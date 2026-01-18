<?php

namespace App\Policies;

use App\Models\TaskTemplate;
use App\Models\User;

class TaskTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->teams->contains($taskTemplate->team_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->teams->contains($taskTemplate->team_id);
    }

    public function delete(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->teams->contains($taskTemplate->team_id);
    }

    public function restore(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->teams->contains($taskTemplate->team_id);
    }

    public function forceDelete(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->teams->contains($taskTemplate->team_id);
    }
}
