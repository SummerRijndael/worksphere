<?php

namespace App\Console\Commands;

use App\Models\EmailSyncLog;
use Illuminate\Console\Command;

class EmailCleanupLogs extends Command
{
    protected $signature = 'email:cleanup-logs {--days= : Number of days to retain logs}';

    protected $description = 'Clean up old email sync logs';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('email.sync_log_retention_days', 7);

        $deleted = EmailSyncLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} sync log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
