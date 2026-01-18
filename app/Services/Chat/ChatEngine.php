<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatBadgeUpdated;
use App\Events\Chat\MessageCreated;
use App\Events\Chat\MessageRead;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core engine for handling chat logic, message persistence, and normalization.
 * Serves as the domain service layer for chat operations.
 */
class ChatEngine
{
    public const MAX_MESSAGE_LENGTH = 4000;

    protected Chat $chat;

    protected User $user;

    public function __construct(Chat $chat, User $user)
    {
        $this->chat = $chat;
        $this->user = $user;
    }

    /**
     * Create a new ChatEngine instance for a chat and user.
     */
    public static function for(Chat $chat, User $user): self
    {
        return new self($chat, $user);
    }

    /**
     * Load the latest messages for the chat.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadMessages(int $limit = 15): array
    {
        $messages = $this->normalize(
            $this->queryMessages()
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
        );

        return $messages;
    }

    /**
     * Load older messages before a given ID (pagination).
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadMore(int $beforeId, int $limit = 25): array
    {
        $messages = $this->normalize(
            $this->queryMessages()
                ->where('id', '<', $beforeId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
        );

        return $messages;
    }

    /**
     * Mark the chat as read for the current user.
     */
    public function markRead(): void
    {
        DB::transaction(function () {
            $latest = $this->chat->messages()->latest('id')->first();

            if (! $latest) {
                return;
            }

            $this->chat->participants()
                ->updateExistingPivot($this->user->id, [
                    'last_read_message_id' => $latest->id,
                ]);

            // Broadcast read receipt to OTHER participants (sender sees "Seen")
            // In a DM, this notifies the other person their message was read
            // In a group, this notifies all others about this user reading
            $otherParticipants = $this->chat->participants
                ->where('id', '!=', $this->user->id);

            foreach ($otherParticipants as $participant) {
                broadcast(new MessageRead(
                    chatPublicId: $this->chat->public_id,
                    lastReadMessageId: $latest->public_id,
                    readerPublicId: $this->user->public_id,
                    recipientPublicId: $participant->public_id, // Send to this user
                ));
            }

            // Update badge count for the reader (self)
            broadcast(new ChatBadgeUpdated(
                userId: $this->user->id,
                unreadCount: self::unreadFor($this->user),
            ))->toOthers();
        });
    }

    /**
     * Create a new message.
     */
    public function createMessage(string $content, ?int $replyToMessageId = null, ?array $metadata = null): ChatMessage
    {
        // $content = $this->sanitizeContent($content); // Removed: Store raw text, frontend handles escaping

        return ChatMessage::create([
            'chat_id' => $this->chat->id,
            'user_id' => $this->user->id,
            'content' => $content,
            'reply_to_message_id' => $replyToMessageId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Send a message with optional file attachments.
     *
     * @param  array<\Illuminate\Http\UploadedFile>|null  $files
     */
    public function send(string $content, ?array $files = null, ?int $replyToMessageId = null, ?array $metadata = null): ChatMessage
    {
        return DB::transaction(function () use ($content, $files, $replyToMessageId, $metadata) {
            $files = $files ?? [];

            // Validate files if provided
            if (! empty($files)) {
                app(ChatMediaService::class)->validateFiles($files, $this->chat);
            }

            // Create the message
            $message = $this->createMessage($content, $replyToMessageId, $metadata);

            // Attach files if provided
            if (! empty($files)) {
                app(ChatMediaService::class)->attachFilesToMessage($message, $files);
            }

            // Refresh with relationships
            $message = $message->fresh([
                'user:id,public_id,name,email', // Added email for notification
                'media',
                'replyTo.user:id,public_id,name',
                'chat.participants', // Need participants for resolution
            ]);

            // Broadcast the new message
            broadcast(new MessageCreated($message))->toOthers();

            // Process Mentions
            $this->processMentions($message);

            return $message;
        });
    }

    /**
     * Parse and notify mentioned users.
     */
    protected function processMentions(ChatMessage $message): void
    {
        // Regex to find @Name patterns
        // We look for @ followed by word characters, allowing for some common name characters
        // This is simple parsing; for robust production we'd use a structured text parser or UUIDs
        if (preg_match_all('/@([a-zA-Z0-9_\-\.]+)/', $message->content, $matches)) {
            $mentionedNames = array_unique($matches[1]);

            if (empty($mentionedNames)) {
                return;
            }

            // Resolve participants matching names
            // We search across the chat participants
            $participants = $this->chat->participants;

            $mentionedUsers = $participants->filter(function ($user) use ($mentionedNames) {
                // exclude self
                if ($user->id === $this->user->id) {
                    return false;
                }

                // Match name or public_id or email prefix
                // This logic MUST match what the frontend Tribute.js uses/displays
                // Tribute currently searches by name.
                // We'll try loose matching on name.
                foreach ($mentionedNames as $name) {
                    if (str_contains(strtolower($user->name), strtolower($name))) {
                        return true;
                    }
                }

                return false;
            });

            foreach ($mentionedUsers as $user) {
                $user->notify(new \App\Notifications\ChatMentionNotification($message, $this->user));
            }
        }
    }

    /**
     * Create a system message.
     */
    public function createSystemMessage(Chat $chat, string $content, int $userId): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $userId,
            'content' => $content,
            'type' => 'system',
        ]);

        // Broadcast
        broadcast(new MessageCreated($message));

        return $message;
    }

    /**
     * Get the last message ID seen by the recipient.
     */
    public function recipientLastSeenMessageId(): int
    {
        $recipient = $this->getRecipient();
        if (! $recipient) {
            return 0;
        }

        $record = DB::table('chat_participants')
            ->where('chat_id', $this->chat->id)
            ->where('user_id', $recipient->id)
            ->first();

        return $record->last_read_message_id ?? 0;
    }

    /**
     * Get the total message count for this chat.
     */
    public function messageCount(): int
    {
        return $this->queryMessages()->count();
    }

    /**
     * Count unread messages for the current user in this chat.
     */
    public function unreadCount(): int
    {
        $userId = $this->user->id;

        return DB::table('chat_messages AS m')
            ->join('chat_participants AS p', function ($join) use ($userId) {
                $join->on('p.chat_id', '=', 'm.chat_id')
                    ->where('p.user_id', $userId);
            })
            ->where('m.chat_id', $this->chat->id)
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->where('m.user_id', '!=', $userId)
            ->count();
    }

    /**
     * Count total unread messages across all chats for a user.
     */
    public static function unreadFor(User $user): int
    {
        return DB::table('chat_messages AS m')
            ->join('chat_participants AS p', function ($join) use ($user) {
                $join->on('p.chat_id', '=', 'm.chat_id')
                    ->where('p.user_id', $user->id);
            })
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->where('m.user_id', '!=', $user->id)
            ->count();
    }

    /**
     * Broadcast typing indicator.
     */
    public function broadcastTyping(): void
    {
        // Typing events are handled via Echo presence channels
        // This is a placeholder for server-side typing logic if needed
    }

    /**
     * Format a single message for API response (instance method).
     *
     * @return array<string, mixed>
     */
    public function formatSingleMessage(ChatMessage $message): array
    {
        $seenThreshold = $this->recipientLastSeenMessageId();

        return self::normalizeOne($message, $seenThreshold);
    }

    /**
     * Get the recipient in a DM chat.
     */
    protected function getRecipient(): ?User
    {
        return $this->chat->participants
            ->where('id', '!=', $this->user->id)
            ->first();
    }

    /**
     * Build the base query for messages with eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    protected function queryMessages()
    {
        return ChatMessage::with([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
        ])->where('chat_id', $this->chat->id);
    }

    /**
     * Normalize a single message for API response.
     * Maps internal IDs to public IDs and determines 'seen' status based on read receipts.
     *
     * @return array<string, mixed>
     */
    public static function normalizeOne(ChatMessage $message, ?int $seenThreshold = null, ?int $ownSeenId = null): array
    {
        $isSeen = false;
        $authorId = $message->user->id ?? $message->user_id;

        if ($seenThreshold && $authorId && $message->user_id === $authorId) {
            $isSeen = $ownSeenId ? $message->id === $ownSeenId : $message->id <= $seenThreshold;
        }

        return [
            'id' => $message->public_id, // Map Public ID to id
            'type' => $message->type,
            'metadata' => $message->metadata, // Expose metadata
            'user_public_id' => $message->user->public_id ?? null,
            'user_name' => $message->user->name ?? ($message->type === 'system' ? 'System' : 'Deactivated User'),
            'user_avatar' => $message->user->avatar_url ?? null,
            'content' => $message->content ?? '',
            'created_at' => $message->created_at->toIso8601String(),
            'is_seen' => $isSeen,
            'seen' => $isSeen,
            'seen_at' => $isSeen ? ($message->updated_at?->toIso8601String() ?? null) : null,
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->public_id,
                'user_public_id' => $message->replyTo->user?->public_id,
                'user_name' => $message->replyTo->user?->name,
                'content' => Str::limit($message->replyTo->content ?? '', 100),
                'has_media' => $message->replyTo->media->isNotEmpty(),
            ] : null,
            'attachments' => $message->media->map(function ($media) {
                $isImage = str_starts_with($media->mime_type, 'image/');
                $viewUrl = route('chat.media.view', ['mediaId' => $media->id], false);
                $downloadUrl = route('chat.media.download', ['mediaId' => $media->id], false);

                $optimizedUrl = $isImage && $media->hasGeneratedConversion('web')
                    ? route('chat.media.conversion', [
                        'mediaId' => $media->id,
                        'conversion' => 'web',
                    ], false)
                    : $viewUrl;

                return [
                    'id' => $media->id,
                    'name' => Str::limit($media->getCustomProperty('original_filename') ?? $media->file_name, 40),
                    'size' => $media->size,
                    'mime_type' => $media->mime_type,
                    'is_image' => $isImage,
                    'url' => $optimizedUrl,
                    'download_url' => $downloadUrl,
                    'thumb_url' => $isImage && $media->hasGeneratedConversion('thumb')
                        ? route('chat.media.conversion', [
                            'mediaId' => $media->id,
                            'conversion' => 'thumb',
                        ], false)
                        : null,
                ];
            })->toArray(),
        ];
    }

    /**
     * Normalize a collection of messages for API response.
     *
     * @param  Collection<int, ChatMessage>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalize(Collection $items): array
    {
        $seenThreshold = $this->chat->participants
            ? $this->chat->participants
                ->where('id', '!=', $this->user->id)
                ->max('pivot.last_read_message_id') ?? 0
            : 0;

        $ownSeenId = 0;
        if ($seenThreshold) {
            $ownSeenId = $items
                ->where('user_id', $this->user->id)
                ->where('id', '<=', $seenThreshold)
                ->max('id') ?? 0;
        }

        return $items
            ->reverse()
            ->values()
            ->map(fn ($m) => self::normalizeOne($m, $seenThreshold, $ownSeenId))
            ->unique('id')
            ->values()
            ->toArray();
    }

    /**
     * Sanitize message content.
     *
     * @deprecated No longer used for DB storage. Frontend handles XSS.
     */
    public static function sanitize(string $content): string
    {
        return htmlspecialchars(trim($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Truncate message content for storage.
     */
    protected function sanitizeContent(string $content): string
    {
        // Only truncate, do not htmlspecialchars
        $clean = trim($content);

        if (Str::length($clean) > self::MAX_MESSAGE_LENGTH) {
            $clean = Str::limit($clean, self::MAX_MESSAGE_LENGTH, '');
        }

        return $clean;
    }
}
