<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportRoutingQueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

class AcdSupportRoutingService extends SupportRoutingService
{
    /**
     * Override dispatchEntry to push directly to Redis ACD queue instead of Laravel async jobs.
     */
    protected function dispatchEntry(SupportRoutingQueueEntry $entry, int $delaySeconds = 0): void
    {
        // Add to Redis sorted set. 
        // We use the timestamp as the score to route oldest first.
        $score = now()->timestamp + $delaySeconds;
        
        // Priority bonus: artificially lower the score so it gets picked up sooner.
        $priorityBonus = match ($entry->priority) {
            'urgent' => 3600, // 1 hour bonus
            'high' => 1800,   // 30 min bonus
            default => 0,
        };
        
        $score -= $priorityBonus;

        Redis::zadd('acd:queue:pending', $score, $entry->id);

        // Ping the watchdog daemon if it's sleeping
        Redis::publish('acd:events', 'queue_updated');
    }

    /**
     * Override to clear from Redis if cancelled.
     */
    public function cancelConversationQueue(
        SupportConversation $conversation,
        string $reason = 'conversation_closed'
    ): void {
        parent::cancelConversationQueue($conversation, $reason);

        $entryIds = SupportRoutingQueueEntry::where('conversation_id', $conversation->id)
            ->pluck('id')->toArray();

        if (!empty($entryIds)) {
            // Unpack ids for variadic zrem
            Redis::zrem('acd:queue:pending', ...$entryIds);
        }
    }

    /**
     * Override immediate routing to ping the watchdog daemon.
     */
    public function triggerImmediateRouting(): void
    {
        Redis::publish('acd:events', 'agents_updated');
    }

    /**
     * The DB sweep runs every minute to catch anything missed. 
     * It uses our overridden dispatchEntry so everything flows to Redis.
     */
    public function dispatchDueEntries(?int $limit = null): int 
    {
        return parent::dispatchDueEntries($limit);
    }

    protected function selectCandidateAgent(SupportConversation $conversation): ?User
    {
        // Instantly prune agents who haven't sent a heartbeat in the last 120 seconds
        Redis::zremrangebyscore('acd:agents:available', '-inf', now()->timestamp - 120);

        $availableIds = Redis::zrange('acd:agents:available', 0, -1);
        if (empty($availableIds)) {
            return null;
        }

        $adapter = $this->supportAccessAdapterResolver->resolve();
        $query = User::query();
        $adapter->applyEligibleAgentsScope($query, $conversation);

        $candidates = $query
            ->whereIn('id', $availableIds)
            ->where('status', 'active')
            ->select(['id', 'public_id', 'name', 'email', 'status'])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $loads = $this->activeLoadByUser($candidateIds);
        $capacities = $this->capacityByUser($conversation, $candidateIds);

        $best = null;
        $bestLoad = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            if (! $adapter->canBeAssignedToConversation($candidate, $conversation)) {
                continue;
            }

            $userId = (int) $candidate->id;
            $capacity = $this->clampAgentCapacity((int) ($capacities[$userId] ?? $this->defaultAgentCapacity()));
            $load = (int) ($loads[$userId] ?? 0);

            if ($load >= $capacity) {
                continue;
            }

            if ($best === null || $load < $bestLoad) {
                $best = $candidate;
                $bestLoad = $load;
            }
        }

        return $best;
    }
}
