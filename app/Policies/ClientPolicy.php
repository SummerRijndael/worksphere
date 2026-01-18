<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrator') || $this->isTeamMember($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Client $client): bool
    {
        return $user->hasRole('administrator') || $this->isTeamMember($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('administrator') || $this->isTeamAdmin($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Client $client): bool
    {
        return $user->hasRole('administrator') || $this->isTeamAdmin($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Client $client): bool
    {
        return $user->hasRole('administrator') || $this->isTeamAdmin($user);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Client $client): bool
    {
        return $user->hasRole('administrator') || $this->isTeamAdmin($user);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return $user->hasRole('administrator');
    }

    /**
     * Check if user is a member of any team.
     */
    protected function isTeamMember(User $user): bool
    {
        return $user->teams()->exists();
    }

    /**
     * Check if user is an admin or owner of any team.
     */
    protected function isTeamAdmin(User $user): bool
    {
        return $user->teams()->wherePivotIn('role', ['owner', 'admin'])->exists();
    }
}
