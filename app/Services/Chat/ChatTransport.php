<?php

namespace App\Services\Chat;

use App\Jobs\ProcessChatMessage;
use App\Models\Chat\ChatMessage;

/**
 * Service for transporting chat messages through the queue system.
 */
class ChatTransport
{
    /**
     * Queue a message for async broadcast.
     */
    public static function queueBroadcast(ChatMessage $message, string $tempId): void
    {
        ProcessChatMessage::dispatch(
            $message->id,
            $message->chat_id,
            $message->user->public_id,
            $tempId
        );
    }

    /**
     * Queue a message broadcast with a delay (for rate limiting scenarios).
     */
    public static function queueBroadcastWithDelay(ChatMessage $message, string $tempId, int $seconds): void
    {
        ProcessChatMessage::dispatch(
            $message->id,
            $message->chat_id,
            $message->user->public_id,
            $tempId
        )->delay(now()->addSeconds($seconds));
    }
}
