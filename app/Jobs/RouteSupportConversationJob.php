<?php

namespace App\Jobs;

use App\Contracts\SupportRoutingServiceContract;
use App\Models\SupportRoutingQueueEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RouteSupportConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        protected int $queueEntryId
    ) {
        $this->onQueue((string) config('support_chat.routing.queue', 'chats'));
    }

    public function handle(SupportRoutingServiceContract $routingService): void
    {
        $routingService->processQueueEntry($this->queueEntryId);
    }
}

