<?php

namespace App\Jobs;

use App\Events\Support\SupportMessageCreated;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastSupportMessageCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $conversationId,
        protected int $messageId,
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

        $message = SupportMessage::query()
            ->with(['sender:id,public_id,name,email', 'media'])
            ->find($this->messageId);

        if (! $message) {
            return;
        }

        broadcast(new SupportMessageCreated($conversation, $message, $this->broadcastToCustomer));
    }
}

