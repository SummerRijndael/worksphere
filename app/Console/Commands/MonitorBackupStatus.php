<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class MonitorBackupStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:monitor-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor running backup jobs and kill zombie processes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pid = Cache::get('backup_process_pid');
        $startedAt = Cache::get('backup_process_started_at');
        $status = Cache::get('backup_process_status');

        if (! $pid || ! $startedAt || $status !== 'running') {
            $this->info('No running backup process detected.');

            return;
        }

        $startTime = \Carbon\Carbon::parse($startedAt);
        // Timeout threshold: 2 hours (hard limit)
        // Note: Horizon timeout is 1 hour, but process might hang.
        if ($startTime->diffInHours(now()) >= 2) {
            $this->warn("Zombie backup process detected (PID: {$pid}). Running for > 2 hours.");

            // Check if process exists
            if (function_exists('posix_kill')) {
                // Signal 0 checks if process exists
                if (posix_kill($pid, 0)) {
                    $this->error("Killing process {$pid}...");

                    // Kill it
                    if (posix_kill($pid, SIGKILL)) {
                        $this->info("Process {$pid} killed successfully.");
                        $this->notifyAdmins($pid, true);
                    } else {
                        $this->error("Failed to kill process {$pid}.");
                        $this->notifyAdmins($pid, false);
                    }
                } else {
                    $this->info("Process {$pid} not found (already died?). Clearing tracking.");
                }
            } else {
                $this->error('posix_kill not available. Cannot kill process.');
            }

            // Clean up cache
            Cache::forget('backup_process_pid');
            Cache::forget('backup_process_started_at');
            Cache::forget('backup_process_status');
        } else {
            $this->info("Backup process (PID: {$pid}) running for ".$startTime->diffInMinutes(now()).' minutes.');
        }
    }

    protected function notifyAdmins($pid, $success)
    {
        $admins = User::role('administrator')->get();
        $message = $success
            ? "Zombie backup process (PID: {$pid}) was detected and KILLED."
            : "Zombie backup process (PID: {$pid}) was detected but COULD NOT be killed.";

        if ($admins->count() > 0) {
            Notification::send($admins, new SystemNotification(
                'system',
                'Zombie Backup Process',
                $message,
                null,
                null,
                ['pid' => $pid, 'success' => $success]
            ));
        }

        Log::warning($message);
    }
}
