<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.view') || $user->hasPermissionTo('user_manage') || $user->id === $model->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.update') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.delete') || $user->hasPermissionTo('user_manage');
    }

    /**
     * Determine whether the user can view the user profile.
     * Allowed if:
     * 1. It's their own profile
     * 2. They have user_manage permission
     * 3. They share a team with the user
     */
    public function viewProfile(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        if ($user->hasPermissionTo('user_manage')) {
            return true;
        }

        // Check if they share any team
        return $user->teams()
            ->whereHas('members', function ($query) use ($model) {
                $query->where('users.id', $model->id);
            })
            ->exists();
    }
}
