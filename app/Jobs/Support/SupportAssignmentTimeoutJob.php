<?php

namespace App\Jobs\Support;

use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\SupportConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SupportAssignmentTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $conversationId,
        protected int $agentId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SupportConversationService $service): void
    {
        $conversation = SupportConversation::find($this->conversationId, ['*']);
        
        if (! $conversation) {
            return;
        }

        // Only process if still pending and assigned to the same agent
        if (
            $conversation->status === SupportConversation::STATUS_PENDING_ACCEPTANCE &&
            (int) $conversation->assigned_to === $this->agentId
        ) {
            $agent = User::find($this->agentId, ['*']);
            if ($agent) {
                // Trigger rejection via the service to handle re-queuing logic
                $service->rejectAssignment($conversation, $agent, 'Assignment timed out.');
            }
        }
    }
}
