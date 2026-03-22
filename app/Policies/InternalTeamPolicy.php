<?php

namespace App\Policies;

use App\Enums\InternalTeamRole;
use App\Models\InternalTeam;
use App\Models\User;

class InternalTeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Only administrators or users managing support skills need to view all internal teams
        return $user->hasRole('administrator') || $user->hasPermissionTo('tickets.manage') || $user->hasPermissionTo('support.skills.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InternalTeam $internalTeam): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        // A user can view the internal team if they are a member of it
        return $internalTeam->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('administrator') || $user->hasPermissionTo('support.skills.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InternalTeam $internalTeam): bool
    {
        if ($this->create($user)) {
            return true;
        }

        return $internalTeam->hasRole($user, InternalTeamRole::Manager->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InternalTeam $internalTeam): bool
    {
        return $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can manage members of the model.
     */
    public function manageMembers(User $user, InternalTeam $internalTeam): bool
    {
        if ($user->hasRole('administrator') || $user->hasPermissionTo('support.skills.manage')) {
            return true;
        }

        return $internalTeam->hasRole($user, InternalTeamRole::Manager->value) ||
               $internalTeam->hasRole($user, InternalTeamRole::Lead->value);
    }

    public function manageFiles(User $user, InternalTeam $internalTeam): bool
    {
        return $this->update($user, $internalTeam);
    }

    public function manageCalendar(User $user, InternalTeam $internalTeam): bool
    {
        return $this->update($user, $internalTeam);
    }

    public function viewActivity(User $user, InternalTeam $internalTeam): bool
    {
        return $this->view($user, $internalTeam);
    }
}
