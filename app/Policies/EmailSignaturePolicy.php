<?php

namespace App\Policies;

use App\Models\EmailSignature;
use App\Models\User;

class EmailSignaturePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmailSignature $signature): bool
    {
        return $signature->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EmailSignature $signature): bool
    {
        return $signature->user_id === $user->id;
    }

    public function delete(User $user, EmailSignature $signature): bool
    {
        return $signature->user_id === $user->id;
    }
}
