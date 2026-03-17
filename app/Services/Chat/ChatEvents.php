<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatBadgeUpdated;
use App\Events\Chat\InviteAccepted;
use App\Events\Chat\InviteSent;
use App\Events\Chat\MessageConfirmed;
use App\Events\Chat\MessageCreated;
use App\Events\Chat\MessageRead;
use App\Events\Chat\MessageUpdated;
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
     * Broadcast message metadata/content updates to chat participants.
     */
    public static function messageUpdated(ChatMessage $message, string $chatType = 'dm'): void
    {
        broadcast(new MessageUpdated($message))->toOthers();
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
    public static function messageRead(string $chatId, string $lastReadMessageId, User $user, string $chatType = 'dm'): void
    {
        broadcast(new MessageRead($chatId, $lastReadMessageId, $user, $chatType))->toOthers();
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

    /**
     * Broadcast invite sent.
     */
    public static function inviteSent(array $inviteData): void
    {
        broadcast(new InviteSent($inviteData));
    }

    /**
     * Broadcast invite accepted.
     */
    public static function inviteAccepted(array $inviteData, array $chatData): void
    {
        broadcast(new InviteAccepted($inviteData, $chatData));
    }
}
