<?php

namespace App\Contracts;

use App\Models\SupportConversation;
use App\Models\SupportRoutingQueueEntry;

interface SupportRoutingServiceContract
{
    public function enqueueConversation(
        SupportConversation $conversation,
        string $reason = 'conversation_opened',
        bool $force = false
    ): ?SupportRoutingQueueEntry;

    public function cancelConversationQueue(
        SupportConversation $conversation,
        string $reason = 'conversation_closed'
    ): void;

    public function markConversationAssigned(
        SupportConversation $conversation,
        ?int $agentId = null,
        string $reason = 'manual_assignment'
    ): void;

    public function dispatchDueEntries(?int $limit = null): int;

    public function triggerImmediateRouting(): void;

    public function processQueueEntry(int $queueEntryId): void;
}
