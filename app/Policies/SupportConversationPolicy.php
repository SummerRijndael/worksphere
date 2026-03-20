<?php

namespace App\Policies;

use App\Models\SupportConversation;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SupportConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageSupportChats($user)
            || $this->hasPermission($user, 'support.chats.view');
    }

    public function view(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user)
            || $this->hasPermission($user, 'support.chats.view')
            || (int) $conversation->requester_user_id === (int) $user->id;
    }

    public function respondAsAgent(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user)
            || $this->hasPermission($user, 'support.chats.reply');
    }

    public function assign(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user)
            || $this->hasPermission($user, 'support.chats.assign');
    }

    public function resolve(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user)
            || $this->hasPermission($user, 'support.chats.resolve');
    }

    protected function canManageSupportChats(User $user): bool
    {
        return $this->hasPermission($user, 'tickets.manage')
            || $user->hasAnyRole((array) config('support_chat.agent_roles', ['administrator', 'it_support']));
    }

    protected function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
