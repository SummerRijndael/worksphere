<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class ArchiveOldTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'worksphere:archive-tasks {--dry-run : Simulate the archiving process without changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically archive completed tasks older than the configured threshold.';

    /**
     * Execute the console command.
     */
    public function handle(TaskWorkflowService $workflowService)
    {
        if (! Config::get('worksphere.task.auto_archive.enabled', true)) {
            $this->info('Auto-archiving is disabled in configuration.');

            return;
        }

        $days = Config::get('worksphere.task.auto_archive.days_after_completion', 30);
        $threshold = now()->subDays($days);
        $isDryRun = $this->option('dry-run');

        // Find a system user/admin to attribute the archiving to
        // Use a safer lookup that doesn't throw if role is missing
        try {
            // 'administrator' is the super admin role defined in config/roles.php
            $archiver = User::role('administrator')->first()
                ?? User::first();
        } catch (\Throwable $e) {
            // Fallback if role permissions fail
            $archiver = User::first();
        }

        if (! $archiver) {
            $this->error('No user found to perform archiving.');

            return;
        }

        $this->info("Looking for tasks completed before {$threshold->toDateTimeString()}...");

        $query = Task::where('status', TaskStatus::Completed)
            ->where('updated_at', '<', $threshold);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No tasks found to archive.');

            return;
        }

        $this->info("Found {$count} tasks to archive.");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(100, function ($tasks) use ($workflowService, $archiver, $isDryRun, $bar) {
            foreach ($tasks as $task) {
                if ($isDryRun) {
                    $this->info("  [DRY RUN] Would archive Task #{$task->id}: {$task->title}");
                } else {
                    try {
                        if ($workflowService->archiveTask($task, $archiver)) {
                            // Success
                        } else {
                            $this->error("Failed to archive task {$task->id}: Invalid transition.");
                        }
                    } catch (\Exception $e) {
                        $this->error("Failed to archive task {$task->id}: {$e->getMessage()}");
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info($isDryRun ? 'Dry run completed.' : 'Archiving completed.');
    }
}
