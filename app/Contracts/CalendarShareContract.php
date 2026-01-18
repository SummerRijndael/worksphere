<?php

namespace App\Contracts;

use App\Models\User;

interface CalendarShareContract
{
    /**
     * Get all shares involving the user (both shared by them and shared with them).
     * Returns ['shared_with_me' => ..., 'my_shares' => ...]
     */
    public function getShares(User $user): array;

    /**
     * Share the user's calendar with another user by email.
     */
    public function share(User $owner, string $email, string $permission): void;

    /**
     * Update the permission level of a share.
     */
    public function updatePermission(User $owner, int $shareId, string $permission): bool;

    /**
     * Revoke a calendar share.
     */
    public function revoke(User $owner, int $shareId): bool;
}
