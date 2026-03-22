<?php

namespace App\Services\Support;

use App\Events\Support\SupportConversationChanged;
use App\Events\Support\SupportMessageCreated;
use App\Jobs\BroadcastSupportConversationChanged;
use App\Jobs\BroadcastSupportMessageCreated;
use App\Jobs\RouteSupportConversationJob;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportRoutingQueueEntry;
use App\Models\SupportSkillMembership;
use App\Models\User;
use App\Jobs\Support\SupportAssignmentTimeoutJob;
use App\Services\Chat\PresenceService;
use App\Services\Support\Access\SupportAccessAdapterResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SupportRoutingService
{
    public function __construct(
        protected SupportAccessAdapterResolver $supportAccessAdapterResolver,
        protected PresenceService $presenceService
    ) {}

    public function enqueueConversation(
        SupportConversation $conversation,
        string $reason = 'conversation_opened',
        bool $force = false
    ): ?SupportRoutingQueueEntry {
        if (! $this->routingEnabled()) {
            return null;
        }

        $conversation = $conversation->fresh() ?? $conversation;
        if (! $this->shouldRouteConversation($conversation, $force)) {
            return null;
        }

        $entry = DB::transaction(function () use ($conversation, $reason): SupportRoutingQueueEntry {
            $entry = SupportRoutingQueueEntry::query()->firstOrNew([
                'conversation_id' => $conversation->id,
            ]);

            $entry->forceFill([
                'support_skill_id' => $conversation->support_skill_id,
                'state' => SupportRoutingQueueEntry::STATE_PENDING,
                'enqueue_reason' => $reason,
                'priority' => $this->normalizePriority((string) ($conversation->priority ?? 'normal')),
                'max_attempts' => max(1, (int) config('support_chat.routing.max_attempts', 20)),
                'last_error' => null,
                'next_attempt_at' => now(),
                'assigned_to' => null,
            ])->save();

            return $entry->fresh();
        });

        $this->dispatchEntry($entry);

        return $entry;
    }

    public function cancelConversationQueue(
        SupportConversation $conversation,
        string $reason = 'conversation_closed'
    ): void {
        if (! $this->routingEnabled()) {
            return;
        }

        SupportRoutingQueueEntry::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('state', [
                SupportRoutingQueueEntry::STATE_PENDING,
                SupportRoutingQueueEntry::STATE_ROUTING,
            ])
            ->update([
                'state' => SupportRoutingQueueEntry::STATE_CANCELLED,
                'enqueue_reason' => $reason,
                'last_error' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function markConversationAssigned(
        SupportConversation $conversation,
        ?int $agentId = null,
        string $reason = 'manual_assignment'
    ): void {
        if (! $this->routingEnabled()) {
            return;
        }

        SupportRoutingQueueEntry::query()
            ->where('conversation_id', $conversation->id)
            ->update([
                'state' => SupportRoutingQueueEntry::STATE_ASSIGNED,
                'assigned_to' => $agentId ?? $conversation->assigned_to,
                'enqueue_reason' => $reason,
                'next_attempt_at' => null,
                'last_error' => null,
                'last_routed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function dispatchDueEntries(?int $limit = null): int
    {
        if (! $this->routingEnabled()) {
            return 0;
        }

        $batchSize = max(1, (int) ($limit ?? config('support_chat.routing.sweeper_batch_size', 50)));
        $staleSeconds = max(10, (int) config('support_chat.routing.stale_routing_seconds', 60));
        $staleCutoff = now()->subSeconds($staleSeconds);

        SupportRoutingQueueEntry::query()
            ->where('state', SupportRoutingQueueEntry::STATE_ROUTING)
            ->where('updated_at', '<=', $staleCutoff)
            ->limit($batchSize)
            ->update([
                'state' => SupportRoutingQueueEntry::STATE_PENDING,
                'next_attempt_at' => now(),
                'last_error' => 'Recovered stale routing entry for retry.',
                'updated_at' => now(),
            ]);

        $entries = SupportRoutingQueueEntry::query()
            ->where('state', SupportRoutingQueueEntry::STATE_PENDING)
            ->where(function (Builder $query): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('priority')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($entries as $entry) {
            $this->dispatchEntry($entry);
        }

        return $entries->count();
    }

    public function processQueueEntry(int $queueEntryId): void
    {
        if (! $this->routingEnabled()) {
            return;
        }

        $entry = SupportRoutingQueueEntry::query()->find($queueEntryId);
        if (! $entry) {
            return;
        }

        if (in_array($entry->state, [
            SupportRoutingQueueEntry::STATE_ASSIGNED,
            SupportRoutingQueueEntry::STATE_CANCELLED,
            SupportRoutingQueueEntry::STATE_FAILED,
        ], true)) {
            return;
        }

        $lockSeconds = max(5, (int) config('support_chat.routing.lock_seconds', 15));
        $lock = Cache::lock('support-routing:conversation:'.$entry->conversation_id, $lockSeconds);
        if (! $lock->get()) {
            return;
        }

        try {
            $result = DB::transaction(function () use ($queueEntryId): array {
                $entry = SupportRoutingQueueEntry::query()->lockForUpdate()->find($queueEntryId);
                if (! $entry) {
                    return ['status' => 'missing'];
                }

                if (in_array($entry->state, [
                    SupportRoutingQueueEntry::STATE_ASSIGNED,
                    SupportRoutingQueueEntry::STATE_CANCELLED,
                    SupportRoutingQueueEntry::STATE_FAILED,
                ], true)) {
                    return ['status' => 'terminal'];
                }

                $conversation = SupportConversation::query()->lockForUpdate()->find($entry->conversation_id);
                if (! $conversation) {
                    $entry->forceFill([
                        'state' => SupportRoutingQueueEntry::STATE_CANCELLED,
                        'last_error' => 'Conversation not found.',
                        'next_attempt_at' => null,
                    ])->save();

                    return ['status' => 'cancelled'];
                }

                if (! $this->shouldRouteConversation($conversation)) {
                    if ($conversation->assigned_to) {
                        $entry->forceFill([
                            'state' => SupportRoutingQueueEntry::STATE_ASSIGNED,
                            'assigned_to' => $conversation->assigned_to,
                            'next_attempt_at' => null,
                            'last_error' => null,
                            'last_routed_at' => now(),
                        ])->save();

                        return ['status' => 'already_assigned'];
                    }

                    $entry->forceFill([
                        'state' => SupportRoutingQueueEntry::STATE_CANCELLED,
                        'next_attempt_at' => null,
                        'last_error' => 'Conversation is no longer routable.',
                    ])->save();

                    return ['status' => 'cancelled'];
                }

                $entry->forceFill([
                    'state' => SupportRoutingQueueEntry::STATE_ROUTING,
                    'last_error' => null,
                ])->save();

                $agent = $this->selectCandidateAgent($conversation);
                if (! $agent) {
                    $delaySeconds = $this->incrementRetry($entry, 'No eligible support agent is currently available.');

                    return [
                        'status' => 'retry',
                        'entry_id' => $entry->id,
                        'delay_seconds' => $delaySeconds,
                        'state' => $entry->state,
                    ];
                }

                $conversation->forceFill([
                    'assigned_to' => $agent->id,
                    'status' => SupportConversation::STATUS_PENDING_ACCEPTANCE,
                    'ai_handoff_required' => false,
                    'ai_handoff_reason' => null,
                    'support_skill_id' => $conversation->support_skill_id ?? $entry->support_skill_id,
                ])->forceFill([
                    'chat_state' => SupportConversation::CHAT_STATE_NEW,
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_PENDING,
                ])->save();

                $timeoutSeconds = (int) config('support_chat.routing.assignment_timeout_seconds', 60);
                SupportAssignmentTimeoutJob::dispatch($conversation->id, $agent->id)
                    ->delay(now()->addSeconds($timeoutSeconds));


                $message = SupportMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => SupportMessage::SENDER_SYSTEM,
                    'sender_user_id' => null,
                    'body' => "Conversation is being assigned to {$agent->name} (Waiting for acceptance).",
                    'metadata' => [
                        'type' => 'assignment_auto',
                        'agent_id' => $agent->public_id,
                        'agent_name' => $agent->name,
                    ],
                ]);

                $conversation->forceFill([
                    'last_message_at' => $message->created_at,
                ])->save();

                $entry->forceFill([
                    'state' => SupportRoutingQueueEntry::STATE_ASSIGNED,
                    'assigned_to' => $agent->id,
                    'last_routed_at' => now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                ])->save();

                return [
                    'status' => 'assigned',
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                ];
            });

            if (($result['status'] ?? null) === 'retry') {
                $entryState = (string) ($result['state'] ?? '');
                if ($entryState === SupportRoutingQueueEntry::STATE_PENDING) {
                    $entryToRetry = SupportRoutingQueueEntry::query()->find((int) $result['entry_id']);
                    if ($entryToRetry) {
                        $this->dispatchEntry($entryToRetry, (int) ($result['delay_seconds'] ?? 0));
                    }
                }

                return;
            }

            if (($result['status'] ?? null) !== 'assigned') {
                return;
            }

            $conversation = SupportConversation::query()
                ->with('assignee:id,public_id')
                ->find((int) $result['conversation_id']);
            $message = SupportMessage::query()
                ->with(['sender:id,public_id,name,email', 'media'])
                ->find((int) $result['message_id']);

            if (! $conversation || ! $message) {
                return;
            }

            $this->broadcastMessageCreated($conversation, $message);
            $this->broadcastConversationChanged($conversation);
        } catch (\Throwable $exception) {
            Log::warning('[SupportRouting] Failed processing queue entry.', [
                'entry_id' => $queueEntryId,
                'error' => $exception->getMessage(),
            ]);

            DB::transaction(function () use ($queueEntryId, $exception): void {
                $entry = SupportRoutingQueueEntry::query()->lockForUpdate()->find($queueEntryId);
                if (! $entry) {
                    return;
                }

                $this->incrementRetry($entry, 'Routing failure: '.$exception->getMessage());
            });
        } finally {
            $lock->release();
        }
    }

    protected function shouldRouteConversation(SupportConversation $conversation, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        if (
            $conversation->chat_state === SupportConversation::CHAT_STATE_ENDED
            || in_array($conversation->status, [SupportConversation::STATUS_RESOLVED, SupportConversation::STATUS_CLOSED], true)
        ) {
            return false;
        }

        if (
            $conversation->assigned_to
            || $conversation->assignment_state === SupportConversation::ASSIGNMENT_STATE_ASSIGNED
        ) {
            return false;
        }

        $aiEnabled = (bool) ($conversation->ai_enabled && config('support_chat.ai_enabled', true));
        if ($aiEnabled) {
            return (bool) (
                $conversation->status === SupportConversation::STATUS_WAITING_HUMAN
                || $conversation->ai_handoff_required
            );
        }

        return in_array($conversation->status, [
            SupportConversation::STATUS_OPEN,
            SupportConversation::STATUS_WAITING_HUMAN,
            SupportConversation::STATUS_BOT_ACTIVE,
        ], true);
    }

    protected function normalizePriority(string $priority): int
    {
        return match (strtolower(trim($priority))) {
            'urgent' => 25,
            'high' => 50,
            'normal' => 100,
            'low' => 150,
            default => 100,
        };
    }

    protected function dispatchEntry(SupportRoutingQueueEntry $entry, int $delaySeconds = 0): void
    {
        if (! $this->routingEnabled()) {
            return;
        }

        $job = RouteSupportConversationJob::dispatch($entry->id);
        if ($delaySeconds > 0 && $this->supportsDelayedDispatch()) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function supportsDelayedDispatch(): bool
    {
        return (string) config('queue.default', 'sync') !== 'sync';
    }

    protected function incrementRetry(SupportRoutingQueueEntry $entry, string $message): int
    {
        $attempts = (int) $entry->attempts + 1;
        $maxAttempts = max(1, (int) ($entry->max_attempts ?: config('support_chat.routing.max_attempts', 20)));
        $delaySeconds = max(1, (int) config('support_chat.routing.retry_delay_seconds', 15));

        if ($attempts >= $maxAttempts) {
            $entry->forceFill([
                'attempts' => $attempts,
                'state' => SupportRoutingQueueEntry::STATE_FAILED,
                'next_attempt_at' => null,
                'last_error' => $message,
            ])->save();

            return 0;
        }

        $entry->forceFill([
            'attempts' => $attempts,
            'state' => SupportRoutingQueueEntry::STATE_PENDING,
            'next_attempt_at' => now()->addSeconds($delaySeconds),
            'last_error' => $message,
        ])->save();

        return $delaySeconds;
    }

    protected function routingEnabled(): bool
    {
        return (bool) config('support_chat.routing.enabled', true);
    }

    protected function selectCandidateAgent(SupportConversation $conversation): ?User
    {
        $adapter = $this->supportAccessAdapterResolver->resolve();
        $query = User::query();
        $adapter->applyEligibleAgentsScope($query, $conversation);

        $candidates = $query
            ->where('status', 'active')
            ->select(['id', 'public_id', 'name', 'email', 'status'])
            ->orderBy('id')
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

            // Presence Check
            if (config('support_chat.routing.require_online_agent', true)) {
                $status = $this->presenceService->presenceStatus($candidate->id);
                if ($status !== 'online') {
                    continue;
                }
            }

            if (config('support_chat.routing.require_support_available', true)) {
                if (! $this->presenceService->isSupportAvailable((int) $candidate->id)) {
                    continue;
                }
            }

            $userId = (int) $candidate->id;
            $capacity = max(1, (int) ($capacities[$userId] ?? config('support_chat.routing.default_agent_capacity', 3)));
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

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    protected function activeLoadByUser(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $query = SupportConversation::query()
            ->whereIn('assigned_to', $userIds)
            ->whereNotIn('status', [SupportConversation::STATUS_RESOLVED, SupportConversation::STATUS_CLOSED])
            ->selectRaw('assigned_to as user_id, COUNT(*) as active_count')
            ->groupBy('assigned_to');

        if (Schema::hasColumn('support_conversations', 'chat_state')) {
            $query->where('chat_state', SupportConversation::CHAT_STATE_NEW);
        }

        return $query->pluck('active_count', 'user_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    protected function capacityByUser(SupportConversation $conversation, array $userIds): array
    {
        $defaultCapacity = max(1, (int) config('support_chat.routing.default_agent_capacity', 3));
        $capacities = array_fill_keys($userIds, $defaultCapacity);

        if (! $conversation->support_skill_id || $userIds === []) {
            return $capacities;
        }

        $memberships = SupportSkillMembership::query()
            ->where('support_skill_id', $conversation->support_skill_id)
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->get(['user_id', 'capacity']);

        foreach ($memberships as $membership) {
            $userId = (int) $membership->user_id;
            $capacity = (int) ($membership->capacity ?? $defaultCapacity);
            $capacities[$userId] = max(1, $capacity);
        }

        return $capacities;
    }

    protected function broadcastConversationChanged(SupportConversation $conversation): void
    {
        if (! (bool) config('support_chat.jobs.enabled', true)) {
            broadcast(new SupportConversationChanged($conversation, true));

            return;
        }

        $this->dispatchSupportBroadcastJob(
            syncJob: fn () => BroadcastSupportConversationChanged::dispatchSync($conversation->id, true),
            queuedJob: fn () => BroadcastSupportConversationChanged::dispatch($conversation->id, true),
            logContext: [
                'type' => 'conversation_changed',
                'conversation_id' => $conversation->public_id,
            ],
        );
    }

    protected function broadcastMessageCreated(SupportConversation $conversation, SupportMessage $message): void
    {
        if (! (bool) config('support_chat.jobs.enabled', true)) {
            broadcast(new SupportMessageCreated($conversation, $message, true));

            return;
        }

        $this->dispatchSupportBroadcastJob(
            syncJob: fn () => BroadcastSupportMessageCreated::dispatchSync($conversation->id, $message->id, true),
            queuedJob: fn () => BroadcastSupportMessageCreated::dispatch($conversation->id, $message->id, true),
            logContext: [
                'type' => 'message_created',
                'conversation_id' => $conversation->public_id,
                'message_id' => $message->public_id,
            ],
        );
    }

    /**
     * @param  callable():void  $syncJob
     * @param  callable():void  $queuedJob
     * @param  array<string, mixed>  $logContext
     */
    protected function dispatchSupportBroadcastJob(callable $syncJob, callable $queuedJob, array $logContext = []): void
    {
        $mode = (string) config('support_chat.jobs.broadcast_mode', 'sync_first');
        $isQueueFirst = strtolower($mode) === 'queue_first';

        if ($isQueueFirst) {
            try {
                $queuedJob();

                return;
            } catch (\Throwable $exception) {
                Log::warning('[SupportRouting] Queue-first broadcast dispatch failed, falling back to sync.', array_merge($logContext, [
                    'mode' => 'queue_first',
                    'error' => $exception->getMessage(),
                ]));
            }

            $syncJob();

            return;
        }

        try {
            $syncJob();
        } catch (\Throwable $exception) {
            Log::warning('[SupportRouting] Sync-first broadcast dispatch failed, falling back to queue.', array_merge($logContext, [
                'mode' => 'sync_first',
                'error' => $exception->getMessage(),
            ]));
            $queuedJob();
        }
    }
}
