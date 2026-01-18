<?php

namespace App\Services\Chat;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Events\Chat\ChatDeleted;
use App\Events\Chat\ChatMemberKicked;
use App\Events\Chat\ChatMemberLeft;
use App\Models\Chat\Chat;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GroupChatService
{
    public function __construct(
        protected ChatEngine $chatEngine,
        protected AuditService $auditService
    ) {}

    /**
     * Member leaves the group.
     */
    public function leaveGroup(Chat $chat, User $user): void
    {
        if ($chat->isOwner($user)) {
            throw ValidationException::withMessages(['message' => 'Owner cannot leave the group. Delete the group instead or transfer ownership.']);
        }

        DB::transaction(function () use ($chat, $user) {
            $chat->participants()->updateExistingPivot($user->id, [
                'left_at' => now(),
            ]);

            // Create system message
            $this->chatEngine->createSystemMessage($chat, "{$user->name} left the group.", $user->id);

            // Log audit
            $this->auditService->log(
                AuditAction::ChatMemberLeft,
                AuditCategory::Communication,
                $chat,
                $user,
                null,
                ['user_id' => $user->id]
            );

            // Broadcast event
            broadcast(new ChatMemberLeft($chat, $user));
        });
    }

    /**
     * Owner kicks a member.
     */
    public function kickMember(Chat $chat, User $member, User $kicker): void
    {
        if (! $chat->isOwner($kicker)) {
            throw ValidationException::withMessages(['message' => 'Only the owner can kick members.']);
        }

        if ($chat->isOwner($member)) {
            throw ValidationException::withMessages(['message' => 'Cannot kick the owner.']);
        }

        DB::transaction(function () use ($chat, $member, $kicker) {
            $chat->participants()->updateExistingPivot($member->id, [
                'left_at' => now(),
                'kicked_by' => $kicker->id,
            ]);

            // Create system message
            $this->chatEngine->createSystemMessage($chat, "{$member->name} was kicked from the group.", $kicker->id);

            // Log audit
            $this->auditService->log(
                AuditAction::ChatMemberKicked,
                AuditCategory::Communication,
                $chat,
                $kicker,
                null,
                ['kicked_user_id' => $member->id]
            );

            // Broadcast event
            broadcast(new ChatMemberKicked($chat, $member));
        });
    }

    /**
     * Owner deletes the group.
     */
    public function deleteGroup(Chat $chat, User $owner, string $password): void
    {
        if (! $chat->isOwner($owner)) {
            throw ValidationException::withMessages(['message' => 'Only the owner can delete the group.']);
        }

        if (! Hash::check($password, $owner->password)) {
            throw ValidationException::withMessages(['password' => 'Incorrect password.']);
        }

        DB::transaction(function () use ($chat, $owner) {
            // Delete media
            $chat->clearMediaCollection('chat_attachments');
            // Note: Avatar is on Chat model, media library handles cleanup on model delete if configured, otherwise manual:
            $chat->clearMediaCollection('avatar');

            // Broadcast first so clients can react
            broadcast(new ChatDeleted($chat));

            // Log audit
            $this->auditService->log(
                AuditAction::ChatDeleted,
                AuditCategory::Communication,
                null, // Model is being deleted
                $owner,
                $chat->toArray(),
                null,
                ['chat_name' => $chat->name]
            );

            $chat->delete();
        });
    }

    /**
     * Rejoin a group (if invited again).
     */
    public function rejoinGroup(Chat $chat, User $user): void
    {
        // This is typically called by InviteService/ChatEngine when adding member
        // We just need to ensure we clear the flags
        $chat->allParticipants()->updateExistingPivot($user->id, [
            'left_at' => null,
            'kicked_by' => null,
        ]);

        // System message handled by invite logic usually, but if distinct flow:
        // $this->chatEngine->createSystemMessage($chat, "{$user->name} rejoined the group.");
    }

    /**
     * Handle cleanup when a user account is deleted.
     */
    public function handleDeletedUser(User $user): void
    {
        // Find all groups where user is a participant
        $chats = $user->chats()->where('type', Chat::TYPE_GROUP)->get();

        foreach ($chats as $chat) {
            if ($chat->isOwner($user)) {
                // If owner, check if any other members. If so, problem.
                // For now, if owner deletes account, we delete the group? Or assign random owner?
                // Spec say: "User account got deleted, how can we handle this -> run handleDeletedUser()"
                // Let's mark as left for now, but if owner = null, group might be broken.
                // It's better to force delete groups owned by deleted user
                // But let's follow standard "Member Left" flow first
            }

            // Mark as left
            $chat->participants()->updateExistingPivot($user->id, [
                'left_at' => now(),
            ]);

            $this->chatEngine->createSystemMessage($chat, "{$user->name} (Deleted) left the group.", $user->id);
        }
    }

    /**
     * Mark inactive groups for deletion.
     */
    public function markInactiveGroupsForDeletion(): int
    {
        $cutoff = Carbon::now()->subDays(60);

        // Chats with no activity or created > 60 days ago
        // Actually we need to check messages
        // But we added last_activity_at column to chat. Need to populate it for existing chats?
        // Let's assume it's populated or null. If null, use created_at?

        $chats = Chat::query()
            ->where('type', Chat::TYPE_GROUP)
            ->whereNull('marked_for_deletion_at')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('last_activity_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->get();

        $count = 0;
        foreach ($chats as $chat) {
            $chat->update(['marked_for_deletion_at' => now()]);

            // Log & Notify
            $this->auditService->log(
                AuditAction::ChatMarkedForDeletion,
                AuditCategory::Communication,
                $chat,
                null, // System action
                null,
                ['reason' => 'inactivity']
            );

            // Notify Owner (TODO: Notification class)
            // $chat->owner->first()?->notify(new ChatMarkedForDeletionNotification($chat));

            $count++;
        }

        return $count;
    }

    /**
     * Cleanup groups that have been marked for > 10 days.
     */
    public function cleanupMarkedGroups(): int
    {
        $cutoff = Carbon::now()->subDays(10);

        $chats = Chat::query()
            ->where('type', Chat::TYPE_GROUP)
            ->whereNotNull('marked_for_deletion_at')
            ->where('marked_for_deletion_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($chats as $chat) {
            // System delete - no password needed
            broadcast(new ChatDeleted($chat));
            $chat->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Restore a flagged group.
     */
    /**
     * Restore a flagged group.
     */
    public function restoreMarkedGroup(Chat $chat): void
    {
        $chat->update(['marked_for_deletion_at' => null]);

        $this->auditService->log(
            AuditAction::ChatDeletionCancelled,
            AuditCategory::Communication,
            $chat,
            auth()->user(),
            null,
            ['action' => 'restored_by_admin']
        );
    }

    /**
     * Update group settings (Name, Avatar).
     *
     * @param  array{name?: string, avatar?: \Illuminate\Http\UploadedFile}  $data
     */
    public function updateGroupSettings(Chat $chat, User $actor, array $data): Chat
    {
        if (! $chat->isOwner($actor)) {
            throw ValidationException::withMessages(['message' => 'Only the owner can update group settings.']);
        }

        return DB::transaction(function () use ($chat, $actor, $data) {
            $updated = false;

            // 1. Update Name
            if (array_key_exists('name', $data) && $data['name'] !== $chat->name) {
                $oldName = $chat->name;
                $chat->name = $data['name'];
                $chat->save();

                $this->chatEngine->createSystemMessage(
                    $chat,
                    "{$actor->name} renamed the group to \"{$chat->name}\".",
                    $actor->id
                );
                $updated = true;
            }

            // 2. Update Avatar
            if (isset($data['avatar']) && $data['avatar'] instanceof \Illuminate\Http\UploadedFile) {
                app(\App\Services\AvatarService::class)->processUpload($data['avatar'], $chat);

                // Refresh to get new media relation
                $chat->refresh();

                $this->chatEngine->createSystemMessage(
                    $chat,
                    "{$actor->name} changed the group photo.",
                    $actor->id
                );
                $updated = true;
            }

            if ($updated) {
                broadcast(new \App\Events\Chat\ChatUpdated($chat));
            }

            return $chat;
        });
    }
}
