<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CacheService
{
    protected string $defaultStore;

    protected string $fallbackStore;

    protected string $tagPrefix;

    protected bool $redisAvailable;

    protected int $healthCheckInterval;

    protected ?int $lastHealthCheck = null;

    /**
     * @var array<string, int>
     */
    protected array $ttlConfig;

    public function __construct()
    {
        $this->defaultStore = config('caching.default_store', 'redis');
        $this->fallbackStore = config('caching.fallback_store', 'database');
        $this->tagPrefix = config('caching.tag_prefix', 'coresync');
        $this->healthCheckInterval = config('caching.health_check_interval', 30);
        $this->ttlConfig = config('caching.ttl', []);
        $this->redisAvailable = $this->checkRedisConnection();
    }

    /**
     * Get a value from cache with graceful degradation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            return $this->store()->get($key, $default);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'get', $key);

            return $this->fallbackStore()->get($key, $default);
        }
    }

    /**
     * Check if a key exists in cache.
     */
    public function has(string $key): bool
    {
        try {
            return $this->store()->has($key);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'has', $key);

            return $this->fallbackStore()->has($key);
        }
    }

    /**
     * Store a value in cache.
     */
    public function put(string $key, mixed $value, ?int $ttl = null, ?string $category = null): bool
    {
        $ttl = $ttl ?? $this->getTtl($category);

        try {
            return $this->store()->put($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'put', $key);

            return $this->fallbackStore()->put($key, $value, $ttl);
        }
    }

    /**
     * Store a value with tags (Redis only, degrades gracefully).
     *
     * @param  array<string>  $tags
     */
    public function putWithTags(array $tags, string $key, mixed $value, ?int $ttl = null, ?string $category = null): bool
    {
        $ttl = $ttl ?? $this->getTtl($category);
        $prefixedTags = $this->prefixTags($tags);

        if (! $this->redisAvailable) {
            return $this->fallbackStore()->put($key, $value, $ttl);
        }

        try {
            Cache::store('redis')->tags($prefixedTags)->put($key, $value, $ttl);

            return true;
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'putWithTags', $key);

            return $this->fallbackStore()->put($key, $value, $ttl);
        }
    }

    /**
     * Remember a value using a callback.
     */
    public function remember(string $key, ?int $ttl, callable $callback, ?string $category = null): mixed
    {
        $ttlValue = $ttl ?? $this->getTtl($category);

        try {
            return $this->store()->remember($key, $ttlValue, $callback);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'remember', $key);

            return $this->fallbackStore()->remember($key, $ttlValue, $callback);
        }
    }

    /**
     * Remember a value with tags using a callback.
     *
     * @param  array<string>  $tags
     */
    public function rememberWithTags(array $tags, string $key, ?int $ttl, callable $callback, ?string $category = null): mixed
    {
        $ttlValue = $ttl ?? $this->getTtl($category);
        $prefixedTags = $this->prefixTags($tags);

        if (! $this->redisAvailable) {
            return $this->fallbackStore()->remember($key, $ttlValue, $callback);
        }

        try {
            return Cache::store('redis')->tags($prefixedTags)->remember($key, $ttlValue, $callback);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'rememberWithTags', $key);

            return $this->fallbackStore()->remember($key, $ttlValue, $callback);
        }
    }

    /**
     * Store a value forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        try {
            return $this->store()->forever($key, $value);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'forever', $key);

            return $this->fallbackStore()->forever($key, $value);
        }
    }

    /**
     * Forget a cached value.
     */
    public function forget(string $key): bool
    {
        try {
            return $this->store()->forget($key);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'forget', $key);

            return $this->fallbackStore()->forget($key);
        }
    }

    /**
     * Flush cache by tags (Redis only).
     *
     * @param  array<string>  $tags
     */
    public function flushTags(array $tags): bool
    {
        if (! $this->redisAvailable) {
            return false;
        }

        $prefixedTags = $this->prefixTags($tags);

        try {
            Cache::store('redis')->tags($prefixedTags)->flush();

            return true;
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'flushTags', implode(',', $tags));

            return false;
        }
    }

    /**
     * Flush the entire cache store.
     */
    public function flush(): bool
    {
        try {
            return $this->store()->flush();
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'flush', 'all');

            return false;
        }
    }

    /**
     * Increment a numeric value.
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        try {
            return $this->store()->increment($key, $value);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'increment', $key);

            return $this->fallbackStore()->increment($key, $value);
        }
    }

    /**
     * Decrement a numeric value.
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        try {
            return $this->store()->decrement($key, $value);
        } catch (Throwable $e) {
            $this->handleCacheFailure($e, 'decrement', $key);

            return $this->fallbackStore()->decrement($key, $value);
        }
    }

    /**
     * Warm critical cache data.
     *
     * @param  array<string, callable>  $warmers
     */
    public function warm(array $warmers): void
    {
        foreach ($warmers as $key => $callback) {
            try {
                $value = $callback();
                $this->put($key, $value);
            } catch (Throwable $e) {
                Log::warning("Cache warming failed for key: {$key}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get TTL for a category.
     */
    public function getTtl(?string $category): int
    {
        if ($category === null) {
            return $this->ttlConfig['default'] ?? 600;
        }

        return $this->ttlConfig[$category] ?? $this->ttlConfig['default'] ?? 600;
    }

    /**
     * Check if Redis is available.
     */
    public function isRedisAvailable(): bool
    {
        return $this->redisAvailable;
    }

    /**
     * Get the current store name being used.
     */
    public function getCurrentStore(): string
    {
        return $this->redisAvailable ? $this->defaultStore : $this->fallbackStore;
    }

    /**
     * Force a Redis health check.
     */
    public function checkHealth(): bool
    {
        $this->redisAvailable = $this->checkRedisConnection();
        $this->lastHealthCheck = time();

        return $this->redisAvailable;
    }

    /**
     * Get the active cache store.
     */
    protected function store(): Repository
    {
        $this->maybeRecheckRedis();

        return $this->redisAvailable
            ? Cache::store('redis')
            : Cache::store($this->fallbackStore);
    }

    /**
     * Get the fallback cache store.
     */
    protected function fallbackStore(): Repository
    {
        return Cache::store($this->fallbackStore);
    }

    /**
     * Check Redis connection.
     */
    protected function checkRedisConnection(): bool
    {
        if ($this->defaultStore !== 'redis') {
            return false;
        }

        try {
            Redis::ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Recheck Redis availability if enough time has passed.
     */
    protected function maybeRecheckRedis(): void
    {
        if ($this->redisAvailable) {
            return;
        }

        if ($this->lastHealthCheck === null) {
            $this->lastHealthCheck = time();

            return;
        }

        if ((time() - $this->lastHealthCheck) >= $this->healthCheckInterval) {
            $this->checkHealth();
        }
    }

    /**
     * Handle cache operation failure.
     */
    protected function handleCacheFailure(Throwable $e, string $operation, string $key): void
    {
        Log::error("Cache {$operation} failed", [
            'key' => $key,
            'error' => $e->getMessage(),
            'store' => $this->defaultStore,
        ]);

        $this->redisAvailable = false;
        $this->lastHealthCheck = time();
    }

    /**
     * Prefix tags with the configured prefix.
     *
     * @param  array<string>  $tags
     * @return array<string>
     */
    protected function prefixTags(array $tags): array
    {
        return array_map(
            fn (string $tag) => "{$this->tagPrefix}:{$tag}",
            $tags
        );
    }
}
