<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages WebSocket connections with abuse protection and self-healing capabilities.
 */
class ConnectionManagerService
{
    /**
     * Maximum concurrent connections per user.
     */
    public const MAX_CONNECTIONS_PER_USER = 5;

    /**
     * Maximum connection attempts per minute.
     */
    public const MAX_ATTEMPTS_PER_MINUTE = 10;

    /**
     * Connection attempt window in seconds.
     */
    public const ATTEMPT_WINDOW_SECONDS = 60;

    /**
     * Connection health check interval in seconds.
     */
    public const HEALTH_CHECK_INTERVAL = 30;

    /**
     * Cache key prefixes.
     */
    protected const KEY_CONNECTIONS = 'ws:connections:';

    protected const KEY_ATTEMPTS = 'ws:attempts:';

    protected const KEY_BLOCKED = 'ws:blocked:';

    /**
     * Check if a user can establish a new connection.
     */
    public function canConnect(string $userPublicId): array
    {
        // Check if user is blocked
        if ($this->isBlocked($userPublicId)) {
            return [
                'allowed' => false,
                'reason' => 'temporarily_blocked',
                'retry_after' => $this->getBlockedUntil($userPublicId),
            ];
        }

        // Check connection limit
        $connectionCount = $this->getConnectionCount($userPublicId);
        if ($connectionCount >= self::MAX_CONNECTIONS_PER_USER) {
            return [
                'allowed' => false,
                'reason' => 'max_connections_reached',
                'current' => $connectionCount,
                'max' => self::MAX_CONNECTIONS_PER_USER,
            ];
        }

        // Check rate limit
        $attempts = $this->getAttemptCount($userPublicId);
        if ($attempts >= self::MAX_ATTEMPTS_PER_MINUTE) {
            $this->blockUser($userPublicId, 60); // Block for 1 minute

            return [
                'allowed' => false,
                'reason' => 'rate_limited',
                'retry_after' => 60,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Register a new connection for a user.
     */
    public function registerConnection(string $userPublicId, string $connectionId): bool
    {
        $check = $this->canConnect($userPublicId);
        if (! $check['allowed']) {
            Log::warning('WebSocket connection denied', [
                'public_id' => $userPublicId,
                'reason' => $check['reason'],
            ]);

            return false;
        }

        $this->incrementAttempts($userPublicId);

        $connections = $this->getConnections($userPublicId);
        $connections[$connectionId] = [
            'connected_at' => now()->timestamp,
            'last_heartbeat' => now()->timestamp,
        ];

        Cache::put(
            self::KEY_CONNECTIONS.$userPublicId,
            $connections,
            now()->addHours(24)
        );

        Log::debug('WebSocket connection registered', [
            'public_id' => $userPublicId,
            'connection_id' => $connectionId,
            'total_connections' => count($connections),
        ]);

        return true;
    }

    /**
     * Unregister a connection for a user.
     */
    public function unregisterConnection(string $userPublicId, string $connectionId): void
    {
        $connections = $this->getConnections($userPublicId);
        unset($connections[$connectionId]);

        if (empty($connections)) {
            Cache::forget(self::KEY_CONNECTIONS.$userPublicId);
        } else {
            Cache::put(
                self::KEY_CONNECTIONS.$userPublicId,
                $connections,
                now()->addHours(24)
            );
        }

        Log::debug('WebSocket connection unregistered', [
            'public_id' => $userPublicId,
            'connection_id' => $connectionId,
            'remaining_connections' => count($connections),
        ]);
    }

    /**
     * Update heartbeat for a connection.
     */
    public function heartbeat(string $userPublicId, string $connectionId): void
    {
        $connections = $this->getConnections($userPublicId);

        if (isset($connections[$connectionId])) {
            $connections[$connectionId]['last_heartbeat'] = now()->timestamp;
            Cache::put(
                self::KEY_CONNECTIONS.$userPublicId,
                $connections,
                now()->addHours(24)
            );
        }
    }

    /**
     * Prune stale connections that haven't sent a heartbeat.
     */
    public function pruneStaleConnections(string $userPublicId): int
    {
        $connections = $this->getConnections($userPublicId);
        $threshold = now()->timestamp - (self::HEALTH_CHECK_INTERVAL * 3);
        $pruned = 0;

        foreach ($connections as $connectionId => $data) {
            if (($data['last_heartbeat'] ?? 0) < $threshold) {
                unset($connections[$connectionId]);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            if (empty($connections)) {
                Cache::forget(self::KEY_CONNECTIONS.$userPublicId);
            } else {
                Cache::put(
                    self::KEY_CONNECTIONS.$userPublicId,
                    $connections,
                    now()->addHours(24)
                );
            }

            Log::debug('Pruned stale WebSocket connections', [
                'public_id' => $userPublicId,
                'pruned' => $pruned,
            ]);
        }

        return $pruned;
    }

    /**
     * Get all active connections for a user.
     */
    public function getConnections(string $userPublicId): array
    {
        return Cache::get(self::KEY_CONNECTIONS.$userPublicId, []);
    }

    /**
     * Get the number of active connections for a user.
     */
    public function getConnectionCount(string $userPublicId): int
    {
        return count($this->getConnections($userPublicId));
    }

    /**
     * Get connection attempt count for rate limiting.
     */
    protected function getAttemptCount(string $userPublicId): int
    {
        return (int) Cache::get(self::KEY_ATTEMPTS.$userPublicId, 0);
    }

    /**
     * Increment connection attempts.
     */
    protected function incrementAttempts(string $userPublicId): void
    {
        $key = self::KEY_ATTEMPTS.$userPublicId;
        $attempts = $this->getAttemptCount($userPublicId) + 1;
        Cache::put($key, $attempts, now()->addSeconds(self::ATTEMPT_WINDOW_SECONDS));
    }

    /**
     * Block a user temporarily.
     */
    protected function blockUser(string $userPublicId, int $seconds): void
    {
        Cache::put(
            self::KEY_BLOCKED.$userPublicId,
            now()->addSeconds($seconds)->timestamp,
            now()->addSeconds($seconds)
        );

        Log::warning('User temporarily blocked from WebSocket connections', [
            'public_id' => $userPublicId,
            'blocked_for' => $seconds,
        ]);
    }

    /**
     * Check if a user is blocked.
     */
    protected function isBlocked(string $userPublicId): bool
    {
        return Cache::has(self::KEY_BLOCKED.$userPublicId);
    }

    /**
     * Get the timestamp when the user will be unblocked.
     */
    protected function getBlockedUntil(string $userPublicId): ?int
    {
        return Cache::get(self::KEY_BLOCKED.$userPublicId);
    }
}
