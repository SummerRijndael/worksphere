<?php

namespace App\Console\Commands;

use App\Contracts\SupportRoutingServiceContract;
use App\Models\SupportRoutingQueueEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SupportAcdWatchdogCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'support:acd-watchdog';

    /**
     * The console command description.
     */
    protected $description = 'Runs the Automatic Call Distributor (ACD) memory-resident routing engine';

    /**
     * Execute the console command.
     */
    public function handle(SupportRoutingServiceContract $routingService)
    {
        $this->info('Starting ACD Watchdog Daemon...');
        $lastOrphanCheck = 0;

        while (true) {
            if (config('support_chat.routing.engine', 'database') !== 'acd') {
                sleep(5);
                continue;
            }

            // 1. Prune dead agents immediately
            Redis::zremrangebyscore('acd:agents:available', '-inf', now()->timestamp - 120);

            // 2. Orphan Check (every 30 seconds) to avoid spamming the DB
            $now = now()->timestamp;
            if ($now - $lastOrphanCheck >= 30) {
                $lastOrphanCheck = $now;
                $this->checkOrphans();
            }

            // 3. Check for Queue items ready to route
            // We want to process items whose score (scheduled time) is <= now
            $pendingEntries = Redis::zrangebyscore('acd:queue:pending', '-inf', now()->timestamp, [
                'limit' => [0, 50],
            ]);

            if (empty($pendingEntries)) {
                // Sleep for 0.5s if nothing to do. We can also use Redis::blpop but ZSets don't support blocking pull well natively in basic setups.
                usleep(500000); 
                continue;
            }

            // 3. Are there any agents available?
            $agentCount = Redis::zcard('acd:agents:available');
            if ($agentCount === 0) {
                // Queue exists but no agents. 
                usleep(1000000); // Wait 1 second before retrying
                continue;
            }

            // 4. Route!
            foreach ($pendingEntries as $entryId) {
                // Pop it out of Redis first so another worker doesn't grab it simultaneously
                $removed = Redis::zrem('acd:queue:pending', $entryId);
                if ($removed) {
                    try {
                        // processQueueEntry acquires a DB lock and safely attempts assignment
                        $routingService->processQueueEntry($entryId);
                    } catch (\Throwable $e) {
                        $this->error("Failed to process entry {$entryId}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    protected function checkOrphans(): void
    {
        // Find assigned entries that might be orphaned
        $assignedChats = SupportRoutingQueueEntry::query()
            ->where('state', SupportRoutingQueueEntry::STATE_ASSIGNED)
            ->whereNotNull('assigned_to')
            ->get(['id', 'conversation_id', 'assigned_to']);

        if ($assignedChats->isEmpty()) {
            return;
        }

        // We pull the full set of online agents once
        $activeAgentIds = Redis::zrange('acd:agents:available', 0, -1);
        $activeSet = array_flip($activeAgentIds); // O(1) lookup

        foreach ($assignedChats as $chat) {
            if (! isset($activeSet[$chat->assigned_to])) {
                // Agent dropped offline!
                // We emit an event. A listener can broadcast this to the Lead Dashboard.
                // We don't rip it away automatically to give the agent a chance to reconnect.
                event(new \App\Events\Support\SupportConversationOrphaned($chat->conversation_id, $chat->assigned_to));
                Log::warning("ACD Watchdog: Chat {$chat->conversation_id} is orphaned. Agent {$chat->assigned_to} is offline.");
            }
        }
    }
}
