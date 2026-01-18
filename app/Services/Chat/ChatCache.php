<?php

namespace App\Services\Chat;

use App\Models\Chat\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caching service for chat-related data.
 * Handles unread counts, message caching, and chat list caching.
 */
class ChatCache
{
    public const UNREAD_PREFIX = 'user:unread_messages:';

    public const UNREAD_TTL = 300; // 5 minutes

    public const MSG_PREFIX = 'chat:messages:';

    public const MSG_TTL = 60; // 1 minute

    public const CHATLIST_PREFIX = 'chat:list:user:';

    public const CHATLIST_TTL = 60; // 1 minute

    // ----------------------------
    // UNREAD COUNTS
    // ----------------------------

    public static function unreadKey(int $userId): string
    {
        return self::UNREAD_PREFIX.$userId;
    }

    public static function forget(int $userId): void
    {
        Cache::forget(self::unreadKey($userId));
    }

    public static function put(int $userId, int $count): void
    {
        Cache::put(self::unreadKey($userId), $count, now()->addSeconds(self::UNREAD_TTL));
    }

    public static function get(int $userId): int
    {
        return Cache::remember(
            self::unreadKey($userId),
            now()->addSeconds(self::UNREAD_TTL),
            fn () => self::countUnread($userId)
        );
    }

    public static function countUnread(int $userId): int
    {
        return (int) DB::table('chat_messages AS m')
            ->join('chat_participants AS p', function ($join) use ($userId) {
                $join->on('p.chat_id', '=', 'm.chat_id')
                    ->where('p.user_id', $userId);
            })
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->where('m.user_id', '!=', $userId)
            ->count();
    }

    // ----------------------------
    // PER-CHAT MESSAGE CACHING
    // ----------------------------

    public static function msgKey(int $chatId, int $beforeId = 0, int $limit = 15): string
    {
        return self::MSG_PREFIX."{$chatId}:{$beforeId}:{$limit}";
    }

    protected static function msgIndexKey(int $chatId): string
    {
        return self::MSG_PREFIX."{$chatId}:index";
    }

    public static function getMessages(int $chatId, int $beforeId, int $limit): ?array
    {
        return Cache::get(self::msgKey($chatId, $beforeId, $limit));
    }

    public static function putMessages(int $chatId, int $beforeId, int $limit, array $data): void
    {
        $key = self::msgKey($chatId, $beforeId, $limit);

        // Try cache tags for better invalidation (Redis/Memcached)
        try {
            if (method_exists(Cache::store(), 'tags')) {
                Cache::tags(["chat:{$chatId}"])->put(
                    $key,
                    $data,
                    now()->addSeconds(self::MSG_TTL)
                );

                return;
            }
        } catch (\Exception $e) {
            // Fallback to regular cache
        }

        // Regular cache without tags
        Cache::put($key, $data, now()->addSeconds(self::MSG_TTL));
        self::rememberMessageKey($chatId, $key);
    }

    protected static function rememberMessageKey(int $chatId, string $key): void
    {
        $indexKey = self::msgIndexKey($chatId);
        $keys = Cache::get($indexKey, []);
        if (! is_array($keys)) {
            $keys = [];
        }

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
        }

        Cache::put($indexKey, $keys, now()->addSeconds(self::MSG_TTL));
    }

    public static function flushMessages(int $chatId): void
    {
        // Try cache tags first
        try {
            if (method_exists(Cache::store(), 'tags')) {
                Cache::tags(["chat:{$chatId}"])->flush();

                return;
            }
        } catch (\Exception $e) {
            // Fallback to manual deletion
        }

        // Manual deletion for drivers without tag support
        $indexKey = self::msgIndexKey($chatId);
        $keys = Cache::get($indexKey, []);
        if (! is_array($keys)) {
            $keys = [];
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget($indexKey);
    }

    // ----------------------------
    // HELPER METHODS
    // ----------------------------

    public static function chatIsUnread(Chat $chat, User $user): bool
    {
        $participant = $chat->participants
            ->firstWhere('id', $user->id)?->pivot;

        if (! $participant) {
            return false;
        }

        $last = $chat->lastMessage?->id ?? 0;
        $read = $participant->last_read_message_id ?? 0;

        return $last > $read;
    }

    // ----------------------------
    // CHAT LIST CACHE
    // ----------------------------

    public static function chatListKey(int $userId): string
    {
        return self::CHATLIST_PREFIX.$userId;
    }

    public static function getChatList(int $userId): ?array
    {
        return Cache::get(self::chatListKey($userId));
    }

    public static function putChatList(int $userId, array $data): void
    {
        Cache::put(
            self::chatListKey($userId),
            $data,
            now()->addSeconds(self::CHATLIST_TTL)
        );
    }

    public static function flushChatList(int $userId): void
    {
        Cache::forget(self::chatListKey($userId));
    }
}
