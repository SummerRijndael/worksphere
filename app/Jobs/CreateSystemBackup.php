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
    public function __construct(protected string $option = 'both')
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Backup job started', ['option' => $this->option]);

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

            // Disable signals to prevent interruption if possible?
            // Actually, we want it to be interrupted if we kill it.

            $exitCode = Artisan::call($command);

            if ($exitCode === 0) {
                Log::info('Backup job completed successfully');
                Cache::put('backup_process_status', 'completed', 300);
            } else {
                Log::error('Backup job failed with exit code '.$exitCode);
                Cache::put('backup_process_status', 'failed', 300);
            }

        } catch (\Throwable $e) {
            Log::error('Backup job failed exception: '.$e->getMessage());
            Cache::put('backup_process_status', 'failed', 300);
            throw $e;
        } finally {
            // Clear tracking if we finished cleanly (or threw)
            // But if we crash hard (zombie), this might not run.
            // That's why we rely on the Monitor command to check the 'running' status.
            Cache::forget('backup_process_pid');
        }
    }
}
