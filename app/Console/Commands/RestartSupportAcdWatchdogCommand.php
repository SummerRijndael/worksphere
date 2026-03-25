<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class RestartSupportAcdWatchdogCommand extends Command
{
    protected $signature = 'support:acd-watchdog:restart {--reason=manual : Why the restart was requested}';

    protected $description = 'Signals the ACD watchdog to restart gracefully';

    public function handle(): int
    {
        try {
            $requestedAt = now()->timestamp;
            $reason = (string) $this->option('reason');
            $pid = Redis::hget('acd:watchdog:meta', 'pid');

            Redis::setex('acd:watchdog:restart_requested_at', 3600, (string) $requestedAt);
            Redis::setex('acd:watchdog:restart_reason', 3600, $reason);

            $target = $pid ? " for PID {$pid}" : '';
            $this->info("ACD watchdog restart signal sent{$target}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to signal ACD watchdog restart: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
