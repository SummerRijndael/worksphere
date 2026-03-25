<?php

namespace App\Console\Commands;

use App\Contracts\SupportRoutingServiceContract;
use App\Models\SupportRoutingQueueEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class SupportAcdWatchdogCommand extends Command
{
    protected bool $shouldQuit = false;

    protected ?string $stopReason = null;

    protected int $startedAt = 0;

    protected string $startedAtIso = '';

    protected bool $debugEnabled = false;

    protected int $cycleNumber = 0;

    protected int $debugEntriesLimit = 25;

    protected ?string $restartReason = null;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'support:acd-watchdog
        {--debug : Stream watchdog activity (cycle flow, Redis checks, routing attempts)}
        {--debug-entries=25 : Max queue entry IDs to print per cycle while debugging}';

    /**
     * The console command description.
     */
    protected $description = 'Runs the Automatic Call Distributor (ACD) memory-resident routing engine';

    /**
     * Execute the console command.
     */
    public function handle(SupportRoutingServiceContract $routingService)
    {
        $this->debugEnabled = (bool) $this->option('debug');
        $this->debugEntriesLimit = max(1, (int) $this->option('debug-entries'));

        $this->info('Starting ACD Watchdog Daemon...');
        $lastOrphanCheck = 0;
        $heartbeatTtl = 120;
        $this->startedAt = now()->timestamp;
        $this->startedAtIso = now()->toIso8601String();
        $this->bootstrapSignalHandling();
        $this->debug('Watchdog booted.', [
            'pid' => getmypid(),
            'engine' => config('support_chat.routing.engine', 'database'),
            'debug_entries' => $this->debugEntriesLimit,
        ]);

        while (! $this->shouldQuit) {
            $cycleStartedAt = microtime(true);
            $this->cycleNumber++;

            $this->dispatchPendingSignals();
            $this->debug('Cycle started.', [
                'cycle' => $this->cycleNumber,
                'uptime_seconds' => max(0, now()->timestamp - $this->startedAt),
            ]);

            if ($this->restartRequested()) {
                $reason = $this->restartReason
                    ? 'restart signal: '.$this->restartReason
                    : 'restart signal';
                $this->requestStop($reason);
                break;
            }

            $engine = (string) config('support_chat.routing.engine', 'database');
            if ($engine !== 'acd') {
                $this->debug('Routing engine is not ACD; skipping cycle.', [
                    'configured_engine' => $engine,
                ]);
                $this->sleepInterruptibly(5_000_000, 'routing engine is not set to acd');
                continue;
            }

            try {
                $now = now();
                Redis::setex('acd:watchdog:last_seen', $heartbeatTtl, (string) $now->timestamp);
                Redis::hset('acd:watchdog:meta', 'pid', (string) getmypid());
                Redis::hset('acd:watchdog:meta', 'updated_at', $now->toIso8601String());
                Redis::hset('acd:watchdog:meta', 'started_at', $this->startedAtIso);
                Redis::expire('acd:watchdog:meta', $heartbeatTtl);
                $this->debug('Heartbeat refreshed.', [
                    'ttl_seconds' => $heartbeatTtl,
                ]);

                // 1. Prune dead agents immediately
                $prunedAgents = (int) Redis::zremrangebyscore('acd:agents:available', '-inf', $now->timestamp - 120);
                if ($prunedAgents > 0) {
                    $this->debug('Pruned stale available agents.', [
                        'removed' => $prunedAgents,
                    ]);
                }

                // 2. Orphan Check (every 30 seconds) to avoid spamming the DB
                if ($now->timestamp - $lastOrphanCheck >= 30) {
                    $lastOrphanCheck = $now->timestamp;
                    $orphanStats = $this->checkOrphans();
                    $this->debug('Orphan check completed.', $orphanStats);
                }

                // 3. Check for Queue items ready to route
                // We want to process items whose score (scheduled time) is <= now
                $pendingEntries = Redis::zrangebyscore('acd:queue:pending', '-inf', $now->timestamp, [
                    'limit' => [0, 50],
                ]);
                $pendingCount = count($pendingEntries);
                $agentCount = (int) Redis::zcard('acd:agents:available');
                $pendingQueueDepth = (int) Redis::zcard('acd:queue:pending');

                $this->debug('Queue snapshot.', [
                    'ready_entries' => $pendingCount,
                    'pending_queue_depth' => $pendingQueueDepth,
                    'available_agents' => $agentCount,
                ]);

                if (empty($pendingEntries)) {
                    // Sleep for 0.5s if nothing to do.
                    $this->sleepInterruptibly(500000, 'no pending queue entries are ready');
                    continue;
                }

                // 3. Are there any agents available?
                if ($agentCount === 0) {
                    // Queue exists but no agents.
                    $this->sleepInterruptibly(1000000, 'pending entries exist but zero available agents');
                    continue;
                }

                if ($this->debugEnabled) {
                    $preview = array_slice(array_map(static fn ($entryId) => (int) $entryId, $pendingEntries), 0, $this->debugEntriesLimit);
                    $this->debug('Routing batch selected.', [
                        'entry_preview' => $preview,
                        'total_ready_entries' => $pendingCount,
                    ]);
                }

                // 4. Route!
                foreach ($pendingEntries as $entryId) {
                    $entryId = (int) $entryId;

                    // Pop it out of Redis first so another worker doesn't grab it simultaneously
                    $removed = (int) Redis::zrem('acd:queue:pending', $entryId);
                    if (! $removed) {
                        $this->debug('Entry was already claimed by another worker.', [
                            'entry_id' => $entryId,
                        ]);
                        continue;
                    }

                    if ($this->debugEnabled) {
                        $this->debugProcessQueueEntry($routingService, $entryId);
                    } else {
                        try {
                            // processQueueEntry acquires a DB lock and safely attempts assignment
                            $routingService->processQueueEntry($entryId);
                        } catch (\Throwable $e) {
                            $this->error("Failed to process entry {$entryId}: " . $e->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ACD Watchdog cycle failed; retrying.', [
                    'message' => $e->getMessage(),
                ]);
                $this->error('ACD Watchdog unavailable: ' . $e->getMessage());
                $this->debug('Cycle failed.', [
                    'cycle' => $this->cycleNumber,
                    'error' => $e->getMessage(),
                ]);
                $this->sleepInterruptibly(5_000_000, 'retry after cycle failure');
            }

            $this->debug('Cycle completed.', [
                'cycle' => $this->cycleNumber,
                'elapsed_ms' => round((microtime(true) - $cycleStartedAt) * 1000, 2),
            ]);
        }

        $this->cleanupPresenceMeta();

        $reason = $this->stopReason ? " ({$this->stopReason})" : '';
        $this->info("ACD Watchdog stopped{$reason}.");

        return self::SUCCESS;
    }

    /**
     * @return array{checked:int, orphaned:int, active_agents:int}
     */
    protected function checkOrphans(): array
    {
        // Find assigned entries that might be orphaned
        $assignedChats = SupportRoutingQueueEntry::query()
            ->where('state', SupportRoutingQueueEntry::STATE_ASSIGNED)
            ->whereNotNull('assigned_to')
            ->get(['id', 'conversation_id', 'assigned_to']);

        if ($assignedChats->isEmpty()) {
            return [
                'checked' => 0,
                'orphaned' => 0,
                'active_agents' => (int) Redis::zcard('acd:agents:available'),
            ];
        }

        // We pull the full set of online agents once
        $activeAgentIds = Redis::zrange('acd:agents:available', 0, -1);
        $activeSet = array_flip(array_map(static fn ($id) => (string) $id, $activeAgentIds)); // O(1) lookup
        $orphanedCount = 0;

        foreach ($assignedChats as $chat) {
            if (! isset($activeSet[(string) $chat->assigned_to])) {
                // Agent dropped offline!
                // We emit an event. A listener can broadcast this to the Lead Dashboard.
                // We don't rip it away automatically to give the agent a chance to reconnect.
                event(new \App\Events\Support\SupportConversationOrphaned($chat->conversation_id, $chat->assigned_to));
                Log::warning("ACD Watchdog: Chat {$chat->conversation_id} is orphaned. Agent {$chat->assigned_to} is offline.");
                $orphanedCount++;
            }
        }

        return [
            'checked' => $assignedChats->count(),
            'orphaned' => $orphanedCount,
            'active_agents' => count($activeAgentIds),
        ];
    }

    protected function bootstrapSignalHandling(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGTERM, fn () => $this->requestStop('SIGTERM'));
        pcntl_signal(SIGINT, fn () => $this->requestStop('SIGINT'));

        if (defined('SIGHUP')) {
            pcntl_signal(SIGHUP, fn () => $this->requestStop('SIGHUP restart signal'));
        }

        if (defined('SIGUSR2')) {
            pcntl_signal(SIGUSR2, fn () => $this->requestStop('SIGUSR2 restart signal'));
        }
    }

    protected function dispatchPendingSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch') && ! function_exists('pcntl_async_signals')) {
            pcntl_signal_dispatch();
        }
    }

    protected function sleepInterruptibly(int $microseconds, ?string $reason = null): void
    {
        if ($reason !== null) {
            $this->debug('Sleeping.', [
                'for' => $this->formatSleepDuration($microseconds),
                'reason' => $reason,
            ]);
        }

        $remaining = $microseconds;

        while ($remaining > 0 && ! $this->shouldQuit) {
            $this->dispatchPendingSignals();

            if ($this->restartRequested()) {
                $reason = $this->restartReason
                    ? 'restart signal: '.$this->restartReason
                    : 'restart signal';
                $this->requestStop($reason);
                break;
            }

            $chunk = min($remaining, 250000);
            usleep($chunk);
            $remaining -= $chunk;
        }
    }

    protected function restartRequested(): bool
    {
        try {
            $requestedAt = Redis::get('acd:watchdog:restart_requested_at');
            if (! is_numeric($requestedAt) || (int) $requestedAt <= $this->startedAt) {
                return false;
            }

            $reason = Redis::get('acd:watchdog:restart_reason');
            $this->restartReason = is_string($reason) && $reason !== '' ? $reason : null;

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function requestStop(string $reason): void
    {
        if ($this->shouldQuit) {
            return;
        }

        $this->shouldQuit = true;
        $this->stopReason = $reason;

        if (str_contains(strtolower($reason), 'restart signal')) {
            $this->warn('ACD Watchdog restart signal received. Gracefully shutting down for restart...');
        }

        Log::info('ACD Watchdog stop requested.', ['reason' => $reason]);
    }

    protected function cleanupPresenceMeta(): void
    {
        try {
            $meta = Redis::hgetall('acd:watchdog:meta');
            $currentPid = (string) getmypid();

            Redis::setex('acd:watchdog:last_stop_reason', 3600, (string) ($this->stopReason ?? 'stopped'));
            Redis::setex('acd:watchdog:last_stopped_at', 3600, now()->toIso8601String());

            if (($meta['pid'] ?? null) === $currentPid) {
                Redis::del('acd:watchdog:last_seen');
                Redis::del('acd:watchdog:meta');
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup ACD watchdog presence meta.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function debugProcessQueueEntry(
        SupportRoutingServiceContract $routingService,
        int $entryId
    ): void {
        $before = SupportRoutingQueueEntry::query()->find($entryId, [
            'id',
            'state',
            'attempts',
            'max_attempts',
            'assigned_to',
            'next_attempt_at',
            'last_error',
        ]);

        $startedAt = microtime(true);

        try {
            $routingService->processQueueEntry($entryId);
        } catch (\Throwable $e) {
            $this->error("Failed to process entry {$entryId}: " . $e->getMessage());
            $this->debug('Entry processing threw an exception.', [
                'entry_id' => $entryId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $after = SupportRoutingQueueEntry::query()->find($entryId, [
            'id',
            'state',
            'attempts',
            'max_attempts',
            'assigned_to',
            'next_attempt_at',
            'last_error',
        ]);

        $this->debug('Entry processed.', [
            'entry_id' => $entryId,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'before' => $this->entrySnapshot($before),
            'after' => $this->entrySnapshot($after),
        ]);
    }

    protected function entrySnapshot(?SupportRoutingQueueEntry $entry): ?array
    {
        if (! $entry) {
            return null;
        }

        return [
            'state' => $entry->state,
            'attempts' => (int) $entry->attempts,
            'max_attempts' => (int) $entry->max_attempts,
            'assigned_to' => $entry->assigned_to ? (int) $entry->assigned_to : null,
            'next_attempt_at' => $entry->next_attempt_at?->toIso8601String(),
            'last_error' => $entry->last_error
                ? Str::limit((string) $entry->last_error, 140)
                : null,
        ];
    }

    protected function debug(string $message, array $context = []): void
    {
        if (! $this->debugEnabled) {
            return;
        }

        $prefix = now()->format('H:i:s.v');
        $encodedContext = '';
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);
            $encodedContext = $encoded === false ? '' : ' '.$encoded;
        }

        $this->line("<fg=gray>[{$prefix}]</> {$message}{$encodedContext}");
    }

    protected function formatSleepDuration(int $microseconds): string
    {
        if ($microseconds >= 1_000_000) {
            return number_format($microseconds / 1_000_000, 2).'s';
        }

        if ($microseconds >= 1_000) {
            return number_format($microseconds / 1_000, 0).'ms';
        }

        return $microseconds.'µs';
    }
}
