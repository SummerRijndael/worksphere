<?php

namespace App\Policies;

use App\Models\EmailFolder;
use App\Models\User;

class EmailFolderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmailFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EmailFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    public function delete(User $user, EmailFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
