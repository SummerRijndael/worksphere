<?php

namespace Tests\Feature;

use App\Contracts\SupportSurveyServiceContract;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportSkill;
use App\Models\SupportSkillMembership;
use App\Models\SupportSurveyInvite;
use App\Models\SupportSurveyResponse;
use App\Models\User;
use App\Jobs\BroadcastSupportConversationChanged;
use App\Jobs\BroadcastSupportMessageCreated;
use App\Services\Support\Pipelines\SupportHandoffPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected SupportSkill $skill;

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

        $this->skill = SupportSkill::create([
            'name' => 'Core Support',
            'slug' => 'core-support',
            'is_active' => true,
        ]);
    }

    protected function createSupportAgent(Role $role): User
    {
        $agent = User::factory()->create(['status' => 'active']);
        $agent->assignRole($role);

        SupportSkillMembership::create([
            'support_skill_id' => $this->skill->id,
            'user_id' => $agent->id,
            'membership_role' => 'agent',
            'is_primary' => true,
            'is_active' => true,
            'capacity' => 3,
        ]);

        return $agent;
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
            ->assertJsonPath('data.chat_state', SupportConversation::CHAT_STATE_NEW)
            ->assertJsonPath('data.assignment_state', SupportConversation::ASSIGNMENT_STATE_UNASSIGNED)
            ->assertJsonPath('data.resolution_marker', SupportConversation::RESOLUTION_MARKER_UNRESOLVED)
            ->assertJsonPath('data.conversation_type', SupportConversation::TYPE_INQUIRY)
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

    public function test_guest_honeypot_field_blocks_public_support_chat_open(): void
    {
        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Need help.',
            'website_url' => 'https://spam.example.test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['website_url']);
    }

    public function test_guest_requires_recaptcha_token_when_recaptcha_is_enabled(): void
    {
        config()->set('recaptcha.enabled', true);

        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Need help.',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recaptcha_token']);
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

    public function test_support_chat_broadcasts_can_be_queued_via_jobs(): void
    {
        Queue::fake([
            BroadcastSupportMessageCreated::class,
            BroadcastSupportConversationChanged::class,
        ]);

        config()->set('support_chat.jobs.enabled', true);
        config()->set('support_chat.jobs.broadcast_mode', 'queue_first');

        $startResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Guest User',
            'guest_email' => 'guest@example.com',
            'initial_message' => 'Queue broadcast start message.',
        ]);

        $conversationId = (string) $startResponse->json('data.id');
        $guestToken = (string) $startResponse->json('data.guest_token');

        $this->postJson("/api/support/chats/{$conversationId}/messages", [
            'guest_token' => $guestToken,
            'body' => 'Queue broadcast follow up.',
        ])->assertStatus(201);

        Queue::assertPushed(BroadcastSupportMessageCreated::class);
        Queue::assertPushed(BroadcastSupportConversationChanged::class);
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

    public function test_authenticated_user_can_load_own_support_chat_history(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownConversation = SupportConversation::create([
            'requester_user_id' => $user->id,
            'guest_name' => $user->name,
            'guest_email' => $user->email,
            'status' => SupportConversation::STATUS_CLOSED,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $latestMessage = SupportMessage::create([
            'conversation_id' => $ownConversation->id,
            'sender_type' => SupportMessage::SENDER_AGENT,
            'body' => 'Your case has been resolved.',
        ]);

        $ownConversation->forceFill([
            'last_message_at' => $latestMessage->created_at,
        ])->save();

        $otherConversation = SupportConversation::create([
            'requester_user_id' => $otherUser->id,
            'guest_name' => $otherUser->name,
            'guest_email' => $otherUser->email,
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        SupportMessage::create([
            'conversation_id' => $otherConversation->id,
            'sender_type' => SupportMessage::SENDER_CUSTOMER,
            'body' => 'This should stay private.',
        ]);

        $this->actingAs($user)
            ->getJson('/api/support/chats/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownConversation->public_id)
            ->assertJsonPath('data.0.latest_message.body', 'Your case has been resolved.');
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
            ->assertJsonPath('data.metadata.availability.available', false)
            ->assertJsonPath('data.queue.position', 1);

        $conversationId = (string) $response->json('data.id');
        $guestToken = (string) $response->json('data.guest_token');

        $show = $this->getJson("/api/support/chats/{$conversationId}?guest_token={$guestToken}")
            ->assertOk()
            ->assertJsonPath('data.queue.position', 1)
            ->assertJsonPath('data.status', SupportConversation::STATUS_WAITING_HUMAN);

        $this->assertNotEmpty((string) $show->json('data.timers.waiting_since_at'));

        $messages = collect($show->json('data.messages', []));
        $this->assertTrue(
            $messages->contains(function (array $message): bool {
                return ($message['sender_type'] ?? null) === SupportMessage::SENDER_SYSTEM
                    && str_contains((string) ($message['body'] ?? ''), 'number 1 in queue');
            }),
            'Expected waiting notice to include queue position.'
        );
    }

    public function test_assigned_conversation_no_longer_gets_ai_auto_replies(): void
    {
        $agent = User::factory()->create();

        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $agent->id,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->postJson("/api/support/chats/{$conversation->public_id}/messages", [
            'guest_token' => 'valid-token',
            'body' => 'Are you there?',
        ])->assertStatus(201);

        $botMessageCount = SupportMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SupportMessage::SENDER_BOT)
            ->count();

        $this->assertSame(0, $botMessageCount);
    }

    public function test_ai_reply_is_suppressed_if_conversation_gets_assigned_during_handoff(): void
    {
        $agent = User::factory()->create();

        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $pipeline = \Mockery::mock(SupportHandoffPipeline::class, [app(\App\Contracts\SupportAiAdapterContract::class)]);
        $pipeline->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function () use ($conversation, $agent): array {
                $conversation->forceFill([
                    'assigned_to' => $agent->id,
                    'status' => SupportConversation::STATUS_ASSIGNED,
                ])->save();

                return [
                    'reply' => 'This should not be sent once assigned.',
                    'escalate' => false,
                    'reason' => null,
                    'confidence' => 0.9,
                ];
            });
        $this->app->instance(SupportHandoffPipeline::class, $pipeline);

        $this->postJson("/api/support/chats/{$conversation->public_id}/messages", [
            'guest_token' => 'valid-token',
            'body' => 'Can you help me?',
        ])->assertStatus(201);

        $botMessageCount = SupportMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SupportMessage::SENDER_BOT)
            ->count();

        $this->assertSame(0, $botMessageCount);
    }

    public function test_guest_can_submit_survey_via_conversation_scoped_endpoint(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_RESOLVED,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        /** @var SupportSurveyServiceContract $surveyService */
        $surveyService = app(SupportSurveyServiceContract::class);
        $invite = $surveyService->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_CSAT);
        $this->assertNotNull($invite);

        $this->getJson("/api/support/chats/{$conversation->public_id}/survey?guest_token=valid-token")
            ->assertOk()
            ->assertJsonPath('data.state', SupportSurveyInvite::STATUS_PENDING)
            ->assertJsonPath('data.invite.survey_type', SupportSurveyInvite::TYPE_CSAT);

        $this->postJson("/api/support/chats/{$conversation->public_id}/survey", [
            'guest_token' => 'valid-token',
            'score' => 5,
            'comment' => 'Great support.',
        ])->assertStatus(201)
            ->assertJsonPath('data.survey_type', SupportSurveyInvite::TYPE_CSAT)
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.label', 'satisfied');

        $this->getJson("/api/support/chats/{$conversation->public_id}/survey?guest_token=valid-token")
            ->assertOk()
            ->assertJsonPath('data.state', SupportSurveyInvite::STATUS_RESPONDED)
            ->assertJsonPath('data.response.score', 5);
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

    public function test_agent_inbox_scope_mine_exposes_workbench_capacity_meta_capped_to_five(): void
    {
        config()->set('support_chat.routing.default_agent_capacity', 9);
        config()->set('support_chat.workbench.max_panels', 5);

        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);
        SupportSkillMembership::query()
            ->where('user_id', $agent->id)
            ->where('support_skill_id', $this->skill->id)
            ->update(['capacity' => 9, 'is_primary' => true]);

        for ($i = 1; $i <= 2; $i++) {
            SupportConversation::create([
                'guest_name' => "Active Guest {$i}",
                'guest_email' => "active-{$i}@example.test",
                'status' => SupportConversation::STATUS_ASSIGNED,
                'priority' => 'normal',
                'channel' => 'widget',
                'ai_enabled' => true,
                'support_skill_id' => $this->skill->id,
                'assigned_to' => $agent->id,
            ]);
        }

        $this->actingAs($agent)
            ->getJson('/api/support/chats/inbox?scope=mine')
            ->assertOk()
            ->assertJsonPath('meta.workbench.max_panels', 5)
            ->assertJsonPath('meta.workbench.hard_cap', 5)
            ->assertJsonPath('meta.workbench.agent_capacity', 5)
            ->assertJsonPath('meta.workbench.active_chats', 2)
            ->assertJsonPath('meta.workbench.available_slots', 3)
            ->assertJsonPath('meta.workbench.effective_panel_limit', 5);
    }

    public function test_manual_assignment_rejects_when_target_agent_already_has_five_active_chats(): void
    {
        config()->set('support_chat.routing.default_agent_capacity', 9);
        config()->set('support_chat.workbench.max_panels', 5);

        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $manager = $this->createSupportAgent($agentRole);
        $assignee = $this->createSupportAgent($agentRole);

        SupportSkillMembership::query()
            ->where('user_id', $assignee->id)
            ->where('support_skill_id', $this->skill->id)
            ->update(['capacity' => 9, 'is_primary' => true]);

        for ($i = 1; $i <= 5; $i++) {
            SupportConversation::create([
                'guest_name' => "Busy Guest {$i}",
                'guest_email' => "busy-{$i}@example.test",
                'status' => SupportConversation::STATUS_ASSIGNED,
                'priority' => 'normal',
                'channel' => 'widget',
                'ai_enabled' => true,
                'support_skill_id' => $this->skill->id,
                'assigned_to' => $assignee->id,
            ]);
        }

        $conversation = SupportConversation::create([
            'guest_name' => 'Queue Guest',
            'guest_email' => 'queue@example.test',
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
            'support_skill_id' => $this->skill->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $assignee->public_id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', "{$assignee->name} is already at the maximum of 5 active chats.");
    }

    public function test_agent_can_view_inbox_assign_reply_and_start_close_out(): void
    {
        Queue::fake([\App\Jobs\Support\SupportAssignmentTimeoutJob::class]);

        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);

        $secondaryAgent = $this->createSupportAgent($agentRole);

        $requester = User::factory()->create();

        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
            'support_skill_id' => $this->skill->id,
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
            ->assertJsonPath('data.status', SupportConversation::STATUS_PENDING_ACCEPTANCE)
            ->assertJsonPath('data.assignment_state', SupportConversation::ASSIGNMENT_STATE_PENDING)
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
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP)
            ->assertJsonPath('data.resolution_marker', SupportConversation::RESOLUTION_MARKER_RESOLVED);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => $secondaryAgent->id,
            'status' => SupportConversation::STATUS_WRAP_UP,
            'resolution_marker' => SupportConversation::RESOLUTION_MARKER_RESOLVED,
        ]);
    }

    public function test_realtime_token_endpoint_is_agent_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/support/chats/realtime-token')
            ->assertStatus(403);

        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
        ]);

        $agent = $this->createSupportAgent($agentRole);

        $this->actingAs($agent)
            ->getJson('/api/support/chats/realtime-token')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'expires_at', 'channels', 'auth_endpoint'],
            ]);
    }

    public function test_closing_resolved_conversation_issues_bundled_csat_and_nps_invites(): void
    {
        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);

        $requester = User::factory()->create();
        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $agent->id,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->actingAs($agent)
            ->postJson("/api/support/chats/agent/{$conversation->public_id}/end")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP);

        $response = $this->actingAs($agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/wrap-up/complete", [
                'resolved' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_CLOSED)
            ->assertJsonPath('data.resolution_marker', SupportConversation::RESOLUTION_MARKER_RESOLVED)
            ->assertJsonPath('meta.survey_invite_bundle.csat.survey_type', SupportSurveyInvite::TYPE_CSAT)
            ->assertJsonPath('meta.survey_invite_bundle.csat.status', SupportSurveyInvite::STATUS_PENDING)
            ->assertJsonPath('meta.survey_invite_bundle.nps.survey_type', SupportSurveyInvite::TYPE_NPS)
            ->assertJsonPath('meta.survey_invite_bundle.nps.status', SupportSurveyInvite::STATUS_PENDING);

        $this->assertDatabaseHas('support_survey_invites', [
            'conversation_id' => $conversation->id,
            'survey_type' => SupportSurveyInvite::TYPE_CSAT,
            'status' => SupportSurveyInvite::STATUS_PENDING,
            'requester_user_id' => $requester->id,
        ]);
        $this->assertDatabaseHas('support_survey_invites', [
            'conversation_id' => $conversation->id,
            'survey_type' => SupportSurveyInvite::TYPE_NPS,
            'status' => SupportSurveyInvite::STATUS_PENDING,
            'requester_user_id' => $requester->id,
        ]);
    }

    public function test_guest_can_submit_bundled_csat_and_nps_for_conversation(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_CLOSED,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        /** @var SupportSurveyServiceContract $surveyService */
        $surveyService = app(SupportSurveyServiceContract::class);
        $bundle = $surveyService->issuePostResolutionSurveyBundle($conversation);
        $this->assertNotNull($bundle[SupportSurveyInvite::TYPE_CSAT] ?? null);
        $this->assertNotNull($bundle[SupportSurveyInvite::TYPE_NPS] ?? null);

        $submitResponse = $this->postJson("/api/support/chats/{$conversation->public_id}/survey", [
            'guest_token' => 'valid-token',
            'csat_score' => 5,
            'nps_score' => 9,
            'comment' => 'Helpful support team.',
        ]);

        $submitResponse->assertStatus(201)
            ->assertJsonCount(2, 'data.responses');

        $this->assertDatabaseHas('support_survey_responses', [
            'conversation_id' => $conversation->id,
            'survey_type' => SupportSurveyInvite::TYPE_CSAT,
            'score' => 5,
        ]);
        $this->assertDatabaseHas('support_survey_responses', [
            'conversation_id' => $conversation->id,
            'survey_type' => SupportSurveyInvite::TYPE_NPS,
            'score' => 9,
        ]);
    }

    public function test_guest_survey_opt_out_blocks_future_bundle_invites(): void
    {
        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);

        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $agent->id,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->postJson("/api/support/chats/{$conversation->public_id}/survey-preference", [
            'guest_token' => 'valid-token',
            'opt_out' => true,
        ])->assertOk()
            ->assertJsonPath('data.conversation.survey_opt_out', true);

        $this->actingAs($agent)
            ->postJson("/api/support/chats/agent/{$conversation->public_id}/end")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP);

        $response = $this->actingAs($agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/wrap-up/complete", [
                'resolved' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('meta.survey_invite_bundle.csat', null)
            ->assertJsonPath('meta.survey_invite_bundle.nps', null);

        $this->assertDatabaseMissing('support_survey_invites', [
            'conversation_id' => $conversation->id,
            'status' => SupportSurveyInvite::STATUS_PENDING,
        ]);
    }

    public function test_guest_can_end_conversation_and_audit_fields_are_persisted(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest Person',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->postJson("/api/support/chats/{$conversation->public_id}/end", [
            'guest_token' => 'valid-token',
            'ended_by_name' => 'Guest Person',
        ])->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_CLOSED)
            ->assertJsonPath('data.chat_state', SupportConversation::CHAT_STATE_ENDED)
            ->assertJsonPath('data.end_reason', SupportConversation::END_REASON_USER_ENDED)
            ->assertJsonPath('data.ended_by.type', 'guest')
            ->assertJsonPath('data.ended_by.name', 'Guest Person');

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'status' => SupportConversation::STATUS_CLOSED,
            'chat_state' => SupportConversation::CHAT_STATE_ENDED,
            'end_reason' => SupportConversation::END_REASON_USER_ENDED,
            'ended_by_type' => 'guest',
            'ended_by_name' => 'Guest Person',
        ]);
    }

    public function test_agent_can_end_conversation_via_agent_endpoint(): void
    {
        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);
        $requester = User::factory()->create();

        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $agent->id,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->actingAs($agent)
            ->postJson("/api/support/chats/agent/{$conversation->public_id}/end")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP)
            ->assertJsonPath('data.chat_state', SupportConversation::CHAT_STATE_ENDED)
            ->assertJsonPath('data.end_reason', SupportConversation::END_REASON_AGENT_ENDED)
            ->assertJsonPath('data.ended_by.type', 'agent')
            ->assertJsonPath('data.ended_by.user.id', $agent->public_id);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'status' => SupportConversation::STATUS_WRAP_UP,
            'chat_state' => SupportConversation::CHAT_STATE_ENDED,
            'end_reason' => SupportConversation::END_REASON_AGENT_ENDED,
            'ended_by_type' => 'agent',
            'ended_by_user_id' => $agent->id,
        ]);
    }

    public function test_guest_can_fetch_and_submit_survey_using_secure_token(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_RESOLVED,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        /** @var SupportSurveyServiceContract $surveyService */
        $surveyService = app(SupportSurveyServiceContract::class);
        $invite = $surveyService->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_CSAT);
        $this->assertNotNull($invite);
        $token = (string) $invite?->getAttribute('plain_token');
        $this->assertNotSame('', $token);

        $this->getJson("/api/support/chats/surveys/{$token}")
            ->assertOk()
            ->assertJsonPath('data.survey_type', SupportSurveyInvite::TYPE_CSAT)
            ->assertJsonPath('data.status', SupportSurveyInvite::STATUS_PENDING)
            ->assertJsonPath('data.definition.scale_min', 1)
            ->assertJsonPath('data.definition.scale_max', 5);

        $submitResponse = $this->postJson("/api/support/chats/surveys/{$token}", [
            'score' => 5,
            'comment' => 'Great support response and fast resolution.',
        ]);

        $submitResponse->assertStatus(201)
            ->assertJsonPath('data.survey_type', SupportSurveyInvite::TYPE_CSAT)
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.label', 'satisfied');

        $this->assertDatabaseHas('support_survey_responses', [
            'invite_id' => $invite?->id,
            'survey_type' => SupportSurveyInvite::TYPE_CSAT,
            'score' => 5,
            'label' => 'satisfied',
        ]);

        $this->assertDatabaseHas('support_survey_invites', [
            'id' => $invite?->id,
            'status' => SupportSurveyInvite::STATUS_RESPONDED,
        ]);
    }

    public function test_survey_token_cannot_be_submitted_twice(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'guest_token' => 'valid-token',
            'status' => SupportConversation::STATUS_RESOLVED,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        /** @var SupportSurveyServiceContract $surveyService */
        $surveyService = app(SupportSurveyServiceContract::class);
        $invite = $surveyService->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_CSAT);
        $token = (string) $invite?->getAttribute('plain_token');

        $this->postJson("/api/support/chats/surveys/{$token}", ['score' => 4])
            ->assertStatus(201);

        $this->postJson("/api/support/chats/surveys/{$token}", ['score' => 5])
            ->assertStatus(409);

        $this->assertDatabaseCount('support_survey_responses', 1);
    }

    public function test_agent_can_view_survey_metrics_and_non_agent_is_forbidden(): void
    {
        $agentRole = Role::findOrCreate('administrator', 'web');
        $agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
        ]);

        $agent = $this->createSupportAgent($agentRole);
        $requester = User::factory()->create();

        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_RESOLVED,
            'assigned_to' => $agent->id,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        SupportSurveyResponse::create([
            'invite_id' => SupportSurveyInvite::create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => $requester->id,
                'issued_by_user_id' => $agent->id,
                'survey_type' => SupportSurveyInvite::TYPE_CSAT,
                'status' => SupportSurveyInvite::STATUS_RESPONDED,
                'token_hash' => hash('sha256', 'csat-token'),
                'issued_at' => now()->subHour(),
                'responded_at' => now()->subMinutes(30),
            ])->id,
            'conversation_id' => $conversation->id,
            'requester_user_id' => $requester->id,
            'rated_agent_user_id' => $agent->id,
            'survey_type' => SupportSurveyInvite::TYPE_CSAT,
            'score' => 5,
            'label' => 'satisfied',
            'comment' => 'Great.',
            'channel' => 'widget',
        ]);

        SupportSurveyResponse::create([
            'invite_id' => SupportSurveyInvite::create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => $requester->id,
                'issued_by_user_id' => $agent->id,
                'survey_type' => SupportSurveyInvite::TYPE_NPS,
                'status' => SupportSurveyInvite::STATUS_RESPONDED,
                'token_hash' => hash('sha256', 'nps-token'),
                'issued_at' => now()->subHour(),
                'responded_at' => now()->subMinutes(20),
            ])->id,
            'conversation_id' => $conversation->id,
            'requester_user_id' => $requester->id,
            'rated_agent_user_id' => $agent->id,
            'survey_type' => SupportSurveyInvite::TYPE_NPS,
            'score' => 9,
            'label' => 'promoter',
            'comment' => 'Would recommend.',
            'channel' => 'widget',
        ]);

        $this->actingAs($agent)
            ->getJson('/api/support/chats/surveys/metrics')
            ->assertOk()
            ->assertJsonPath('data.totals.responses', 2)
            ->assertJsonPath('data.totals.csat.responses', 1)
            ->assertJsonPath('data.totals.nps.responses', 1);

        $nonAgent = User::factory()->create();

        $this->actingAs($nonAgent)
            ->getJson('/api/support/chats/surveys/metrics')
            ->assertStatus(403);
    }
}
