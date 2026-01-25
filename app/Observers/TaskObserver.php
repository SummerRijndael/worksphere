<?php

namespace App\Observers;

use App\Models\Task;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        if ($task->project && $task->wasChanged('status')) {
            $task->project->recalculateProgress();
        }
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        if ($task->project) {
            $task->project->recalculateProgress();
        }
    }
}
