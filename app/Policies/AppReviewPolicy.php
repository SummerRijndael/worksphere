<?php

namespace App\Policies;

use App\Models\AppReview;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AppReviewPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('reviews.moderate') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Any authenticated user can submit a review
    }

    /**
     * Determine whether the user can update the model (moderate).
     */
    public function update(User $user, AppReview $review): bool
    {
        return $user->hasPermissionTo('reviews.moderate') || $user->hasRole('administrator');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AppReview $review): bool
    {
        return $user->hasPermissionTo('reviews.moderate') || $user->hasRole('administrator');
    }
}
