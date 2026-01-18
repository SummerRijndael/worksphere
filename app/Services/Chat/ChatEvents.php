<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatBadgeUpdated;
use App\Events\Chat\MessageConfirmed;
use App\Events\Chat\MessageCreated;
use App\Events\Chat\MessageRead;
use App\Events\Chat\UserTyping;
use App\Models\Chat\ChatMessage;
use App\Models\User;

/**
 * Helper service for broadcasting chat events.
 */
class ChatEvents
{
    /**
     * Broadcast new message to chat participants.
     */
    public static function messageCreated(ChatMessage $message, string $chatType = 'dm'): void
    {
        broadcast(new MessageCreated($message))->toOthers();
    }

    /**
     * Confirm message was saved to sender (replaces optimistic temp message).
     */
    public static function messageConfirmed(ChatMessage $message, string $tempId, User $user): void
    {
        event(new MessageConfirmed($message, $tempId, $user->public_id));
    }

    /**
     * Broadcast read receipt to chat participants.
     */
    public static function messageRead(int $chatId, int $lastReadMessageId, User $user): void
    {
        broadcast(new MessageRead($chatId, $lastReadMessageId, $user))->toOthers();
    }

    /**
     * Broadcast unread badge count update to user.
     */
    public static function unreadBadge(User $user, int $count): void
    {
        broadcast(new ChatBadgeUpdated($user->id, $count));
    }

    /**
     * Broadcast typing indicator.
     */
    public static function typing(int $chatId, User $user, string $chatType = 'dm'): void
    {
        broadcast(new UserTyping($chatId, $user, $chatType))->toOthers();
    }
}
