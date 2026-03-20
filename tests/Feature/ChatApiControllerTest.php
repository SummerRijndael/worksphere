<?php

namespace Tests\Feature;

use App\Events\Chat\InviteAccepted;
use App\Events\Chat\InviteDeclined;
use App\Events\Chat\InviteSent;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatInvite;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ChatApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    /**
     * Helper to attach participants with auto-generated public_id.
     */
    protected function attachParticipant(Chat $chat, int $userId, string $role = 'member'): void
    {
        $chat->participants()->attach($userId, [
            'role' => $role,
            'public_id' => (string) Str::ulid(),
        ]);
    }

    protected function createDmChat(): Chat
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        return $chat;
    }

    /**
     * Test user can list their chats.
     */
    public function test_user_can_list_chats(): void
    {
        // Create a chat with the user as participant
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $response = $this->actingAs($this->user)
            ->getJson('/api/chat');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'name', 'participants'],
                ],
            ]);
    }

    /**
     * Test user can send a message.
     */
    public function test_user_can_send_message(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Hello, world!',
            ]);

        $response->assertOk();

        // Check the response has the message
        $response->assertJsonPath('data.content', 'Hello, world!');

        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'content' => 'Hello, world!',
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * Test user cannot access chat they're not part of.
     */
    public function test_user_cannot_access_non_member_chat(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->otherUser->id,
        ]);

        // Only add otherUser, not the test user
        $this->attachParticipant($chat, $this->otherUser->id);

        $response = $this->actingAs($this->user)
            ->getJson("/api/chat/{$chat->public_id}");

        $response->assertStatus(404);
    }

    /**
     * Test user can mark chat as read.
     */
    public function test_user_can_mark_chat_read(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        // Create a message
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $this->otherUser->id,
            'content' => 'Test message',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/read");

        $response->assertOk();
    }

    public function test_upload_persists_duration_seconds_metadata(): void
    {
        Storage::fake('private');

        $chat = $this->createDmChat();
        $file = UploadedFile::fake()->image('sample.png');

        $response = $this->actingAs($this->user)
            ->post("/api/chat/{$chat->public_id}/upload", [
                'files' => [$file],
                'media_metadata' => json_encode([
                    ['duration_seconds' => 17],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.attachments.0.duration_seconds', 17);

        $messagePublicId = (string) $response->json('data.id');
        $message = ChatMessage::where('public_id', $messagePublicId)->firstOrFail();
        $media = Media::where('model_type', ChatMessage::class)
            ->where('model_id', $message->id)
            ->firstOrFail();

        $this->assertSame(17, (int) $media->getCustomProperty('duration_seconds'));
    }

    /**
     * Test user can get their pending invites.
     */
    public function test_user_can_get_invites(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/chat/invites');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_send_invite_broadcasts_invite_sent_event(): void
    {
        Event::fake([InviteSent::class]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/chat/invites', [
                'invitee_public_id' => $this->otherUser->public_id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'invite_sent');

        Event::assertDispatched(InviteSent::class, function (InviteSent $event): bool {
            return data_get($event->invite, 'invitee_public_id') === $this->otherUser->public_id
                && data_get($event->invite, 'inviter_public_id') === $this->user->public_id
                && data_get($event->invite, 'type') === 'dm';
        });
    }

    /**
     * Test user can create a group chat.
     */
    public function test_user_can_create_group(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/chat/groups', [
                'name' => 'Test Group',
                'member_ids' => [],
            ]);

        $response->assertStatus(201);

        // Check chat was created
        $response->assertJsonPath('data.name', 'Test Group');

        $this->assertDatabaseHas('chats', [
            'type' => 'group',
            'name' => 'Test Group',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Test user can search for people.
     */
    public function test_user_can_search_people(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/chat/people/search?q='.urlencode($this->otherUser->name));

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    /**
     * Test typing indicator endpoint.
     */
    public function test_user_can_send_typing_indicator(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/typing");

        $response->assertOk();
    }

    /**
     * Test group owner can add member to group (covers $chatId bug fix).
     */
    public function test_group_owner_can_add_member(): void
    {
        Event::fake([InviteSent::class]);

        // Create a third user to add to the group
        $thirdUser = User::factory()->create();

        // Create a group owned by the user
        $chat = Chat::create([
            'public_id' => (string) Str::ulid(),
            'type' => 'group',
            'name' => 'Test Group',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id, 'owner');

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/members", [
                'user_public_id' => $thirdUser->public_id, // Use public_id for security
            ]);

        // Should create an invite (201) since it's a group invite flow
        $response->assertStatus(201);
        $response->assertJsonStructure(['message', 'invite_id']);

        Event::assertDispatched(InviteSent::class, function (InviteSent $event) use ($thirdUser, $chat): bool {
            return data_get($event->invite, 'invitee_public_id') === $thirdUser->public_id
                && data_get($event->invite, 'inviter_public_id') === $this->user->public_id
                && data_get($event->invite, 'type') === 'group'
                && data_get($event->invite, 'chat_public_id') === $chat->public_id;
        });
    }

    public function test_accept_invite_broadcasts_invite_accepted_event(): void
    {
        Event::fake([InviteAccepted::class]);

        $invite = ChatInvite::create([
            'public_id' => (string) Str::ulid(),
            'inviter_id' => $this->user->id,
            'invitee_id' => $this->otherUser->id,
            'type' => 'dm',
            'status' => ChatInvite::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->otherUser)
            ->postJson("/api/chat/invites/{$invite->public_id}/accept")
            ->assertOk();

        Event::assertDispatched(InviteAccepted::class, function (InviteAccepted $event): bool {
            return data_get($event->invite, 'inviter_public_id') === $this->user->public_id
                && data_get($event->invite, 'invitee_public_id') === $this->otherUser->public_id
                && filled(data_get($event->chat, 'public_id'));
        });
    }

    public function test_decline_invite_broadcasts_invite_declined_event(): void
    {
        Event::fake([InviteDeclined::class]);

        $invite = ChatInvite::create([
            'public_id' => (string) Str::ulid(),
            'inviter_id' => $this->user->id,
            'invitee_id' => $this->otherUser->id,
            'type' => 'dm',
            'status' => ChatInvite::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->otherUser)
            ->postJson("/api/chat/invites/{$invite->public_id}/decline")
            ->assertOk();

        Event::assertDispatched(InviteDeclined::class, function (InviteDeclined $event): bool {
            $channels = collect($event->broadcastOn())
                ->map(fn ($channel) => $channel->name)
                ->all();

            return data_get($event->invite, 'inviter_public_id') === $this->user->public_id
                && data_get($event->invite, 'invitee_public_id') === $this->otherUser->public_id
                && in_array("private-user.{$this->user->public_id}", $channels, true)
                && in_array("private-user.{$this->otherUser->public_id}", $channels, true);
        });
    }

    /**
     * Test search handles special LIKE characters safely.
     */
    public function test_search_handles_special_characters(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/chat/people/search?q='.urlencode('test%user_name'));

        // Should not error even with SQL LIKE special characters
        $response->assertOk();
    }

    /**
     * Test message content is sanitized (HTML entities escaped).
     */
    public function test_message_content_is_sanitized(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => '<script>alert("xss")</script>',
            ]);

        $response->assertOk();

        // Verify that the saved message has escaped HTML
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'user_id' => $this->user->id,
        ]);

        // Check response content is escaped
        $responseData = $response->json('data');
        $this->assertStringNotContainsString('<script>', $responseData['content']);
    }

    public function test_user_can_toggle_message_reaction(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $messageResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'React to me',
            ])
            ->assertOk();

        $messagePublicId = (string) $messageResponse->json('data.id');

        $addResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/reactions", [
                'reaction' => 'like',
            ]);

        $addResponse->assertOk()
            ->assertJsonPath('meta.active', true)
            ->assertJsonPath('data.reactions.like.0', strtolower((string) $this->user->public_id));

        $removeResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/reactions", [
                'reaction' => 'like',
            ]);

        $removeResponse->assertOk()
            ->assertJsonPath('meta.active', false);

        $this->assertNull($removeResponse->json('data.reactions.like'));
    }

    public function test_user_reaction_overwrites_previous_reaction_on_same_message(): void
    {
        $chat = Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $this->attachParticipant($chat, $this->user->id);
        $this->attachParticipant($chat, $this->otherUser->id);

        $messageResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Only one reaction',
            ])
            ->assertOk();

        $messagePublicId = (string) $messageResponse->json('data.id');
        $actor = strtolower((string) $this->user->public_id);

        $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/reactions", [
                'reaction' => 'laugh',
            ])
            ->assertOk();

        $overwriteResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/reactions", [
                'reaction' => 'love',
            ]);

        $overwriteResponse->assertOk()
            ->assertJsonPath('data.reactions.love.0', $actor);

        $this->assertNull($overwriteResponse->json('data.reactions.laugh'));
    }

    public function test_user_can_pin_and_unpin_message(): void
    {
        $chat = $this->createDmChat();

        $messageResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Pin me',
            ])
            ->assertOk();

        $messagePublicId = (string) $messageResponse->json('data.id');

        $pinResponse = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/pin");

        $pinResponse->assertOk()
            ->assertJsonPath('data.is_pinned', true)
            ->assertJsonPath('data.pinned_by_user_public_id', (string) $this->user->public_id)
            ->assertJsonPath('data.pinned_by_user_name', (string) $this->user->name);

        $unpinResponse = $this->actingAs($this->user)
            ->deleteJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/pin");

        $unpinResponse->assertOk()
            ->assertJsonPath('data.is_pinned', false);

        $this->assertNull($unpinResponse->json('data.pinned_at'));
        $this->assertNull($unpinResponse->json('data.pinned_by_user_public_id'));
        $this->assertNull($unpinResponse->json('data.pinned_by_user_name'));
    }

    public function test_user_can_edit_message_and_view_edit_history(): void
    {
        $chat = $this->createDmChat();

        $send = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Original content',
            ])
            ->assertOk();

        $messagePublicId = (string) $send->json('data.id');

        $edit = $this->actingAs($this->user)
            ->patchJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}", [
                'content' => 'Edited content',
            ]);

        $edit->assertOk()
            ->assertJsonPath('data.content', 'Edited content')
            ->assertJsonPath('data.is_edited', true)
            ->assertJsonPath('data.edit_history_count', 1);

        $history = $this->actingAs($this->user)
            ->getJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/history");

        $history->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.is_edited', true)
            ->assertJsonPath('data.0.previous_content', 'Original content');
    }

    public function test_user_cannot_edit_other_users_message(): void
    {
        $chat = $this->createDmChat();

        $send = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Not yours',
            ])
            ->assertOk();

        $messagePublicId = (string) $send->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}", [
                'content' => 'Trying to edit',
            ])
            ->assertStatus(403);
    }

    public function test_user_can_unsend_message_for_me_and_hidden_message_is_excluded(): void
    {
        $chat = $this->createDmChat();

        $visible = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Visible message',
            ])
            ->assertOk();
        $hidden = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Hide this for me',
            ])
            ->assertOk();

        $hiddenPublicId = (string) $hidden->json('data.id');
        $visiblePublicId = (string) $visible->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/chat/{$chat->public_id}/messages/{$hiddenPublicId}", [
                'scope' => 'me',
            ])
            ->assertOk()
            ->assertJsonPath('scope', 'me');

        $messages = $this->actingAs($this->user)
            ->getJson("/api/chat/{$chat->public_id}/messages")
            ->assertOk()
            ->json('data');

        $visibleIds = collect($messages)->pluck('id')->all();
        $this->assertContains($visiblePublicId, $visibleIds);
        $this->assertNotContains($hiddenPublicId, $visibleIds);

        $this->actingAs($this->user)
            ->getJson('/api/chat')
            ->assertOk()
            ->assertJsonPath('data.0.last_message.id', $visiblePublicId);
    }

    public function test_sender_can_delete_message_for_everyone(): void
    {
        $chat = $this->createDmChat();

        $send = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Delete me',
            ])
            ->assertOk();
        $messagePublicId = (string) $send->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}", [
                'scope' => 'all',
            ])
            ->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.content', '');

        $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}/reactions", [
                'reaction' => 'like',
            ])
            ->assertStatus(422);
    }

    public function test_dm_recipient_cannot_delete_other_users_message_for_everyone(): void
    {
        $chat = $this->createDmChat();

        $send = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => 'Sender message',
            ])
            ->assertOk();
        $messagePublicId = (string) $send->json('data.id');

        $this->actingAs($this->otherUser)
            ->deleteJson("/api/chat/{$chat->public_id}/messages/{$messagePublicId}", [
                'scope' => 'all',
            ])
            ->assertStatus(403);
    }
}
