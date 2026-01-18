<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('teams.view') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->hasPermissionTo('teams.view') || $user->hasPermissionTo('user_manage') || $team->hasMember($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('teams.create') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return ($user->hasPermissionTo('teams.update') || $user->hasPermissionTo('user_manage')) || $team->hasAdmin($user);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function invite(User $user, Team $team): bool
    {
        return $user->hasPermissionTo('user_manage') || $team->isOwner($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return ($user->hasPermissionTo('teams.delete') || $user->hasPermissionTo('user_manage')) || $team->isOwner($user);
    }
}
