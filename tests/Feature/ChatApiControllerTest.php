<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        // Create a third user to add to the group
        $thirdUser = User::factory()->create();

        // Create a group owned by the user
        $chat = Chat::create([
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
}
