<?php

namespace App\Policies;

use App\Models\EmailAccount;
use App\Models\User;

class EmailAccountPolicy
{
    /**
     * Determine if the user can view any email accounts.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can always list their own accounts
    }

    /**
     * Determine if the user can view the email account.
     */
    public function view(User $user, EmailAccount $emailAccount): bool
    {
        return $this->isOwner($user, $emailAccount);
    }

    /**
     * Determine if the user can create email accounts.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the email account.
     */
    public function update(User $user, EmailAccount $emailAccount): bool
    {
        return $this->isOwner($user, $emailAccount);
    }

    /**
     * Determine if the user can delete the email account.
     */
    public function delete(User $user, EmailAccount $emailAccount): bool
    {
        return $this->isOwner($user, $emailAccount);
    }

    /**
     * Check if user owns the email account (directly or via team).
     */
    protected function isOwner(User $user, EmailAccount $emailAccount): bool
    {
        // Super Admins can access everything
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        // Personal account
        if ($emailAccount->user_id === $user->id) {
            return true;
        }

        // Team account - check if user belongs to the team (if teams relationship exists)
        if ($emailAccount->team_id && method_exists($user, 'teams')) {
            return $user->teams()->where('teams.id', $emailAccount->team_id)->exists();
        }

        return false;
    }
}
