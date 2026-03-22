<?php

namespace App\Services\Chat;

use App\Events\Chat\UserPresenceChanged;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PresenceService
{
    /**
     * Redis key prefix for per-user presence markers.
     */
    public const KEY_PREFIX = 'user:last_active:';

    /**
     * Index key to track active user IDs for non-Redis cache drivers.
     */
    public const INDEX_KEY = 'presence:active_users';

    /**
     * How long until a user is marked away (seconds).
     */
    public const AWAY_AFTER_SECONDS = 180;

    /**
     * How long until a user is marked offline (seconds).
     */
    public const OFFLINE_AFTER_SECONDS = 600;

    /**
     * How long the presence index should persist (seconds).
     */
    public const HEARTBEAT_TTL_SECONDS = 600;

    /**
     * Record a heartbeat for the given user and broadcast when they come online or status changes.
     */
    public function heartbeat(User|int|null $user): void
    {
        $userModel = $this->resolveUser($user);

        if (! $userModel) {
            return;
        }

        $cacheKey = $this->keyFor($userModel->id);
        $current = Cache::get($cacheKey, []);
        $wasOnline = ! empty($current);
        $previousStatus = $current['status'] ?? null;

        // CRITICAL: Use database preference as fallback when cache expires
        $preferredStatus = $current['status'] ?? $userModel->presence_preference ?? 'online';
        $supportStatus = $current['support_status'] ?? $userModel->support_status ?? 'unavailable';
        $supportAvailable = (bool) ($current['support_available'] ?? $userModel->support_available ?? false);

        Cache::put($cacheKey, [
            'last_active' => now()->timestamp,
            'status' => $preferredStatus,
            'support_status' => $supportStatus,
            'support_available' => $supportAvailable,
        ], now()->addSeconds(self::OFFLINE_AFTER_SECONDS + 60));
        $this->rememberIndex($userModel->id);

        $newStatus = $this->presenceStatus($userModel->id);

        // Only broadcast when:
        // 1. User just came online (wasn't online before)
        // 2. Status actually changed (online -> away, busy -> online, etc.)
        if (! $wasOnline) {
            // User just came online, broadcast their current status
            try {
                UserPresenceChanged::dispatch($userModel, $newStatus, $supportAvailable, $supportStatus);
            } catch (\Throwable $e) {
                Log::warning('Presence broadcast failed: '.$e->getMessage());
            }
        } elseif ($previousStatus !== null && $previousStatus !== $newStatus) {
            // Status changed, broadcast the new status
            try {
                UserPresenceChanged::dispatch($userModel, $newStatus, $supportAvailable, $supportStatus);
            } catch (\Throwable $e) {
                Log::warning('Presence broadcast failed: '.$e->getMessage());
            }
        }
        // If status is the same, no broadcast needed
    }

    /**
     * Record a heartbeat for a specific meeting.
     */
    public function meetingHeartbeat(string $meetingPublicId, string $participantPublicId): void
    {
        $key = "meeting:presence:{$meetingPublicId}";

        // Add participant to the set of active users for this meeting
        Redis::zadd($key, now()->timestamp, $participantPublicId);

        // Set expiry for the whole set if it's new (e.g. 2 hours)
        Redis::expire($key, 7200);

        // Prune participants who haven't sent a heartbeat in 60 seconds
        $threshold = now()->timestamp - 60;
        Redis::zremrangebyscore($key, '-inf', $threshold);
    }

    /**
     * Get IDs of participants currently active in the meeting.
     */
    public function getActiveMeetingParticipantIds(string $meetingPublicId): array
    {
        $key = "meeting:presence:{$meetingPublicId}";

        // Prune stale before returning
        $threshold = now()->timestamp - 60;
        Redis::zremrangebyscore($key, '-inf', $threshold);

        return Redis::zrange($key, 0, -1) ?: [];
    }

    /**
     * Remove a user from the online pool and broadcast when they go offline.
     */
    public function markOffline(User|int|null $user): bool
    {
        $userModel = $this->resolveUser($user);

        if (! $userModel) {
            return false;
        }

        $cacheKey = $this->keyFor($userModel->id);
        $current = $this->presenceStatus($userModel->id);
        $wasOnline = Cache::forget($cacheKey);
        $this->forgetIndex($userModel->id);

        if ($wasOnline || $current !== 'offline') {
            UserPresenceChanged::dispatch($userModel, 'offline', false, 'offline');
        }

        return $wasOnline;
    }

    /**
     * Scan active users and remove those who have expired.
     * This is needed because Redis keys expire silently without broadcasting 'offline'.
     */
    public function pruneStaleUsers(): int
    {
        $ids = Cache::get(self::INDEX_KEY, []);
        if (! is_array($ids) || empty($ids)) {
            return 0;
        }

        $pruned = 0;
        foreach ($ids as $id) {
            // Check status. If cache key is gone/expired, this returns 'offline'.
            // Since the user is in our INDEX_KEY list, they *should* be online/away.
            // If they are 'offline', it means they timed out.
            if ($this->presenceStatus((int) $id) === 'offline') {
                if ($this->markOffline((int) $id)) {
                    $pruned++;
                    Log::info('Pruned stale user presence', ['user_id' => $id]);
                } else {
                    // markOffline returns false if user wasn't "online" in cache (already expired).
                    // But we still need to ensure they are removed from the index and broadcast sent if needed.
                    // markOffline handles index removal and broadcast.
                    // The return value indicates if cache *was* there.
                    // We count it as pruned if we cleaned them up.
                    $this->forgetIndex((int) $id);
                    UserPresenceChanged::dispatch(User::find($id), 'offline', false, 'offline');
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    public function setExplicitStatus(User|int|null $user, string $status): void
    {
        $userModel = $this->resolveUser($user);
        if (! $userModel) {
            return;
        }

        $status = match ($status) {
            'busy' => 'busy',
            'away' => 'away',
            'offline', 'invisible' => 'offline',
            default => 'online',
        };

        // Persist global preference
        $userModel->update(['presence_preference' => $status]);

        $cacheKey = $this->keyFor($userModel->id);
        $current = Cache::get($cacheKey, []);
        
        Cache::put($cacheKey, array_merge($current, [
            'last_active' => now()->timestamp,
            'status' => $status,
        ]), now()->addSeconds(self::OFFLINE_AFTER_SECONDS));

        $this->rememberIndex($userModel->id);

        UserPresenceChanged::dispatch($userModel, $status, (bool) ($current['support_available'] ?? $userModel->support_available), (string) ($current['support_status'] ?? $userModel->support_status));
    }

    public function setSupportStatus(User|int|null $user, string $status, bool $available): void
    {
        $userModel = $this->resolveUser($user);
        if (! $userModel) {
            return;
        }

        $status = match ($status) {
            'available' => 'available',
            'break' => 'break',
            'lunch' => 'lunch',
            'acw' => 'acw',
            'bio' => 'bio',
            'unavailable' => 'unavailable',
            default => 'unavailable',
        };

        // Persist support status
        $userModel->update([
            'support_status' => $status,
            'support_available' => $available,
        ]);

        $cacheKey = $this->keyFor($userModel->id);
        $current = Cache::get($cacheKey, []);

        Cache::put($cacheKey, array_merge($current, [
            'last_active' => now()->timestamp,
            'support_status' => $status,
            'support_available' => $available,
        ]), now()->addSeconds(self::OFFLINE_AFTER_SECONDS));

        $this->rememberIndex($userModel->id);

        // Broadcast with global status but updated support info
        $globalStatus = $current['status'] ?? $userModel->presence_preference ?? 'online';
        UserPresenceChanged::dispatch($userModel, $globalStatus, $available, $status);
    }

    public function setSupportAvailability(User|int|null $user, bool $available): void
    {
        $userModel = $this->resolveUser($user);
        if (! $userModel) {
            return;
        }

        $userModel->update(['support_available' => $available]);

        $cacheKey = $this->keyFor($userModel->id);
        $current = Cache::get($cacheKey, []);
        
        Cache::put($cacheKey, array_merge($current, [
            'last_active' => now()->timestamp,
            'support_available' => $available,
        ]), now()->addSeconds(self::OFFLINE_AFTER_SECONDS));

        $this->rememberIndex($userModel->id);

        $globalStatus = $current['status'] ?? $userModel->presence_preference ?? 'online';
        UserPresenceChanged::dispatch($userModel, $globalStatus, $available, (string) ($current['support_status'] ?? $userModel->support_status));
    }

    public function isSupportAvailable(int $userId): bool
    {
        $data = Cache::get($this->keyFor($userId));
        
        if ($this->presenceStatus($userId) === 'offline') {
            return false;
        }

        return (bool) ($data['support_available'] ?? false);
    }

    /**
     * Return the active user IDs.
     *
     * @return array<int>
     */
    public function getActiveUserIds(): array
    {
        $rawKeys = $this->getActiveUserKeys();
        $fullPrefix = $this->getFullCachePrefix().self::KEY_PREFIX;

        $fromKeys = collect($rawKeys)
            ->filter(fn (string $key) => str_contains($key, $fullPrefix))
            ->map(fn (string $key) => (int) Str::after($key, $fullPrefix))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($fromKeys)) {
            return $fromKeys;
        }

        // Fallback for cache drivers that cannot list keys
        $index = Cache::get(self::INDEX_KEY, []);

        return is_array($index)
            ? collect($index)->filter()->unique()->values()->all()
            : [];
    }

    /**
     * Return active user models.
     */
    public function getActiveUsers(): Collection
    {
        $ids = $this->getActiveUserIds();

        if (empty($ids)) {
            return collect();
        }

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Count currently active users.
     */
    public function countActiveUsers(): int
    {
        return count($this->getActiveUserIds());
    }

    /**
     * Check if an individual user is marked online.
     */
    public function isUserActive(int $userId): bool
    {
        return $this->presenceStatus($userId) !== 'offline';
    }

    /**
     * Build the Redis key for the given user.
     */
    protected function keyFor(int $userId): string
    {
        return self::KEY_PREFIX.$userId;
    }

    /**
     * Determine presence status: online/away/offline based on last activity.
     */
    public function presenceStatus(int $userId): string
    {
        $data = Cache::get($this->keyFor($userId));
        if (! is_array($data) || empty($data['last_active'])) {
            return 'offline';
        }

        $last = (int) $data['last_active'];
        $age = now()->timestamp - $last;

        if ($age >= self::OFFLINE_AFTER_SECONDS) {
            // Cache::forget($this->keyFor($userId));
            // $this->forgetIndex($userId);

            return 'offline';
        }

        if ($age >= self::AWAY_AFTER_SECONDS) {
            if (!in_array($data['status'] ?? null, ['away', 'busy', 'break', 'lunch', 'meeting', 'on_call', 'acw', 'bio'])) {
                Cache::put($this->keyFor($userId), [
                    'last_active' => $last,
                    'status' => 'away',
                ], now()->addSeconds(self::OFFLINE_AFTER_SECONDS - $age));
            }

            return in_array($data['status'] ?? null, ['busy', 'break', 'lunch', 'meeting', 'on_call', 'acw', 'bio']) ? $data['status'] : 'away';
        }

        return in_array($data['status'] ?? null, ['busy', 'break', 'lunch', 'meeting', 'on_call', 'acw', 'bio']) ? $data['status'] : 'online';
    }

    protected function rememberIndex(int $userId): void
    {
        $ids = Cache::get(self::INDEX_KEY, []);
        if (! is_array($ids)) {
            $ids = [];
        }
        if (! in_array($userId, $ids, true)) {
            $ids[] = $userId;
        }

        Cache::put(self::INDEX_KEY, $ids, now()->addSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    protected function forgetIndex(int $userId): void
    {
        $ids = Cache::get(self::INDEX_KEY, []);
        if (! is_array($ids) || empty($ids)) {
            return;
        }

        $ids = array_values(array_filter($ids, fn ($id) => (int) $id !== $userId));
        Cache::put(self::INDEX_KEY, $ids, now()->addSeconds(self::HEARTBEAT_TTL_SECONDS));
    }

    /**
     * Grab all active-user keys from Redis using SCAN (non-blocking).
     *
     * SECURITY: Uses SCAN instead of KEYS to avoid blocking Redis.
     * KEYS command can freeze Redis for seconds with many online users.
     *
     * @return array<int, string>
     */
    protected function getActiveUserKeys(): array
    {
        $prefix = $this->getFullCachePrefix();
        $pattern = $prefix.self::KEY_PREFIX.'*';

        try {
            $redis = Redis::connection('cache');
        } catch (\Throwable $e) {
            Log::debug('PresenceService: redis unavailable, falling back to cache index', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
        $keys = [];
        $cursor = '0';
        $iterations = 0;
        $maxIterations = 1000; // Safety limit to prevent infinite loops

        // Use SCAN for non-blocking iteration
        do {
            $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);

            if (! is_array($result) || count($result) !== 2) {
                break;
            }

            [$cursor, $batch] = $result;
            $keys = array_merge($keys, $batch ?: []);
            $iterations++;

            if ($iterations >= $maxIterations) {
                Log::warning('PresenceService::getActiveUserKeys exceeded max iterations', [
                    'iterations' => $iterations,
                    'pattern' => $pattern,
                    'keys_found' => count($keys),
                ]);
                break;
            }
        } while ((string) $cursor !== '0');

        return $keys;
    }

    /**
     * Extract the configured cache prefix for the active store.
     */
    protected function getFullCachePrefix(): string
    {
        $cacheStoreName = config('cache.default');
        $prefix = config("cache.stores.{$cacheStoreName}.prefix") ?? '';

        if ($prefix !== '' && ! str_ends_with($prefix, ':')) {
            $prefix .= ':';
        }

        return $prefix;
    }

    /**
     * Safely resolve a User model from a mixed input.
     */
    protected function resolveUser(User|int|null $user): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        if (is_int($user)) {
            return User::find($user);
        }

        return null;
    }
}
