<?php

namespace App\Services\Support\Access;

use App\Contracts\SupportAccessAdapterContract;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class LegacySupportAccessAdapter implements SupportAccessAdapterContract
{
    public function canViewAny(User $user): bool
    {
        return $this->canManageSupportChats($user) || $this->hasPermission($user, 'support.chats.view');
    }

    public function canAccessConversation(User $user, SupportConversation $conversation): bool
    {
        return $this->canViewAny($user) || (int) $conversation->requester_user_id === (int) $user->id;
    }

    public function canReply(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user) || $this->hasPermission($user, 'support.chats.reply');
    }

    public function canAssign(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user) || $this->hasPermission($user, 'support.chats.assign');
    }

    public function canResolve(User $user, SupportConversation $conversation): bool
    {
        return $this->canManageSupportChats($user) || $this->hasPermission($user, 'support.chats.resolve');
    }

    public function canOperateAsAgent(User $user): bool
    {
        if ($user->internalTeams()->exists()) {
            return true;
        }

        if (! empty((array) config('support_chat.agent_roles', [])) && $user->hasAnyRole((array) config('support_chat.agent_roles', []))) {
            return true;
        }

        foreach ((array) config('support_chat.agent_permissions', []) as $permission) {
            if ($this->hasPermission($user, (string) $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    public function applyInboxAccessScope(User $user, Builder $query): Builder
    {
        return $query;
    }

    public function applyEligibleAgentsScope(Builder $query, ?SupportConversation $conversation = null): Builder
    {
        $roles = (array) config('support_chat.agent_roles', ['administrator']);
        $permissions = (array) config('support_chat.agent_permissions', ['tickets.manage']);

        return $query->where(function (Builder $nested) use ($roles, $permissions): void {
            $nested->whereHas('internalTeams');

            if (! empty($roles)) {
                $nested->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles));
            }

            if (! empty($permissions)) {
                $nested->orWhereHas('permissions', fn (Builder $permQuery) => $permQuery->whereIn('name', $permissions));
            }
        });
    }

    public function canBeAssignedToConversation(User $user, SupportConversation $conversation): bool
    {
        return $this->canOperateAsAgent($user);
    }

    protected function canManageSupportChats(User $user): bool
    {
        return $this->hasPermission($user, 'tickets.manage')
            || $user->internalTeams()->exists()
            || $user->hasAnyRole((array) config('support_chat.agent_roles', ['administrator']));
    }

    protected function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
