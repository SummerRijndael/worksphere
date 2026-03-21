<?php

namespace App\Policies;

use App\Models\SupportSkill;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SupportSkillPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'support.chats.view')
            || $this->canManageRouting($user);
    }

    public function view(User $user, SupportSkill $skill): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageRouting($user);
    }

    public function update(User $user, SupportSkill $skill): bool
    {
        return $this->canManageRouting($user);
    }

    public function delete(User $user, SupportSkill $skill): bool
    {
        return $this->canManageRouting($user);
    }

    public function assignMembers(User $user, SupportSkill $skill): bool
    {
        return $this->canManageRouting($user);
    }

    protected function canManageRouting(User $user): bool
    {
        return $this->hasPermission($user, 'support.chats.assign')
            || $this->hasPermission($user, 'tickets.manage')
            || $user->hasRole(config('roles.super_admin_role', 'administrator'));
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
