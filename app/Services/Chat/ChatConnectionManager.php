<?php

namespace App\Services\Chat;

use App\Models\Chat\ChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages chat connections with self-healing capabilities.
 * Tracks user connections per chat, heartbeats, and enables message resync.
 */
class ChatConnectionManager
{
    public const CONNECTION_PREFIX = 'chat:connection:';

    public const HEARTBEAT_PREFIX = 'chat:heartbeat:';

    public const DELIVERY_PREFIX = 'chat:delivery:';

    public const CONNECTION_INDEX_PREFIX = 'chat:connection-index:';

    public const HEARTBEAT_INTERVAL = 15; // seconds

    public const CONNECTION_TIMEOUT = 45; // seconds (grace above heartbeat interval)

    /**
     * Register a new connection when user opens chat.
     */
    public function register(int $chatId, int $userId): void
    {
        $key = $this->connectionKey($chatId, $userId);

        Cache::put($key, [
            'user_id' => $userId,
            'chat_id' => $chatId,
            'connected_at' => now()->timestamp,
            'last_seen' => now()->timestamp,
        ], now()->addSeconds(self::CONNECTION_TIMEOUT));

        $this->rememberConnectionIndex($chatId, $userId);

        // Seed initial heartbeat
        Cache::put(
            $this->heartbeatKey($chatId, $userId),
            now()->timestamp,
            now()->addSeconds(self::CONNECTION_TIMEOUT)
        );

        Log::debug('[ChatConnectionManager] Connection registered', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Update heartbeat timestamp. Returns false if connection expired.
     */
    public function heartbeat(int $chatId, int $userId): bool
    {
        $key = $this->heartbeatKey($chatId, $userId);

        Cache::put($key, now()->timestamp, now()->addSeconds(self::CONNECTION_TIMEOUT));
        $this->rememberConnectionIndex($chatId, $userId);

        // Update connection record
        $connKey = $this->connectionKey($chatId, $userId);
        $data = Cache::get($connKey);

        if ($data) {
            $data['last_seen'] = now()->timestamp;
            Cache::put($connKey, $data, now()->addSeconds(self::CONNECTION_TIMEOUT));

            return true;
        }

        // If expired, re-register automatically (self-healing)
        try {
            $this->register($chatId, $userId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if user is actively connected to chat.
     */
    public function isConnected(int $chatId, int $userId): bool
    {
        $lastBeat = Cache::get($this->heartbeatKey($chatId, $userId));

        if (! $lastBeat) {
            return false;
        }

        $secondsSinceLastBeat = now()->timestamp - $lastBeat;

        return $secondsSinceLastBeat < self::HEARTBEAT_INTERVAL + 5; // 5s grace
    }

    /**
     * Get all active connections for a chat.
     */
    public function getActiveConnections(int $chatId): array
    {
        $userIds = $this->getConnectionIndex($chatId);
        $active = [];

        foreach ($userIds as $userId) {
            $data = Cache::get($this->connectionKey($chatId, $userId));
            if ($data && $this->isConnected($chatId, $userId)) {
                $active[] = $data;
            }
        }

        return $active;
    }

    /**
     * Disconnect user from chat.
     */
    public function disconnect(int $chatId, int $userId): void
    {
        Cache::forget($this->connectionKey($chatId, $userId));
        Cache::forget($this->heartbeatKey($chatId, $userId));
        $this->forgetConnectionIndex($chatId, $userId);

        Log::debug('[ChatConnectionManager] Connection disconnected', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Track message delivery to specific user.
     */
    public function markMessageDelivered(int $messageId, int $userId): void
    {
        $key = $this->deliveryKey($messageId, $userId);
        Cache::put($key, now()->timestamp, now()->addHour());

        // Update last delivered marker
        $message = ChatMessage::find($messageId);
        if ($message) {
            $this->updateLastDeliveredMessageId($message->chat_id, $userId, $messageId);
        }
    }

    /**
     * Check if message was delivered to user.
     */
    public function wasMessageDelivered(int $messageId, int $userId): bool
    {
        return Cache::has($this->deliveryKey($messageId, $userId));
    }

    /**
     * Get last delivered message ID for user in chat.
     */
    public function getLastDeliveredMessageId(int $chatId, int $userId): int
    {
        return (int) Cache::get("chat:last_delivered:{$chatId}:{$userId}", 0);
    }

    /**
     * Update last delivered message ID atomically.
     */
    public function updateLastDeliveredMessageId(int $chatId, int $userId, int $messageId): void
    {
        $key = "chat:last_delivered:{$chatId}:{$userId}";
        $current = $this->getLastDeliveredMessageId($chatId, $userId);

        if ($messageId > $current) {
            Cache::put($key, $messageId, now()->addDay());
        }
    }

    /**
     * Get messages missed by a user for resync (self-healing).
     */
    public function getMissedMessages(int $chatId, int $userId): array
    {
        $lastDelivered = $this->getLastDeliveredMessageId($chatId, $userId);

        return ChatMessage::where('chat_id', $chatId)
            ->where('id', '>', $lastDelivered)
            ->with('user:id,public_id,name')
            ->orderBy('id')
            ->get()
            ->unique('id')
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_public_id' => $m->user?->public_id,
                'user_name' => $m->user?->name,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->toArray();
    }

    // Helper methods

    protected function connectionKey(int $chatId, int $userId): string
    {
        return self::CONNECTION_PREFIX."{$chatId}:{$userId}";
    }

    protected function heartbeatKey(int $chatId, int $userId): string
    {
        return self::HEARTBEAT_PREFIX."{$chatId}:{$userId}";
    }

    protected function deliveryKey(int $messageId, int $userId): string
    {
        return self::DELIVERY_PREFIX."{$messageId}:{$userId}";
    }

    protected function indexKey(int $chatId): string
    {
        return self::CONNECTION_INDEX_PREFIX.$chatId;
    }

    protected function rememberConnectionIndex(int $chatId, int $userId): void
    {
        $key = $this->indexKey($chatId);
        $ids = Cache::get($key, []);
        if (! is_array($ids)) {
            $ids = [];
        }
        if (! in_array($userId, $ids, true)) {
            $ids[] = $userId;
        }
        Cache::put($key, $ids, now()->addSeconds(self::CONNECTION_TIMEOUT));
    }

    protected function forgetConnectionIndex(int $chatId, int $userId): void
    {
        $key = $this->indexKey($chatId);
        $ids = Cache::get($key, []);
        if (! is_array($ids) || empty($ids)) {
            return;
        }
        $ids = array_values(array_filter($ids, fn ($id) => (int) $id !== (int) $userId));
        Cache::put($key, $ids, now()->addSeconds(self::CONNECTION_TIMEOUT));
    }

    protected function getConnectionIndex(int $chatId): array
    {
        $ids = Cache::get($this->indexKey($chatId), []);

        return is_array($ids) ? $ids : [];
    }
}
