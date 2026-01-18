<?php

namespace App\Contracts;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatInvite;
use App\Models\User;

interface ChatServiceContract
{
    /**
     * Resolve a user by their public ID.
     */
    public function resolveUserByPublicId(string $publicId): ?User;

    /**
     * Resolve a chat by its public ID.
     */
    public function resolveChatByPublicId(string $publicId): ?Chat;

    /**
     * Resolve a chat invite by its public ID.
     */
    public function resolveInviteByPublicId(string $publicId): ?ChatInvite;

    /**
     * Check if a user is a participant of a chat.
     */
    public function isParticipant(User $user, Chat $chat): bool;

    /**
     * Check if a user can manage members of a chat.
     */
    public function canManageMembers(User $user, Chat $chat): bool;

    /**
     * Mark a chat as read for a user.
     */
    public function markRead(Chat $chat, User $user): void;

    /**
     * Create a new message in a chat.
     */
    public function sendMessage(Chat $chat, User $user, string $content, ?array $files = null, ?int $replyToId = null): ChatMessage;

    /**
     * Get the unread count for a user in a chat.
     */
    public function unreadCount(Chat $chat, User $user): int;

    /**
     * Get total unread count for a user across all chats.
     */
    public function totalUnreadCount(User $user): int;
}
