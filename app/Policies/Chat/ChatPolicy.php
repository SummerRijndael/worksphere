<?php

namespace App\Policies\Chat;

use App\Models\Chat\Chat;
use App\Models\User;

class ChatPolicy
{
    /**
     * Determine whether the user can view any chats.
     * Allowed for all users since they can only see their own chats via the API query scopes.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the chat.
     */
    public function view(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    /**
     * Determine whether the user can create chats.
     * Allowed for all active authenticated users.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can send messages in the chat.
     */
    public function send(User $user, Chat $chat): bool
    {
        return $this->isParticipant($user, $chat);
    }

    /**
     * Determine whether the user can manage members (add/remove).
     */
    public function manageMembers(User $user, Chat $chat): bool
    {
        // Only group chats can have member management
        if ($chat->type !== 'group') {
            return false;
        }

        return $this->isOwnerOrAdmin($user, $chat);
    }

    /**
     * Determine whether the user can update the chat (rename, avatar).
     */
    public function update(User $user, Chat $chat): bool
    {
        if ($chat->type !== 'group') {
            return false;
        }

        return $this->isOwnerOrAdmin($user, $chat);
    }

    /**
     * Determine whether the user can delete the chat.
     */
    public function delete(User $user, Chat $chat): bool
    {
        return $this->isOwner($user, $chat);
    }

    /**
     * Check if user is a participant of the chat.
     */
    protected function isParticipant(User $user, Chat $chat): bool
    {
        return $chat->participants()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Check if user is the owner or admin of the chat.
     */
    protected function isOwnerOrAdmin(User $user, Chat $chat): bool
    {
        $role = $chat->participants()
            ->where('user_id', $user->id)
            ->value('role');

        return in_array($role, ['owner', 'admin'], true);
    }

    /**
     * Check if user is the owner of the chat.
     */
    protected function isOwner(User $user, Chat $chat): bool
    {
        return $chat->participants()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }
}
