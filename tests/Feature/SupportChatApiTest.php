<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
            'tickets.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_guest_can_open_conversation_and_continue_with_guest_token(): void
    {
        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'I need help with my account.',
            'source_url' => 'https://example.test/pricing',
        ]);

        $startResponse->assertStatus(201)
            ->assertJsonPath('data.guest_name', 'Guest User')
            ->assertJsonPath('data.guest_email', 'guest@example.com')
            ->assertJsonStructure([
                'meta' => [
                    'realtime' => ['token', 'expires_at', 'channels', 'auth_endpoint'],
                ],
            ]);

        $conversationId = (string) $startResponse->json('data.id');
        $guestToken = (string) $startResponse->json('data.guest_token');
        $this->assertNotSame('', $guestToken);

        $showResponse = $this->getJson("/api/support/chats/{$conversationId}?guest_token={$guestToken}");
        $showResponse->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $messageResponse = $this->postJson("/api/support/chats/{$conversationId}/messages", [
            'guest_token' => $guestToken,
            'body' => 'Sharing additional details for troubleshooting.',
        ]);

        $messageResponse->assertStatus(201)
            ->assertJsonPath('data.body', 'Sharing additional details for troubleshooting.');

        $this->assertDatabaseHas('support_messages', [
            'conversation_id' => SupportConversation::where('public_id', $conversationId)->value('id'),
            'sender_type' => SupportMessage::SENDER_CUSTOMER,
            'body' => 'Sharing additional details for troubleshooting.',
        ]);
    }

    public function test_guest_can_resume_conversation_using_secure_cookie_session(): void
    {
        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Need help resuming this conversation.',
        ]);

        $startResponse->assertStatus(201);
        $conversationId = (string) $startResponse->json('data.id');
        $cookieName = (string) config('support_chat.guest_resume_cookie', 'worksphere_support_guest');
        $resumeCookie = $startResponse->getCookie($cookieName, false);

        $this->assertNotNull($resumeCookie);

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $resumeCookie?->getValue())
            ->getJson('/api/support/chats/resume')
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('data.guest_token', $startResponse->json('data.guest_token'));
    }

    public function test_guest_can_send_message_without_guest_token_when_resume_cookie_is_present(): void
    {
        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Initial message.',
        ]);

        $conversationId = (string) $startResponse->json('data.id');
        $cookieName = (string) config('support_chat.guest_resume_cookie', 'worksphere_support_guest');
        $resumeCookie = $startResponse->getCookie($cookieName, false);
        $this->assertNotNull($resumeCookie);

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $resumeCookie?->getValue())
            ->postJson("/api/support/chats/{$conversationId}/messages", [
                'body' => 'Follow-up without guest token.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Follow-up without guest token.');
    }

    public function test_guest_clear_resume_revokes_server_session_and_prevents_future_resume(): void
    {
        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Initial message.',
        ]);

        $conversationId = (string) $startResponse->json('data.id');
        $cookieName = (string) config('support_chat.guest_resume_cookie', 'worksphere_support_guest');
        $resumeCookie = $startResponse->getCookie($cookieName, false);
        $this->assertNotNull($resumeCookie);

        $cookieValue = (string) $resumeCookie?->getValue();

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, $cookieValue)
            ->postJson('/api/support/chats/resume/clear')
            ->assertOk()
            ->assertCookieExpired($cookieName);

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, $cookieValue)
            ->getJson('/api/support/chats/resume')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('support_guest_sessions', [
            'conversation_id' => SupportConversation::where('public_id', $conversationId)->value('id'),
            'revoked_at' => null,
        ]);
    }

    public function test_authenticated_user_can_claim_guest_conversation_and_revoke_guest_resume_session(): void
    {
        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'I started as a guest and then signed up.',
        ]);

        $startResponse->assertStatus(201);

        $conversationId = (string) $startResponse->json('data.id');
        $conversationDbId = SupportConversation::where('public_id', $conversationId)->value('id');
        $cookieName = (string) config('support_chat.guest_resume_cookie', 'worksphere_support_guest');
        $resumeCookie = $startResponse->getCookie($cookieName, false);
        $this->assertNotNull($resumeCookie);

        $user = User::factory()->create();
        $cookieValue = (string) $resumeCookie?->getValue();

        $claimResponse = $this->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie($cookieName, $cookieValue)
            ->postJson('/api/support/chats/claim-guest');

        $claimResponse->assertOk()
            ->assertCookieExpired($cookieName)
            ->assertJsonPath('data.id', $conversationId)
            ->assertJsonPath('data.requester.id', $user->public_id);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversationDbId,
            'requester_user_id' => $user->id,
            'guest_token' => null,
        ]);

        $this->assertDatabaseMissing('support_guest_sessions', [
            'conversation_id' => $conversationDbId,
            'revoked_at' => null,
        ]);

        $this->actingAs($user)
            ->getJson("/api/support/chats/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, $cookieValue)
            ->getJson('/api/support/chats/resume')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_claim_guest_conversation_returns_null_when_no_guest_session_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/support/chats/claim-guest')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_guest_can_fetch_paginated_messages_for_infinite_scroll(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::SENDER_CUSTOMER,
            'body' => 'Message 1',
        ]);
        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::SENDER_AGENT,
            'body' => 'Message 2',
        ]);
        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::SENDER_CUSTOMER,
            'body' => 'Message 3',
        ]);
        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::SENDER_AGENT,
            'body' => 'Message 4',
        ]);

        $latestPage = $this->getJson("/api/support/chats/{$conversation->public_id}/messages?guest_token=valid-token&limit=2");
        $latestPage->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'Message 3')
            ->assertJsonPath('data.1.body', 'Message 4')
            ->assertJsonPath('meta.has_more_before', true);

        $before = (string) $latestPage->json('meta.oldest_id');

        $olderPage = $this->getJson("/api/support/chats/{$conversation->public_id}/messages?guest_token=valid-token&limit=2&before={$before}");
        $olderPage->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'Message 1')
            ->assertJsonPath('data.1.body', 'Message 2')
            ->assertJsonPath('meta.has_more_before', false);
    }

    public function test_guest_can_send_attachment_only_message(): void
    {
        Storage::fake('private');

        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $file = UploadedFile::fake()->image('diagnostics.png', 120, 120);

        $response = $this->withHeader('Accept', 'application/json')
            ->post("/api/support/chats/{$conversation->public_id}/messages", [
                'guest_token' => 'valid-token',
                'body' => '',
                'files' => [$file],
            ]);

        $response->assertStatus(201)
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.name', 'diagnostics.png');

        $this->assertDatabaseHas('media', [
            'model_type' => SupportMessage::class,
            'collection_name' => SupportMessage::MEDIA_COLLECTION,
        ]);
    }

    public function test_support_upload_blocks_dangerous_extension_using_existing_validation(): void
    {
        Storage::fake('private');

        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $file = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');

        $response = $this->withHeader('Accept', 'application/json')
            ->post("/api/support/chats/{$conversation->public_id}/messages", [
                'guest_token' => 'valid-token',
                'body' => '',
                'files' => [$file],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_guest_typing_endpoint_requires_valid_access_token(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->postJson("/api/support/chats/{$conversation->public_id}/typing", [
            'guest_token' => 'valid-token',
            'is_typing' => true,
        ])->assertOk();

        $this->postJson("/api/support/chats/{$conversation->public_id}/typing", [
            'guest_token' => 'wrong-token',
            'is_typing' => true,
        ])->assertStatus(403);
    }

    public function test_guest_cannot_view_conversation_without_valid_guest_token(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->getJson("/api/support/chats/{$conversation->public_id}")
            ->assertStatus(403);

        $this->getJson("/api/support/chats/{$conversation->public_id}?guest_token=wrong-token")
            ->assertStatus(403);
    }

    public function test_complex_guest_case_is_escalated_and_availability_is_reported(): void
    {
        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'We may have a security breach and critical outage on production.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', SupportConversation::STATUS_WAITING_HUMAN)
            ->assertJsonPath('data.ai_handoff_required', true)
            ->assertJsonPath('data.metadata.availability.available', false);
    }

    public function test_non_agent_cannot_access_support_inbox(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/support/chats/inbox')
            ->assertStatus(403);
    }

    public function test_agent_inbox_still_works_when_some_support_permissions_are_not_seeded(): void
    {
        Permission::query()
            ->whereIn('name', [
                'support.chats.view',
                'support.chats.reply',
                'support.chats.assign',
                'support.chats.resolve',
            ])
            ->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $agent = User::factory()->create(['status' => 'active']);
        $agent->givePermissionTo('tickets.manage');

        $this->actingAs($agent)
            ->getJson('/api/support/chats/inbox?scope=mine')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_agent_can_view_inbox_assign_reply_and_resolve_conversation(): void
    {
        $agentRole = Role::findOrCreate('it_support', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = User::factory()->create(['status' => 'active']);
        $agent->assignRole($agentRole);

        $secondaryAgent = User::factory()->create(['status' => 'active']);
        $secondaryAgent->assignRole($agentRole);

        $requester = User::factory()->create();

        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::SENDER_CUSTOMER,
            'sender_user_id' => $requester->id,
            'body' => 'Need a human specialist for this issue.',
        ]);

        $this->actingAs($agent)
            ->getJson('/api/support/chats/inbox?scope=unassigned')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($agent)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->public_id);

        $this->actingAs($agent)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}/messages?limit=10")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($agent)
            ->postJson("/api/support/chats/agent/{$conversation->public_id}/typing", [
                'is_typing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $assignResponse = $this->actingAs($agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $secondaryAgent->public_id,
            ]);

        $assignResponse->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_ASSIGNED)
            ->assertJsonPath('data.assignee.id', $secondaryAgent->public_id);

        $replyResponse = $this->actingAs($secondaryAgent)
            ->postJson("/api/support/chats/{$conversation->public_id}/agent-messages", [
                'body' => 'Thanks, I am taking this over now.',
            ]);

        $replyResponse->assertStatus(201)
            ->assertJsonPath('data.sender_type', SupportMessage::SENDER_AGENT);

        $resolveResponse = $this->actingAs($secondaryAgent)
            ->postJson("/api/support/chats/{$conversation->public_id}/resolve");

        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_RESOLVED);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => $secondaryAgent->id,
            'status' => SupportConversation::STATUS_RESOLVED,
        ]);
    }

    public function test_realtime_token_endpoint_is_agent_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/support/chats/realtime-token')
            ->assertStatus(403);

        $agentRole = Role::findOrCreate('it_support', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
        ]);

        $agent = User::factory()->create(['status' => 'active']);
        $agent->assignRole($agentRole);

        $this->actingAs($agent)
            ->getJson('/api/support/chats/realtime-token')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'expires_at', 'channels', 'auth_endpoint'],
            ]);
    }
}
