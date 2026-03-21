<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CreateSystemBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $option = 'both',
        protected ?int $userId = null
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $auditService = app(\App\Services\AuditService::class);
        $user = $this->userId ? \App\Models\User::find($this->userId) : null;
        $trigger = $user ? "Manual ({$user->name})" : 'System (Scheduled)';

        Log::info('Backup job started', ['option' => $this->option, 'triggered_by' => $trigger]);

        // Audit: Backup Started
        $auditService->log(
            action: \App\Enums\AuditAction::BackupStarted,
            category: \App\Enums\AuditCategory::System,
            user: $user,
            context: [
                'option' => $this->option,
                'triggered_by' => $trigger,
            ]
        );

        // Store backup tracking info for zombie detection
        $pid = getmypid();
        Cache::put('backup_process_pid', $pid, 3700);
        Cache::put('backup_process_started_at', now(), 3700);
        Cache::put('backup_process_status', 'running', 3700);

        try {
            $command = 'backup:run';
            if ($this->option === 'db') {
                $command .= ' --only-db';
            } elseif ($this->option === 'files') {
                $command .= ' --only-files';
            }

            $exitCode = Artisan::call($command);

            if ($exitCode === 0) {
                Log::info('Backup job completed successfully');
                Cache::put('backup_process_status', 'completed', 300);

                // Audit: Backup Completed
                $auditService->log(
                    action: \App\Enums\AuditAction::BackupCompleted,
                    category: \App\Enums\AuditCategory::System,
                    user: $user,
                    context: [
                        'option' => $this->option,
                        'triggered_by' => $trigger,
                    ]
                );

                $this->notifyAdmins(
                    title: 'System Backup Completed',
                    message: "The system backup ($this->option) has been completed successfully. Triggered by: $trigger.",
                    type: 'success',
                    actionUrl: '/admin/maintenance/backups'
                );
            } else {
                $output = Artisan::output();
                Log::error('Backup job failed with exit code '.$exitCode, ['output' => $output]);
                Cache::put('backup_process_status', 'failed', 300);

                // Audit: Backup Failed
                $auditService->log(
                    action: \App\Enums\AuditAction::BackupFailed,
                    category: \App\Enums\AuditCategory::System,
                    user: $user,
                    context: [
                        'option' => $this->option,
                        'triggered_by' => $trigger,
                        'exit_code' => $exitCode,
                        'output' => substr($output, 0, 1000), // Limit output size
                    ]
                );

                $this->notifyAdmins(
                    title: 'System Backup Failed',
                    message: "The system backup ($this->option) failed with exit code $exitCode. Triggered by: $trigger.",
                    type: 'error',
                    actionUrl: '/admin/maintenance/backups'
                );
            }

        } catch (\Throwable $e) {
            Log::error('Backup job failed exception: '.$e->getMessage());
            Cache::put('backup_process_status', 'failed', 300);

            // Audit: Backup Failed (Exception)
            $auditService->log(
                action: \App\Enums\AuditAction::BackupFailed,
                category: \App\Enums\AuditCategory::System,
                user: $user,
                context: [
                    'option' => $this->option,
                    'triggered_by' => $trigger,
                    'error' => $e->getMessage(),
                ]
            );

                $this->notifyAdmins(
                    title: 'System Backup Exception',
                    message: "An exception occurred during system backup ($this->option): {$e->getMessage()}. Triggered by: $trigger.",
                    type: 'error',
                    actionUrl: '/admin/maintenance/backups'
                );

            throw $e;
        } finally {
            Cache::forget('backup_process_pid');
        }
    }

    /**
     * Notify administrators.
     */
    protected function notifyAdmins(string $title, string $message, string $type, ?string $actionUrl = null): void
    {
        $admins = \App\Models\User::role(['administrator'])->get();

        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\SystemNotification(
                type: 'system',
                title: $title,
                message: $message,
                actionUrl: $actionUrl,
                metadata: ['status' => $type]
            ));
        }
    }
}
