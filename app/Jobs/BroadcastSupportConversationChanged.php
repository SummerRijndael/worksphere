<?php

namespace App\Jobs;

use App\Events\Support\SupportConversationChanged;
use App\Models\SupportConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastSupportConversationChanged implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $conversationId,
        protected bool $broadcastToCustomer = true
    ) {
        $this->onQueue((string) config('support_chat.jobs.queue', 'chats'));
    }

    public function handle(): void
    {
        $conversation = SupportConversation::query()
            ->with('assignee:id,public_id')
            ->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        broadcast(new SupportConversationChanged($conversation, $this->broadcastToCustomer));
    }
}

