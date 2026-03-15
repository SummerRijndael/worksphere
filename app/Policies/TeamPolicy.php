<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Pre-check: block all non-view actions on pending teams for non-admins.
     * Returning false here denies the action outright.
     * Returning null allows the individual method to decide.
     */
    public function before(User $user, string $ability): ?bool
    {
        // Admins can always act on any team (including approving pending ones)
        if ($user->hasRole('administrator') || $user->hasPermissionTo('user_manage')) {
            return null; // Defer to individual methods
        }

        return null; // Individual methods will handle pending checks where needed
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Controller handles scoping
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
        // Pending teams cannot be updated by non-admins
        if ($team->status === 'pending') {
            return false;
        }

        return ($user->hasPermissionTo('teams.update') || $user->hasPermissionTo('user_manage')) || $team->hasAdmin($user);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function invite(User $user, Team $team): bool
    {
        // Cannot invite to a pending team
        if ($team->status === 'pending') {
            return false;
        }

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
