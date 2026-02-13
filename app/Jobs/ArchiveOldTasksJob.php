<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;

class ArchiveOldTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(TaskWorkflowService $workflowService): void
    {
        if (! Config::get('worksphere.task.auto_archive.enabled', true)) {
            return;
        }

        $days = Config::get('worksphere.task.auto_archive.days_after_completion', 30);
        $threshold = now()->subDays($days);

        try {
            $archiver = User::role('administrator')->first()
                ?? User::first();
        } catch (\Throwable $e) {
            $archiver = User::first();
        }

        if (! $archiver) {
            return;
        }

        Task::where('status', TaskStatus::Completed)
            ->where('updated_at', '<', $threshold)
            ->chunk(100, function ($tasks) use ($workflowService, $archiver) {
                foreach ($tasks as $task) {
                    try {
                        $workflowService->archiveTask($task, $archiver);
                    } catch (\Exception $e) {
                        // Log or handle individual task failure
                    }
                }
            });
    }
}
