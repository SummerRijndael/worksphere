<?php

namespace App\Policies;

use App\Models\Email;
use App\Models\User;

class EmailPolicy
{
    /**
     * Determine if user can view any emails.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if user can view the email.
     */
    public function view(User $user, Email $email): bool
    {
        return $email->user_id === $user->id;
    }

    /**
     * Determine if user can create/send emails.
     */
    public function create(User $user): bool
    {
        // User must have at least one active email account
        return $user->emailAccounts()->active()->exists();
    }

    /**
     * Determine if user can update the email.
     */
    public function update(User $user, Email $email): bool
    {
        return $email->user_id === $user->id;
    }

    /**
     * Determine if user can delete the email.
     */
    public function delete(User $user, Email $email): bool
    {
        return $email->user_id === $user->id;
    }

    /**
     * Determine if user can send as a system account.
     */
    public function sendAsSystem(User $user): bool
    {
        return $user->hasPermissionTo('email.send_as_system');
    }
}
