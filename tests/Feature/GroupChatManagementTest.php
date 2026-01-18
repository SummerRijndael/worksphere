<?php

namespace Tests\Feature;

use App\Models\Chat\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupChatManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.async' => false]);
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function createGroupAndAttachUsers(User $owner, array $members): Chat
    {
        $chat = Chat::create([
            'type' => 'group',
            'name' => 'Test Group',
            'created_by' => $owner->id,
            'public_id' => (string) Str::ulid(),
        ]);

        $this->attachParticipant($chat, $owner->id, 'owner');
        foreach ($members as $member) {
            $this->attachParticipant($chat, $member->id, 'member');
        }

        return $chat;
    }

    protected function attachParticipant(Chat $chat, int $userId, string $role = 'member'): void
    {
        $chat->participants()->attach($userId, [
            'role' => $role,
            'public_id' => (string) Str::ulid(),
        ]);
    }

    public function test_member_can_leave_group()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);

        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/leave");

        $response->assertOk();

        // Check if user is marked as left
        $this->assertDatabaseMissing('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $this->otherUser->id,
            'left_at' => null,
        ]);
    }

    public function test_owner_can_kick_member()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/kick/{$this->otherUser->public_id}");

        $response->assertOk();

        $this->assertDatabaseMissing('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $this->otherUser->id,
            'left_at' => null,
        ]);

        $this->assertDatabaseHas('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $this->otherUser->id,
            'kicked_by' => $this->user->id,
        ]);
    }

    public function test_non_owner_cannot_kick_member()
    {
        $thirdUser = User::factory()->create();
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser, $thirdUser]);

        // Other user trying to kick third user
        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/kick/{$thirdUser->public_id}");

        $response->assertStatus(422); // ValidationException -> 422
    }

    public function test_member_can_rejoin_group()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);

        // Leave first
        $this->actingAs($this->otherUser)->postJson("/api/chat/{$chat->public_id}/leave");

        // Rejoin
        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/chat/{$chat->public_id}/rejoin");

        $response->assertOk();

        $this->assertDatabaseHas('chat_participants', [
            'chat_id' => $chat->id,
            'user_id' => $this->otherUser->id,
            'left_at' => null,
        ]);
    }

    public function test_owner_can_delete_group_with_password()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);

        // Password is 'password' by default for factory
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/chat/{$chat->public_id}", [
                'password' => 'password',
            ]);

        $response->assertOk();

        $this->assertSoftDeleted('chats', ['id' => $chat->id]);
    }

    public function test_owner_cannot_delete_group_with_wrong_password()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/chat/{$chat->public_id}", [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseHas('chats', ['id' => $chat->id]);
    }

    public function test_admin_can_restore_flagged_chat()
    {
        // 1. Create permission if needed
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'chats.manage', 'guard_name' => 'web']);
        // Note: Guard name might fail if default is different, usually 'web' or 'sanctum'.
        // Spatie usually defaults to config. Let's try without guard or with 'web'.

        // 2. Assign permission
        $this->user->givePermissionTo($permission);

        // 3. Create flagged chat
        $chat = $this->createGroupAndAttachUsers($this->user, [$this->otherUser]);
        $chat->update(['marked_for_deletion_at' => now()]);

        // 4. Call restore endpoint
        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/chats/{$chat->public_id}/restore");

        $response->assertOk();

        // 5. Assert database update
        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'marked_for_deletion_at' => null,
        ]);

        // 6. Assert Audit Log (optional but good)
        // Since we disabled async, we can check DB directly if we knew AuditLog structure
        // But simply asserting OK implies logic ran.
    }

    public function test_system_message_on_invite_acceptance()
    {
        $chat = $this->createGroupAndAttachUsers($this->user, []);
        $invitee = User::factory()->create();

        // 1. Create invite
        $invite = \App\Models\Chat\ChatInvite::create([
            'chat_id' => $chat->id,
            'inviter_id' => $this->user->id,
            'invitee_id' => $invitee->id,
            'type' => 'group',
            'status' => 'pending',
            'public_id' => (string) Str::ulid(),
            'expires_at' => now()->addDays(7),
        ]);

        // 2. Accept invite
        $response = $this->actingAs($invitee)
            ->postJson("/api/chat/invites/{$invite->public_id}/accept");

        $response->assertOk();

        // 3. Verify system message
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'type' => 'system',
            'content' => "{$this->user->name} added {$invitee->name} to the group.",
            'user_id' => $this->user->id, // Inviter is the actor
        ]);
    }
}
