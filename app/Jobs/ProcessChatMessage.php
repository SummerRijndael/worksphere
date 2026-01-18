<?php

namespace App\Jobs;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatCache;
use App\Services\Chat\ChatEngine;
use App\Services\Chat\ChatEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job to process chat messages asynchronously.
 * Handles broadcasting, unread count updates, and badge notifications.
 */
class ProcessChatMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 1;

    public function __construct(
        protected int $messageId,
        protected int $chatId,
        protected string $senderPublicId,
        protected string $tempId
    ) {
        // High priority queue for real-time responsiveness
        $this->onQueue('chats');
    }

    public function handle(ChatEngine $chatEngine): void
    {
        $message = ChatMessage::with([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
        ])->find($this->messageId);

        if (! $message) {
            Log::error('[ProcessChatMessage] Message not found', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $chat = Chat::with('participants')->find($this->chatId);
        if (! $chat) {
            Log::error('[ProcessChatMessage] Chat not found', [
                'chat_id' => $this->chatId,
            ]);

            return;
        }

        $sender = User::where('public_id', $this->senderPublicId)->first();
        if (! $sender) {
            Log::error('[ProcessChatMessage] Sender not found', [
                'sender_public_id' => $this->senderPublicId,
            ]);

            return;
        }

        // Normalize message for broadcast
        $normalized = $chatEngine->normalizeOne($message);

        Log::info('[ProcessChatMessage] Broadcasting message', [
            'message_id' => $message->id,
            'chat_id' => $chat->id,
        ]);

        // Broadcast to recipient(s)
        ChatEvents::messageCreated($message, $chat->type ?? 'dm');

        // Confirm to sender (clear optimistic ID)
        ChatEvents::messageConfirmed($message, $this->tempId, $sender);

        // Update unread counts and badges for recipients
        foreach ($chat->participants as $participant) {
            if ($participant->id === $sender->id) {
                continue;
            }

            // Invalidate recipient's chat list cache so the new message shows up in list
            ChatCache::flushChatList($participant->id);

            // Invalidate message cache for this chat (so new fetch gets latest)
            ChatCache::flushMessages($chat->id);

            // Calculate unread count
            $unreadCount = ChatEngine::unreadFor($participant);

            // Update unread cache
            ChatCache::put($participant->id, $unreadCount);

            // Broadcast badge update
            ChatEvents::unreadBadge($participant, $unreadCount);

            Log::debug('[ProcessChatMessage] Badge updated for recipient', [
                'recipient_id' => $participant->id,
                'unread_count' => $unreadCount,
            ]);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessChatMessage] Job failed', [
            'message_id' => $this->messageId,
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
