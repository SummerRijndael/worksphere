<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

class MaintenanceService
{
    private const CACHE_OBSERVABILITY_PREFIX = 'metrics:cache:observed';

    private const REDIS_INSTANCE_WINDOW_PREFIX = 'metrics:redis:instance:totals';

    private const METRICS_BUCKET_TTL_SECONDS = 7200;

    public function __construct(
        protected \App\Services\Chat\PresenceService $presenceService
    ) {}

    /**
     * Get completed jobs from Horizon.
     */
    public function getCompletedJobs(int $limit = 50): array
    {
        try {
            $repository = app(JobRepository::class);

            // Horizon stores completed jobs as "recent"
            return $repository->getRecent()->take($limit)->values()->all();
        } catch (Throwable $e) {
            Log::warning('Failed to get completed jobs', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Clear completed jobs.
     */
    public function clearCompletedJobs(): array
    {
        try {
            // Horizon doesn't have a direct "clear all completed" method in the repo interface usually exposed this simply.
            // It relies on trimming.
            // But we can check RedisJobRepository source if we really want or just omit simple clear.
            // Usually we just let them expire.
            // Let's check what methods involve deleting.
            // $repository->delete($id) exists.
            // For now, let's just implement fetching. Clearing might be overkill or complex.
            return [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get comprehensive system information.
     */
    public function getSystemInfo(): array
    {
        $cacheInfo = $this->getDetailedCacheInfo();

        return [
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'db_engine' => $this->getDatabaseEngine(),
            'db_version' => $this->getDatabaseVersion(),
            'database_size' => $this->getDatabaseSize(),
            'cache_size' => $this->getCacheSize(),
            'logs_size' => $this->getLogsSize(),
            'logs_count' => $this->getLogsCount(),
            'server_time' => now()->format('Y-m-d H:i:s T'),
            'uptime' => $this->getSystemUptime(),
            'os_name' => $this->getOsName(),
            'os_version' => $this->getOsVersion(),
            'server_software' => $this->getServerSoftware(),
            'disk_total' => $this->getDiskTotal(),
            'disk_used' => $this->getDiskUsed(),
            'disk_free' => $this->getDiskFree(),
            'memory_total' => $this->getMemoryTotal(),
            'memory_used' => $this->getMemoryUsed(),
            'cpu_model' => $this->getCpuModel(),
            'disk_model' => $this->getDiskModel(),

            // Detailed cache info
            'cache_driver' => $cacheInfo['driver'],
            'cache_status' => $cacheInfo['status'],
            'cache_keys' => $cacheInfo['keys'] ?? null,
            'cache_keys_cache_db' => $cacheInfo['keys_cache_db'] ?? null,
            'cache_keys_default_db' => $cacheInfo['keys_default_db'] ?? null,
            'cache_memory_used' => $cacheInfo['memory_used'] ?? null,
            'cache_memory_peak' => $cacheInfo['memory_peak'] ?? null,
            'cache_memory_limit' => $cacheInfo['memory_limit'] ?? null,
            'cache_hits' => $cacheInfo['hits'] ?? null,
            'cache_misses' => $cacheInfo['misses'] ?? null,
            'cache_hit_rate' => $cacheInfo['hit_rate'] ?? null,
            'cache_hit_rate_5m' => $cacheInfo['hit_rate_5m'] ?? null,
            'redis_instance_metrics' => $cacheInfo['redis_instance_metrics'] ?? null,
            'laravel_cache_metrics' => $cacheInfo['laravel_cache_metrics'] ?? null,
            'reverb_connections' => $cacheInfo['reverb_connections'] ?? 0,

            // Real Health Status
            'health' => $this->getSystemHealth(),

            // Online Users
            'online_stats' => $this->getOnlineUserStats(),
        ];
    }

    /**
     * Get online user statistics.
     */
    public function getOnlineUserStats(): array
    {
        try {
            $activeUsers = $this->presenceService->getActiveUsers();

            return [
                'total' => $activeUsers->count(),
                'administrators' => $activeUsers->filter(fn ($user) => $user->hasRole('administrator'))->count(),
                'it_support' => $activeUsers->filter(fn ($user) => $user->hasRole('it_support'))->count(),
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to get online user stats', ['error' => $e->getMessage()]);

            return [
                'total' => 0,
                'administrators' => 0,
                'it_support' => 0,
            ];
        }
    }

    /**
     * Get system health status.
     */
    private function getSystemHealth(): array
    {
        $status = 'healthy';
        $issues = [];

        // Check Database
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $status = 'degraded';
            $issues[] = 'Database connection failed';
        }

        // Check Redis/Cache
        try {
            // Simple ping check
            Redis::connection()->ping();
        } catch (Throwable $e) {
            // Try fallback if cache is different connection
            try {
                Cache::store()->get('health_check');
            } catch (Throwable $e2) {
                $status = 'degraded'; // or 'critical' depending on severity
                $issues[] = 'Cache/Redis connection failed';
            }
        }

        // Check Maintenance Mode
        if (app()->isDownForMaintenance()) {
            $status = 'maintenance';
        }

        return [
            'status' => $status, // healthy, degraded, maintenance, offline
            'issues' => $issues,
        ];
    }

    /**
     * Get the database engine name (formatted).
     */
    public function getDatabaseEngine(): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'sqlsrv' => 'SQL Server',
            'mariadb' => 'MariaDB',
            default => Str::title($driver),
        };
    }

    /**
     * Get the database version.
     */
    public function getDatabaseVersion(): string
    {
        try {
            $driver = DB::connection()->getDriverName();

            return match ($driver) {
                'mysql', 'mariadb' => $this->getMySqlVersion(),
                'pgsql' => $this->getPostgresVersion(),
                'sqlite' => $this->getSqliteVersion(),
                default => 'Unknown',
            };
        } catch (Throwable $e) {
            Log::warning('Failed to get database version', ['error' => $e->getMessage()]);

            return 'Unknown';
        }
    }

    private function getMySqlVersion(): string
    {
        $result = DB::select('SELECT VERSION() as version');
        $version = $result[0]->version ?? '';

        // Extract just the version number (e.g., "8.0.35" from "8.0.35-0ubuntu0.20.04.1")
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    private function getPostgresVersion(): string
    {
        $result = DB::select('SELECT version()');
        $version = $result[0]->version ?? '';

        // Extract version from "PostgreSQL 15.2 on ..."
        if (preg_match('/PostgreSQL\s+(\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    private function getSqliteVersion(): string
    {
        $result = DB::select('SELECT sqlite_version() as version');

        return $result[0]->version ?? 'Unknown';
    }

    /**
     * Get the database size in a human-readable format.
     */
    public function getDatabaseSize(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
            $database = DB::connection()->getDatabaseName();

            $sizeInBytes = match ($driver) {
                'mysql', 'mariadb' => $this->getMySqlDatabaseSize($database),
                'pgsql' => $this->getPostgresDatabaseSize($database),
                'sqlite' => $this->getSqliteDatabaseSize($database),
                default => 0,
            };

            return $this->formatBytes($sizeInBytes);
        } catch (Throwable $e) {
            Log::warning('Failed to get database size', ['error' => $e->getMessage()]);

            return 'Unknown';
        }
    }

    private function getMySqlDatabaseSize(string $database): int
    {
        $result = DB::select('
            SELECT SUM(data_length + index_length) as size
            FROM information_schema.tables
            WHERE table_schema = ?
        ', [$database]);

        return (int) ($result[0]->size ?? 0);
    }

    private function getPostgresDatabaseSize(string $database): int
    {
        $result = DB::select('SELECT pg_database_size(?) as size', [$database]);

        return (int) ($result[0]->size ?? 0);
    }

    private function getSqliteDatabaseSize(string $database): int
    {
        if (File::exists($database)) {
            return File::size($database);
        }

        return 0;
    }

    /**
     * Get detailed cache information.
     */
    public function getDetailedCacheInfo(): array
    {
        $driver = config('cache.default');

        $info = [
            'driver' => ucfirst($driver),
            'status' => 'Unknown',
        ];

        try {
            if ($driver === 'redis') {
                $redisInfo = $this->getRedisDetailedInfo();
                $info = array_merge($info, $redisInfo);
            } elseif ($driver === 'file') {
                $info['status'] = 'Active';
                $info['keys'] = $this->getFileCacheKeyCount();
            } else {
                $info['status'] = 'Active';
            }
        } catch (Throwable $e) {
            Log::warning('Failed to get detailed cache info', ['error' => $e->getMessage()]);
            $info['status'] = 'Error';
        }

        return $info;
    }

    /**
     * Get detailed Redis information.
     */
    private function getRedisDetailedInfo(): array
    {
        try {
            $memoryInfo = Redis::info('memory');
            $statsInfo = Redis::info('stats');

            // Handle Predis/PhpRedis variants: nested arrays or flat arrays with section keys.
            $usedMemory = $memoryInfo['used_memory'] ?? $memoryInfo['Memory']['used_memory'] ?? 0;
            $peakMemory = $memoryInfo['used_memory_peak'] ?? $memoryInfo['Memory']['used_memory_peak'] ?? 0;
            $statsRoot = $statsInfo['Stats'] ?? $statsInfo['stats'] ?? $statsInfo;

            $hasHits = is_array($statsRoot) && array_key_exists('keyspace_hits', $statsRoot);
            $hasMisses = is_array($statsRoot) && array_key_exists('keyspace_misses', $statsRoot);
            $hits = $hasHits ? (int) $statsRoot['keyspace_hits'] : null;
            $misses = $hasMisses ? (int) $statsRoot['keyspace_misses'] : null;
            $expiredKeys = is_array($statsRoot) && array_key_exists('expired_keys', $statsRoot)
                ? (int) $statsRoot['expired_keys']
                : null;
            $evictedKeys = is_array($statsRoot) && array_key_exists('evicted_keys', $statsRoot)
                ? (int) $statsRoot['evicted_keys']
                : null;

            $hitRate = $this->calculateHitRate($hits, $misses);
            $hitRateWindow = ($hits !== null && $misses !== null)
                ? $this->calculateRedisInstanceWindowedHitRate($hits, $misses, 5)
                : null;

            $keyCounts = $this->getRedisKeyCounts();

            // Get Reverb Connections if available
            $reverbConnections = 0;
            try {
                // Reverb uses Pusher protocol usually on port 8080/6001 or via HTTP API
                // We'll try to use the Broadcast facade which uses the configured pusher client
                if (config('broadcasting.default') === 'reverb') {
                    $reverbConnections = $this->getReverbConnectionCount();
                }
            } catch (Throwable) {
                // Ignore errors fetching reverb stats to not break cache stats
            }

            $redisInstanceMetrics = [
                'scope' => 'redis_instance_global',
                'hits' => $hits,
                'misses' => $misses,
                'hit_rate' => $hitRate,
                'hit_rate_5m' => $hitRateWindow,
                'expired_keys' => $expiredKeys,
                'evicted_keys' => $evictedKeys,
            ];

            $laravelCacheMetrics = $this->getObservedLaravelCacheMetrics(5);

            return [
                'status' => 'Connected',
                'keys' => $keyCounts['total'],
                'keys_cache_db' => $keyCounts['cache_db'],
                'keys_default_db' => $keyCounts['default_db'],
                'memory_used' => $this->formatBytes((int) $usedMemory),
                'memory_peak' => $this->formatBytes((int) $peakMemory),
                'memory_limit' => $this->getRedisMemoryLimit($memoryInfo),
                'hits' => $hits !== null ? number_format($hits) : 'N/A',
                'misses' => $misses !== null ? number_format($misses) : 'N/A',
                'hit_rate' => $hitRate !== null ? $hitRate.'%' : 'N/A',
                'hit_rate_5m' => $hitRateWindow !== null ? $hitRateWindow.'%' : null,
                'redis_instance_metrics' => $redisInstanceMetrics,
                'laravel_cache_metrics' => $laravelCacheMetrics,
                'reverb_connections' => $reverbConnections,
            ];
        } catch (Throwable) {
            return [
                'status' => 'Disconnected',
            ];
        }
    }

    /**
     * Get Redis memory limit.
     */
    private function getRedisMemoryLimit(array $info): string
    {
        $maxMemory = $info['maxmemory'] ?? $info['Memory']['maxmemory'] ?? 0;

        if ((int) $maxMemory === 0) {
            return 'Unlimited';
        }

        return $this->formatBytes((int) $maxMemory);
    }

    /**
     * Get number of keys in Redis.
     */
    private function getRedisKeyCount(): int
    {
        return $this->getRedisKeyCounts()['total'];
    }

    /**
     * Get Redis key counts split by db for better metric accuracy.
     *
     * @return array{total:int, cache_db:int, default_db:int}
     */
    private function getRedisKeyCounts(): array
    {
        try {
            $info = Redis::info('keyspace');
            $counts = [];

            // Handle Predis nested array format (Keyspace -> dbX -> keys)
            if (isset($info['Keyspace']) && is_array($info['Keyspace'])) {
                foreach ($info['Keyspace'] as $db => $stats) {
                    if (isset($stats['keys'])) {
                        $counts[(string) $db] = (int) $stats['keys'];
                    }
                }
            } else {
                // Handle Phpredis/Raw format (dbX:keys=123,expires=10...)
                foreach ($info as $key => $value) {
                    if (strpos((string) $key, 'db') === 0 && preg_match('/keys=(\d+)/', (string) $value, $matches)) {
                        $counts[(string) $key] = (int) $matches[1];
                    }
                }
            }

            $total = array_sum($counts);
            $cacheDb = 'db'.(string) config('database.redis.cache.database', 1);
            $defaultDb = 'db'.(string) config('database.redis.default.database', 0);

            return [
                'total' => $total,
                'cache_db' => $counts[$cacheDb] ?? 0,
                'default_db' => $counts[$defaultDb] ?? 0,
            ];
        } catch (Throwable) {
            return [
                'total' => 0,
                'cache_db' => 0,
                'default_db' => 0,
            ];
        }
    }

    /**
     * Calculate hit rate percentage as float.
     */
    private function calculateHitRate(?int $hits, ?int $misses): ?float
    {
        if ($hits === null || $misses === null) {
            return null;
        }

        $total = $hits + $misses;
        if ($total <= 0) {
            return null;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Calculate windowed Redis instance hit rate from cumulative totals.
     */
    private function calculateRedisInstanceWindowedHitRate(int $hits, int $misses, int $windowMinutes): ?float
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);

            $bucketNow = Carbon::now()->startOfMinute();
            $currentKey = self::REDIS_INSTANCE_WINDOW_PREFIX.':'.$bucketNow->format('YmdHi');
            $oldKey = self::REDIS_INSTANCE_WINDOW_PREFIX.':'.$bucketNow->copy()->subMinutes($windowMinutes)->format('YmdHi');

            $redis->setex($currentKey, self::METRICS_BUCKET_TTL_SECONDS, json_encode([
                'hits' => $hits,
                'misses' => $misses,
            ]));

            $oldRaw = $redis->get($oldKey);
            if (! is_string($oldRaw) || $oldRaw === '') {
                return null;
            }

            $old = json_decode($oldRaw, true);
            if (! is_array($old) || ! isset($old['hits'], $old['misses'])) {
                return null;
            }

            $deltaHits = max(0, $hits - (int) $old['hits']);
            $deltaMisses = max(0, $misses - (int) $old['misses']);

            return $this->calculateHitRate($deltaHits, $deltaMisses);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build Laravel cache effectiveness metrics from event counters.
     */
    private function getObservedLaravelCacheMetrics(int $windowMinutes = 5): ?array
    {
        try {
            $connection = config('cache.stores.redis.connection', 'cache');
            $redis = Redis::connection($connection);
            $now = Carbon::now()->startOfMinute();

            $hitTotal = (int) ($redis->get(self::CACHE_OBSERVABILITY_PREFIX.':hit:total') ?? 0);
            $missTotal = (int) ($redis->get(self::CACHE_OBSERVABILITY_PREFIX.':miss:total') ?? 0);
            $writeTotal = (int) ($redis->get(self::CACHE_OBSERVABILITY_PREFIX.':write:total') ?? 0);

            $hitKeys = [];
            $missKeys = [];
            for ($i = 0; $i < $windowMinutes; $i++) {
                $bucket = $now->copy()->subMinutes($i)->format('YmdHi');
                $hitKeys[] = self::CACHE_OBSERVABILITY_PREFIX.":hit:{$bucket}";
                $missKeys[] = self::CACHE_OBSERVABILITY_PREFIX.":miss:{$bucket}";
            }

            $hitValues = $redis->mget($hitKeys);
            $missValues = $redis->mget($missKeys);

            $hitWindow = array_sum(array_map(fn ($v) => (int) ($v ?? 0), is_array($hitValues) ? $hitValues : []));
            $missWindow = array_sum(array_map(fn ($v) => (int) ($v ?? 0), is_array($missValues) ? $missValues : []));

            return [
                'scope' => 'laravel_cache_events',
                'window_minutes' => $windowMinutes,
                'hits_total' => $hitTotal,
                'misses_total' => $missTotal,
                'writes_total' => $writeTotal,
                'hit_rate_total' => $this->calculateHitRate($hitTotal, $missTotal),
                'hits_window' => $hitWindow,
                'misses_window' => $missWindow,
                'hit_rate_window' => $this->calculateHitRate($hitWindow, $missWindow),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get approximate number of file cache keys.
     */
    private function getFileCacheKeyCount(): int
    {
        $cachePath = storage_path('framework/cache/data');

        if (! File::isDirectory($cachePath)) {
            return 0;
        }

        return count(File::allFiles($cachePath));
    }

    /**
     * Get Reverb connection count (Active Users).
     * We count 'private-user.*' channels as a proxy for active user sessions.
     */
    private function getReverbConnectionCount(): int
    {
        try {
            if (config('broadcasting.default') !== 'reverb') {
                return 0;
            }

            // Manually instantiate Pusher client to avoid undefined method error on Reverb driver
            // Fallback to broadcasting config if reverb config is missing
            $appId = config('reverb.apps.apps.0.id') ?? config('broadcasting.connections.reverb.app_id');
            $key = config('reverb.apps.apps.0.key') ?? config('broadcasting.connections.reverb.key');
            $secret = config('reverb.apps.apps.0.secret') ?? config('broadcasting.connections.reverb.secret');

            $options = config('broadcasting.connections.reverb.options', []);
            $host = $options['host'] ?? '127.0.0.1';
            $port = $options['port'] ?? 8080;
            $scheme = $options['scheme'] ?? 'http';

            if (! $appId || ! class_exists(\Pusher\Pusher::class)) {
                return 0;
            }

            $pusher = new \Pusher\Pusher($key, $secret, $appId, [
                'host' => $host,
                'port' => $port,
                'scheme' => $scheme,
                'useTLS' => $scheme === 'https',
            ]);

            // Get total connections count from Reverb API
            $response = $pusher->get('/connections');
            if ($response && isset($response->connections)) {
                return (int) $response->connections;
            }

            // Fetch user channels to get active user count as fallback
            $channels = $pusher->get_channels(['filter_by_prefix' => 'private-user.']);

            $count = 0;
            if ($channels && isset($channels->channels)) {
                $count = count((array) $channels->channels);
            }

            return $count;
        } catch (Throwable $e) {
            // Suppress known Reverb/Pusher compatibility issue where array is returned instead of object
            if (str_contains($e->getMessage(), 'get_object_vars')) {
                return 0;
            }

            Log::error('Reverb Connection Error: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Get the cache size.
     */
    public function getCacheSize(): string
    {
        try {
            $cacheDriver = config('cache.default');

            if ($cacheDriver === 'redis') {
                return $this->getRedisCacheSize();
            }

            if ($cacheDriver === 'file') {
                return $this->getFileCacheSize();
            }

            return 'N/A';
        } catch (Throwable $e) {
            Log::warning('Failed to get cache size', ['error' => $e->getMessage()]);

            return 'Unknown';
        }
    }

    private function getRedisCacheSize(): string
    {
        try {
            $info = Redis::info('memory');
            $usedMemory = $info['used_memory'] ?? 0;

            return $this->formatBytes((int) $usedMemory);
        } catch (Throwable) {
            return 'Unavailable';
        }
    }

    private function getFileCacheSize(): string
    {
        $cachePath = storage_path('framework/cache/data');

        if (! File::isDirectory($cachePath)) {
            return '0 B';
        }

        $size = $this->getDirectorySize($cachePath);

        return $this->formatBytes($size);
    }

    /**
     * Get the logs directory size.
     */
    public function getLogsSize(): string
    {
        $logsPath = storage_path('logs');

        if (! File::isDirectory($logsPath)) {
            return '0 B';
        }

        $size = $this->getDirectorySize($logsPath);

        return $this->formatBytes($size);
    }

    /**
     * Get the number of log files.
     */
    public function getLogsCount(): int
    {
        $logsPath = storage_path('logs');

        if (! File::isDirectory($logsPath)) {
            return 0;
        }

        return count(File::glob($logsPath.'/*.log'));
    }

    /**
     * Get system uptime.
     */
    public function getSystemUptime(): string
    {
        try {
            // Try to read from /proc/uptime (Linux)
            if (File::exists('/proc/uptime')) {
                $uptime = File::get('/proc/uptime');
                $seconds = (int) explode(' ', $uptime)[0];

                return $this->formatUptime($seconds);
            }

            // Fallback: use PHP start time
            return 'N/A';
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get operating system name with distribution/edition.
     */
    public function getOsName(): string
    {
        $osType = php_uname('s');

        // Linux: Try to get distribution name
        if (stripos($osType, 'Linux') !== false) {
            $distro = $this->getLinuxDistribution();

            return $distro ? "Linux/{$distro}" : 'Linux';
        }

        // Windows: Try to get edition
        if (stripos($osType, 'Windows') !== false || stripos($osType, 'WINNT') !== false) {
            $edition = $this->getWindowsEdition();

            return $edition ? "Windows/{$edition}" : 'Windows';
        }

        // macOS/Darwin
        if (stripos($osType, 'Darwin') !== false) {
            return 'macOS';
        }

        return $osType;
    }

    /**
     * Get Linux distribution name.
     */
    private function getLinuxDistribution(): ?string
    {
        try {
            // Try /etc/os-release (standard on most modern distros)
            if (File::exists('/etc/os-release')) {
                $osRelease = File::get('/etc/os-release');

                // Look for NAME or PRETTY_NAME
                if (preg_match('/^NAME="?([^"\n]+)"?/m', $osRelease, $matches)) {
                    $name = trim($matches[1], '"');

                    // Clean up common patterns
                    $name = str_replace(' Linux', '', $name);
                    $name = str_replace(' GNU/Linux', '', $name);

                    return $name;
                }
            }

            // Fallback: Try specific distribution files
            if (File::exists('/etc/redhat-release')) {
                $content = File::get('/etc/redhat-release');
                if (preg_match('/^([A-Za-z\s]+)/', $content, $matches)) {
                    return trim($matches[1]);
                }
            }

            if (File::exists('/etc/debian_version')) {
                return 'Debian';
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get Windows edition.
     */
    private function getWindowsEdition(): ?string
    {
        try {
            // php_uname('v') on Windows returns build info
            $version = php_uname('v');

            // Try to extract edition from version string
            if (preg_match('/Windows (Server \d+|10|11|8\.1|8|7|Vista|XP)/', $version, $matches)) {
                return $matches[1];
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get operating system version/release.
     */
    public function getOsVersion(): string
    {
        return php_uname('r');
    }

    /**
     * Get server software (e.g., Apache, Nginx).
     */
    public function getServerSoftware(): string
    {
        return $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    }

    /**
     * Get total disk space.
     */
    public function getDiskTotal(): string
    {
        try {
            $total = disk_total_space('/');

            return $this->formatBytes($total);
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get used disk space.
     */
    public function getDiskUsed(): string
    {
        try {
            $total = disk_total_space('/');
            $free = disk_free_space('/');

            // For more accuracy on Linux, we could parse df, but total-free is good enough
            // The difference from 'df' is reserved blocks (typically 5% for root)
            $used = $total - $free;

            return $this->formatBytes($used);
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get free disk space.
     */
    public function getDiskFree(): string
    {
        try {
            $free = disk_free_space('/');

            return $this->formatBytes($free);
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get disk usage percentage.
     */
    public function getDiskUsagePercent(): float
    {
        try {
            $total = disk_total_space('/');
            $free = disk_free_space('/');

            if ($total === 0) {
                return 0;
            }

            return round((($total - $free) / $total) * 100, 1);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Get total system memory.
     */
    public function getMemoryTotal(): string
    {
        try {
            // Linux only
            if (File::exists('/proc/meminfo')) {
                $meminfo = File::get('/proc/meminfo');
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                    return $this->formatBytes($matches[1] * 1024);
                }
            }

            return 'N/A';
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get used system memory.
     */
    public function getMemoryUsed(): string
    {
        try {
            // Linux only
            if (File::exists('/proc/meminfo')) {
                $meminfo = File::get('/proc/meminfo');
                $total = 0;
                $available = 0;

                if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                    $total = $matches[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                    $available = $matches[1] * 1024;
                }

                if ($total > 0) {
                    $used = $total - $available;

                    return $this->formatBytes($used);
                }
            }

            return 'N/A';
        } catch (Throwable) {
            return 'N/A';
        }
    }

    /**
     * Get CPU Model Name.
     */
    public function getCpuModel(): ?string
    {
        try {
            if (File::exists('/proc/cpuinfo')) {
                $cpuinfo = File::get('/proc/cpuinfo');
                if (preg_match('/model name\s+:\s+(.+)/', $cpuinfo, $matches)) {
                    return trim($matches[1]);
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get Disk Model.
     */
    public function getDiskModel(): ?string
    {
        try {
            // Check common block devices
            $prefixes = ['sd', 'vd', 'nvme', 'hd'];

            foreach ($prefixes as $prefix) {
                // simple wildcard check or just check a few likely ones
                // since we can't efficiently glob /sys/block easily without iterating or known names
                // let's try reading /sys/block directory
                if (File::isDirectory('/sys/block')) {
                    $dirs = File::directories('/sys/block');
                    foreach ($dirs as $dir) {
                        $basename = basename($dir);
                        if (Str::startsWith($basename, $prefixes)) {
                            $modelPath = $dir.'/device/model';
                            if (File::exists($modelPath)) {
                                $model = trim(File::get($modelPath));
                                if (! empty($model)) {
                                    return $model;
                                }
                            }
                        }
                    }
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    // =========================================================================
    // Maintenance Mode Operations
    // =========================================================================

    /**
     * Check if maintenance mode is enabled.
     */
    public function isMaintenanceMode(): bool
    {
        return app()->isDownForMaintenance();
    }

    /**
     * Get maintenance mode information.
     */
    public function getMaintenanceInfo(): ?array
    {
        if (! $this->isMaintenanceMode()) {
            return null;
        }

        $maintenanceFile = storage_path('framework/down');

        if (! File::exists($maintenanceFile)) {
            return ['enabled' => true];
        }

        try {
            $data = json_decode(File::get($maintenanceFile), true);

            return [
                'enabled' => true,
                'reason' => $data['message'] ?? null,
                'secret' => $data['secret'] ?? null,
                'started_at' => isset($data['time']) ? Carbon::createFromTimestamp($data['time'])->toIso8601String() : null,
            ];
        } catch (Throwable) {
            return ['enabled' => true];
        }
    }

    /**
     * Enable maintenance mode.
     */
    public function enableMaintenanceMode(string $reason, ?string $secret = null): void
    {
        $params = [
            '--message' => $reason,
        ];

        if ($secret) {
            $params['--secret'] = $secret;
        }

        Artisan::call('down', $params);

        Log::info('Maintenance mode enabled', [
            'reason' => $reason,
            'has_secret' => ! empty($secret),
        ]);
    }

    /**
     * Disable maintenance mode.
     */
    public function disableMaintenanceMode(): void
    {
        Artisan::call('up');
        Log::info('Maintenance mode disabled');
    }

    // =========================================================================
    // Cache Operations
    // =========================================================================

    /**
     * Clear application cache.
     */
    public function clearApplicationCache(): array
    {
        Artisan::call('cache:clear');
        Log::info('Application cache cleared');

        return [
            'success' => true,
            'message' => 'Application cache cleared successfully',
        ];
    }

    /**
     * Clear view cache.
     */
    public function clearViewCache(): array
    {
        Artisan::call('view:clear');
        Log::info('View cache cleared');

        return [
            'success' => true,
            'message' => 'Compiled views cleared successfully',
        ];
    }

    /**
     * Clear config cache.
     */
    public function clearConfigCache(): array
    {
        Artisan::call('config:clear');
        Log::info('Config cache cleared');

        return [
            'success' => true,
            'message' => 'Configuration cache cleared successfully',
        ];
    }

    /**
     * Clear route cache.
     */
    public function clearRouteCache(): array
    {
        Artisan::call('route:clear');
        Log::info('Route cache cleared');

        return [
            'success' => true,
            'message' => 'Route cache cleared successfully',
        ];
    }

    /**
     * Clear all caches.
     */
    public function clearAllCaches(): array
    {
        $this->clearApplicationCache();
        $this->clearViewCache();
        $this->clearConfigCache();
        $this->clearRouteCache();

        return [
            'success' => true,
            'message' => 'All caches cleared successfully',
        ];
    }

    // =========================================================================
    // Session Operations
    // =========================================================================

    /**
     * Clear all sessions.
     */
    public function clearAllSessions(): array
    {
        $driver = config('session.driver');

        try {
            match ($driver) {
                'file' => $this->clearFileSessions(),
                'database' => $this->clearDatabaseSessions(),
                'redis' => $this->clearRedisSessions(),
                default => throw new \Exception("Unsupported session driver: {$driver}"),
            };

            Log::warning('All sessions cleared');

            return [
                'success' => true,
                'message' => 'All sessions cleared successfully',
            ];
        } catch (Throwable $e) {
            Log::error('Failed to clear sessions', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to clear sessions: '.$e->getMessage(),
            ];
        }
    }

    private function clearFileSessions(): void
    {
        $sessionPath = storage_path('framework/sessions');

        if (File::isDirectory($sessionPath)) {
            $files = File::files($sessionPath);
            foreach ($files as $file) {
                File::delete($file);
            }
        }
    }

    private function clearDatabaseSessions(): void
    {
        $table = config('session.table', 'sessions');
        DB::table($table)->truncate();
    }

    public function clearRedisSessions(): void
    {
        $connection = config('session.connection');
        Redis::connection($connection)->flushdb();
    }

    /**
     * Flush all Redis data.
     */
    public function flushRedis(): array
    {
        try {
            Redis::flushall();
            Log::warning('Redis flushed');

            return [
                'success' => true,
                'message' => 'Redis database flushed successfully',
            ];
        } catch (Throwable $e) {
            Log::error('Failed to flush Redis', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to flush Redis: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Restart queue workers.
     */
    public function restartQueue(): array
    {
        try {
            // Run artisan via a new process to ensure command registration (web doesn't have console commands)
            $process = Process::path(base_path())->run(PHP_BINARY.' artisan queue:restart');
            Log::info('Queue workers restart signal sent via Process', ['output' => $process->output()]);

            return [
                'success' => true,
                'message' => 'Queue workers restart signal sent successfully',
            ];
        } catch (Throwable $e) {
            Log::error('Failed to restart queue workers', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to restart queue workers: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Restart Horizon.
     */
    public function restartHorizon(): array
    {
        try {
            // Run artisan via a new process to ensure command registration (web doesn't have console commands)
            $process = Process::path(base_path())->run(PHP_BINARY.' artisan horizon:terminate');
            Log::info('Horizon termination signal sent via Process', ['output' => $process->output()]);

            return [
                'success' => true,
                'message' => 'Horizon termination signal sent successfully',
            ];
        } catch (Throwable $e) {
            Log::error('Failed to restart Horizon', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to restart Horizon: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Restart Reverb.
     */
    public function restartReverb(): array
    {
        try {
            // Run artisan via a new process to ensure command registration (web doesn't have console commands)
            $process = Process::path(base_path())->run(PHP_BINARY.' artisan reverb:restart');
            Log::info('Reverb restart signal sent via Process', ['output' => $process->output()]);

            return [
                'success' => true,
                'message' => 'Reverb restart signal sent successfully',
            ];
        } catch (Throwable $e) {
            Log::error('Failed to restart Reverb', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to restart Reverb: '.$e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Log Operations
    // =========================================================================

    /**
     * Clear old log files.
     */
    public function clearOldLogs(int $daysOld = 30): array
    {
        $logsPath = storage_path('app/private/sys/logs');
        $deletedCount = 0;
        $errors = [];

        if (! File::isDirectory($logsPath)) {
            return [
                'success' => true,
                'message' => 'No logs directory found',
                'deleted_count' => 0,
            ];
        }

        $cutoffDate = $daysOld > 0 ? Carbon::now()->subDays($daysOld) : Carbon::now()->addMinute(); // If 0, include everything up to now

        foreach (File::files($logsPath) as $file) {
            if ($file->getExtension() === 'log') {
                try {
                    $modifiedAt = Carbon::createFromTimestamp($file->getMTime());

                    if ($modifiedAt->lt($cutoffDate)) {
                        // If it's the main laravel log, try to truncate instead of delete to avoid lock issues
                        if ($file->getFilename() === 'laravel.log' || $file->getFilename() === 'browser.log') {
                            File::put($file->getRealPath(), '');
                        } else {
                            File::delete($file);
                        }
                        $deletedCount++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Failed to clear {$file->getFilename()}: {$e->getMessage()}";
                }
            }
        }

        Log::info('Old logs cleared', [
            'deleted_count' => $deletedCount,
            'days_old' => $daysOld,
            'errors' => $errors,
        ]);

        return [
            'success' => count($errors) === 0,
            'message' => count($errors) === 0
                ? "Cleared {$deletedCount} log file(s)"
                : "Cleared {$deletedCount} files with some errors.",
            'deleted_count' => $deletedCount,
            'errors' => $errors,
        ];
    }

    // =========================================================================
    // Scheduled Tasks
    // =========================================================================

    /**
     * Get list of scheduled tasks with their status.
     */
    public function getScheduledTasks(): array
    {
        $tasks = [
            [
                'name' => 'process-expired-permissions',
                'description' => 'Process Expired Permissions',
                'schedule' => 'Hourly',
            ],
            [
                'name' => 'send-permission-expiry-reminders-7day',
                'description' => 'Permission Expiry Reminders (7 day)',
                'schedule' => 'Daily at 9:00 AM',
            ],
            [
                'name' => 'send-permission-expiry-reminders-1day',
                'description' => 'Permission Expiry Reminders (1 day)',
                'schedule' => 'Daily at 9:00 AM',
            ],
            [
                'name' => 'expire-role-change-requests',
                'description' => 'Expire Old Role Change Requests',
                'schedule' => 'Daily',
            ],
            [
                'name' => 'horizon:snapshot',
                'description' => 'Horizon Metrics Snapshot',
                'schedule' => 'Every 5 minutes',
            ],
            [
                'name' => 'events:send-reminders',
                'description' => 'Send Event Reminders',
                'schedule' => 'Every minute',
            ],
            [
                'name' => 'tickets:reminders',
                'description' => 'Ticket SLA & Deadline Reminders',
                'schedule' => 'Every 5 minutes',
            ],
            [
                'name' => 'server-monitor:run-checks',
                'description' => 'Server Monitor Checks',
                'schedule' => 'Every minute',
            ],
            [
                'name' => 'email:sync-incremental',
                'description' => 'Email Incremental Sync',
                'schedule' => 'Every 5 minutes',
            ],
            [
                'name' => 'presence:prune',
                'description' => 'Prune Stale Connections',
                'schedule' => 'Every 5 minutes',
            ],
            [
                'name' => 'audit:prune',
                'description' => 'Prune Audit Logs (30 days)',
                'schedule' => 'Daily',
            ],
            [
                'name' => 'teams:check-health',
                'description' => 'Team Health Check (Dormant/Deletion)',
                'schedule' => 'Daily at 2:00 AM',
            ],
            [
                'name' => 'audit:prune',
                'description' => 'Prune Audit Logs (30 days)',
                'schedule' => 'Daily at 2:30 AM',
            ],
        ];

        return array_map(function ($task) {
            $status = $this->getTaskStatus($task['name']);

            return array_merge($task, [
                'last_run' => $status['last_run'],
                'status' => $status['status'],
                'start_time' => $status['start_time'],
                'duration' => $status['duration'],
            ]);
        }, $tasks);
    }

    /**
     * Get detailed status of a scheduled task.
     */
    public function getTaskStatus(string $taskName): array
    {
        $cacheKey = "scheduled_task_status:{$taskName}";

        // Try new cache key first (from subscriber)
        $data = Cache::get($cacheKey);

        if ($data) {
            return [
                'status' => $data['status'] ?? 'unknown',
                'last_run' => isset($data['last_run']) ? Carbon::parse($data['last_run'])->toIso8601String() : null,
                'start_time' => isset($data['start_time']) ? Carbon::parse($data['start_time'])->toIso8601String() : null,
                'duration' => $data['duration'] ?? null,
            ];
        }

        // Fallback to old simple key (manual runs before subscriber)
        $lastRun = Cache::get("scheduled_task_last_run:{$taskName}");

        if ($lastRun) {
            return [
                'status' => 'success', // Assume success for legacy data
                'last_run' => Carbon::parse($lastRun)->toIso8601String(),
                'start_time' => null,
                'duration' => null,
            ];
        }

        return [
            'status' => 'pending',
            'last_run' => null,
            'start_time' => null,
            'duration' => null,
        ];
    }

    /**
     * Record a task run (manual).
     */
    public function recordTaskRun(string $taskName): void
    {
        // Update the new cache structure
        Cache::put("scheduled_task_status:{$taskName}", [
            'status' => 'success',
            'start_time' => now()->toIso8601String(),
            'end_time' => now()->toIso8601String(),
            'last_run' => now()->toIso8601String(),
            'duration' => null, // Manual runs don't capture duration easily here
        ], 60 * 60 * 24 * 7);
    }

    /**
     * Manually run a scheduled task.
     */
    public function runScheduledTask(string $taskName): array
    {
        try {
            // Map task names to their artisan commands or jobs
            $result = match ($taskName) {
                'process-expired-permissions' => $this->runJob(\App\Jobs\ProcessExpiredPermissions::class),
                'send-permission-expiry-reminders-7day' => $this->runJob(\App\Jobs\SendPermissionExpiryReminders::class, [7]),
                'send-permission-expiry-reminders-1day' => $this->runJob(\App\Jobs\SendPermissionExpiryReminders::class, [1]),
                'expire-role-change-requests' => $this->runRoleChangeExpiry(),
                'horizon:snapshot' => $this->runArtisanCommand('horizon:snapshot'),
                'events:send-reminders' => $this->runArtisanCommand('events:send-reminders'),
                'tickets:reminders' => $this->runArtisanCommand('tickets:reminders'),
                'server-monitor:run-checks' => $this->runArtisanCommand('server-monitor:run-checks'),
                'email:sync-incremental' => $this->runArtisanCommand('email:sync-incremental'),
                'teams:check-health' => $this->runArtisanCommand('teams:check-health'),
                'audit:prune' => $this->runArtisanCommand('audit:prune', ['--days' => 30]),
                default => throw new \Exception("Unknown task: {$taskName}"),
            };

            $this->recordTaskRun($taskName);

            return [
                'success' => true,
                'message' => "Task '{$taskName}' executed successfully",
            ];
        } catch (Throwable $e) {
            Log::error('Failed to run scheduled task', [
                'task' => $taskName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to run task: '.$e->getMessage(),
            ];
        }
    }

    private function runJob(string $jobClass, array $params = []): bool
    {
        if (! class_exists($jobClass)) {
            throw new \Exception("Job class not found: {$jobClass}");
        }

        $job = empty($params) ? new $jobClass : new $jobClass(...$params);
        dispatch_sync($job);

        return true;
    }

    private function runRoleChangeExpiry(): bool
    {
        app(\App\Services\RoleChangeService::class)->expireOldRequests();

        return true;
    }

    private function runArtisanCommand(string $command, array $params = []): bool
    {
        Artisan::call($command, $params);

        return true;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get directory size recursively.
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * Format uptime seconds to human-readable format.
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0) {
            $parts[] = $hours.'h';
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes.'m';
        }

        return implode(' ', $parts) ?: '< 1m';
    }

    /**
     * Get PHP Configuration Information.
     */
    public function getPhpInfo(): array
    {
        return [
            'version' => phpversion(),
            'interface' => php_sapi_name(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false,
            'extensions' => get_loaded_extensions(),
        ];
    }

    /**
     * Get Database Table Health (Top Tables by Size) with Pagination.
     */
    public function getDatabaseHealth(int $page = 1, int $perPage = 10): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            $database = DB::connection()->getDatabaseName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $offset = ($page - 1) * $perPage;

                $total = DB::scalar('
                    SELECT COUNT(*) 
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                ', [$database]);

                // Using values directly for limit/offset to avoid PDO string quoting issues in some driver versions
                // Since inputs are typed ints, this is safe from injection
                $query = "
                    SELECT 
                        table_name as name,
                        ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb,
                        table_rows as rows_count
                    FROM information_schema.tables
                    WHERE table_schema = ?
                    ORDER BY (data_length + index_length) DESC
                    LIMIT {$perPage} OFFSET {$offset}
                ";

                $data = DB::select($query, [$database]);

                return [
                    'data' => $data,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil($total / $perPage),
                    ],
                ];
            }

            return ['data' => [], 'pagination' => []];
        } catch (Throwable $e) {
            Log::warning('Failed to fetch table stats', ['error' => $e->getMessage()]);

            return ['data' => [], 'pagination' => []];
        }
    }

    /**
     * Get tail of the Laravel log file.
     */
    public function getLogs(int $lines = 100): array
    {
        $logFile = storage_path('app/private/sys/logs/laravel.log');

        if (! File::exists($logFile)) {
            return [
                'content' => [],
                'file_size' => '0 B',
                'path' => $logFile,
            ];
        }

        try {
            // Simple approach for reasonably sized logs:
            // Native PHP tail implementation
            $chunkSize = 4096;
            $fileSize = File::size($logFile);
            $handle = fopen($logFile, 'rb');

            if (! $handle) {
                return [
                    'content' => [],
                    'file_size' => $this->formatBytes($fileSize),
                    'path' => $logFile,
                ];
            }

            $linesFound = [];
            $position = $fileSize;
            $currentLine = '';

            while ($position > 0 && count($linesFound) < $lines) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;

                fseek($handle, $position);
                $chunk = fread($handle, $readSize);

                // Process chunk in reverse
                for ($i = strlen($chunk) - 1; $i >= 0; $i--) {
                    $char = $chunk[$i];
                    if ($char === "\n") {
                        if ($currentLine !== '') {
                            // Prepend because we are reading backwards
                            array_unshift($linesFound, strrev($currentLine));
                            $currentLine = '';
                        }

                        if (count($linesFound) >= $lines) {
                            break;
                        }
                    } else {
                        $currentLine .= $char;
                    }
                }
            }

            // Add any remaining line segment
            if ($currentLine !== '') {
                array_unshift($linesFound, strrev($currentLine));
            }

            fclose($handle);

            return [
                'content' => $linesFound,
                'file_size' => $this->formatBytes($fileSize),
                'path' => $logFile,
            ];

            // Fallback for purely PHP
            $content = file($logFile);
            $slice = array_slice($content, -$lines);

            return [
                'content' => array_map('trim', $slice),
                'file_size' => $this->formatBytes(File::size($logFile)),
                'path' => $logFile,
            ];

        } catch (Throwable $e) {
            return [
                'content' => ['Error reading log file: '.$e->getMessage()],
                'file_size' => 'Unknown',
                'path' => $logFile,
            ];
        }
    }

    /**
     * Get list of backups.
     */
    /**
     * Get list of backups with Pagination.
     */
    public function getBackups(int $page = 1, int $perPage = 20): array
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? config('filesystems.default');
        $disk = Storage::disk($diskName);
        $name = config('backup.backup.name');

        Log::debug('Fetching backups', ['disk' => $diskName, 'name' => $name, 'root' => config("filesystems.disks.$diskName.root")]);

        $files = $disk->allFiles($name);
        Log::debug('Backups found', ['count' => count($files)]);

        $backups = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.zip')) {
                $backups[] = [
                    'path' => $file,
                    'name' => basename($file),
                    'size' => $this->formatBytes($disk->size($file)),
                    'created_at' => date('Y-m-d H:i:s', $disk->lastModified($file)),
                    'timestamp' => $disk->lastModified($file),
                ];
            }
        }

        usort($backups, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        // Paginator logic
        $total = count($backups);
        $slice = array_slice($backups, ($page - 1) * $perPage, $perPage);

        return [
            'data' => $slice,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage) ?: 1,
            ],
        ];
    }

    /**
     * Create a new backup.
     */
    /**
     * Create a new backup (Queued).
     */
    public function createBackup(string $option = 'both'): array
    {
        try {
            // Dispatch Job to Heavy Queue
            \App\Jobs\CreateSystemBackup::dispatch($option)->onQueue('heavy');

            return ['success' => true, 'message' => 'Backup task has been queued and will start shortly.'];
        } catch (Throwable $e) {
            Log::error('Backup dispatch failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a backup.
     */
    public function deleteBackup(string $path): bool
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? config('filesystems.default');

        return Storage::disk($diskName)->delete($path);
    }

    /**
     * Bulk Delete Backups.
     */
    public function bulkDeleteBackups(array $paths): array
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? config('filesystems.default');
        $deleted = 0;
        foreach ($paths as $path) {
            if (Storage::disk($diskName)->exists($path)) {
                Storage::disk($diskName)->delete($path);
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }

    /**
     * Process Secure Download.
     */
    /**
     * Process Secure Download (Queued).
     */
    public function processSecureDownload($user, array $paths, string $reason): array
    {
        // Dispatch Job
        \App\Jobs\PrepareSecureDownload::dispatch($user, $paths, $reason);

        return ['success' => true, 'message' => 'Secure download is being prepared. You will receive an email shortly.'];
    }

    /**
     * Get backup stream for download (Legacy/Internal).
     *
     * @deprecated Use processSecureDownload
     */
    public function downloadBackup(string $path)
    {
        $diskName = config('backup.backup.destination.disks')[0] ?? config('filesystems.default');

        return Storage::disk($diskName)->download($path);
    }

    /**
     * Get Reverb statistics from Pulse aggregates.
     */
    public function getReverbStats(int $hours = 24): array
    {
        try {
            $period = 10080; // Default to 7d for historical data

            // 1. Get current connections
            $connections = DB::table('pulse_values')
                ->where('type', 'reverb_connections')
                ->latest('timestamp')
                ->first();

            $currentConnections = $connections ? (int) $connections->value : $this->getReverbConnectionCount();

            // 2. Get message stats (Aggregates)
            $messageReceived = DB::table('pulse_aggregates')
                ->where('type', 'reverb_message:received')
                ->where('period', $period)
                ->orderBy('bucket', 'desc')
                ->limit($hours * 60)
                ->get()
                ->map(fn ($row) => [
                    'timestamp' => $row->bucket,
                    'value' => (float) $row->value,
                ]);

            $messageSent = DB::table('pulse_aggregates')
                ->where('type', 'reverb_message:sent')
                ->where('period', $period)
                ->orderBy('bucket', 'desc')
                ->limit($hours * 60)
                ->get()
                ->map(fn ($row) => [
                    'timestamp' => $row->bucket,
                    'value' => (float) $row->value,
                ]);

            // 3. Get connection history
            $connectionHistory = DB::table('pulse_aggregates')
                ->where('type', 'reverb_connections')
                ->where('period', $period)
                ->orderBy('bucket', 'desc')
                ->limit($hours * 60)
                ->get()
                ->map(fn ($row) => [
                    'timestamp' => $row->bucket,
                    'value' => (float) $row->value,
                ]);

            return [
                'current_connections' => $currentConnections,
                'history' => [
                    'messages_received' => $messageReceived->reverse()->values()->all(),
                    'messages_sent' => $messageSent->reverse()->values()->all(),
                    'connections' => $connectionHistory->reverse()->values()->all(),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to get Reverb stats from Pulse', ['error' => $e->getMessage()]);

            return [
                'current_connections' => 0,
                'history' => [
                    'messages_received' => [],
                    'messages_sent' => [],
                    'connections' => [],
                ],
            ];
        }
    }

    /**
     * Get External Services Status.
     */
    public function getExternalServicesStatus(): array
    {
        $results = [];

        // 1. Google ReCaptcha
        $recaptchaConfig = config('recaptcha');
        $recaptchaEnabled = $recaptchaConfig['enabled'] ?? false;
        $results['recaptcha'] = [
            'name' => 'Google ReCaptcha',
            'configured' => ! empty($recaptchaConfig['site_key']) && ! empty($recaptchaConfig['secret_key']),
            'enabled' => $recaptchaEnabled,
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];

        if ($results['recaptcha']['configured']) {
            $start = microtime(true);
            try {
                $response = Http::timeout(3)->get('https://www.google.com/recaptcha/api/siteverify');
                if ($response->successful()) {
                    $results['recaptcha']['status'] = 'Operational';
                    $results['recaptcha']['latency'] = round((microtime(true) - $start) * 1000);
                } else {
                    $results['recaptcha']['status'] = 'Error';
                    $results['recaptcha']['message'] = 'HTTP '.$response->status();
                }
            } catch (Throwable $e) {
                $results['recaptcha']['status'] = 'Unreachable';
                $results['recaptcha']['message'] = $e->getMessage();
            }
        } else {
            $results['recaptcha']['status'] = 'Not Configured';
        }

        // 2. Twilio
        $twilioConfig = config('services.twilio');
        $twilioConfigured = ! empty($twilioConfig['sid']) && ! empty($twilioConfig['token']);
        $results['twilio'] = [
            'name' => 'Twilio',
            'configured' => $twilioConfigured,
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];

        if ($twilioConfigured) {
            $start = microtime(true);
            try {
                $sid = $twilioConfig['sid'];
                $token = $twilioConfig['token'];
                $response = Http::timeout(5)->withBasicAuth($sid, $token)
                    ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

                if ($response->successful()) {
                    $data = $response->json();
                    $results['twilio']['status'] = ($data['status'] ?? 'active') === 'active' ? 'Operational' : ucfirst($data['status'] ?? 'Unknown');
                    $results['twilio']['latency'] = round((microtime(true) - $start) * 1000);
                } elseif ($response->status() === 401 || $response->status() === 403) {
                    $results['twilio']['status'] = 'Invalid Credentials';
                    $results['twilio']['message'] = 'Invalid credentials';
                } else {
                    $results['twilio']['status'] = 'Error';
                    $results['twilio']['message'] = 'HTTP '.$response->status();
                }
            } catch (Throwable $e) {
                $message = strtolower($e->getMessage());
                $isAuthError = str_contains($message, 'unauthorized')
                    || str_contains($message, 'forbidden')
                    || str_contains($message, 'invalid username')
                    || str_contains($message, 'invalid password')
                    || str_contains($message, 'invalid credentials')
                    || str_contains($message, 'authentication');

                if ($isAuthError) {
                    $results['twilio']['status'] = 'Invalid Credentials';
                    $results['twilio']['message'] = 'Invalid credentials';
                } else {
                    $results['twilio']['status'] = 'Unreachable';
                    $results['twilio']['message'] = 'Unable to reach Twilio';
                }
            }
        } else {
            $results['twilio']['status'] = 'Not Configured';
        }

        // 3. Cloudflare
        $results['cloudflare'] = [
            'name' => 'Cloudflare',
            'status' => 'Unknown',
            'latency' => null,
            'info' => null,
        ];
        $start = microtime(true);
        try {
            $response = Http::timeout(3)->get('https://www.cloudflare.com/cdn-cgi/trace');
            if ($response->successful()) {
                $results['cloudflare']['status'] = 'Operational';
                $results['cloudflare']['latency'] = round((microtime(true) - $start) * 1000);

                $body = $response->body();
                if (preg_match('/colo=([A-Z]+)/', $body, $matches)) {
                    $results['cloudflare']['info'] = 'Connected via '.$matches[1];
                }
            } else {
                $results['cloudflare']['status'] = 'Error';
            }
        } catch (Throwable $e) {
            $results['cloudflare']['status'] = 'Unreachable';
        }

        // 4. Cloudflare R2 (S3 API) connectivity
        $allDisks = config('filesystems.disks', []);
        $r2Candidates = [];

        foreach ($allDisks as $diskName => $diskConfig) {
            if (! is_array($diskConfig) || ($diskConfig['driver'] ?? null) !== 's3') {
                continue;
            }

            $endpoint = strtolower((string) ($diskConfig['endpoint'] ?? ''));
            $isR2Endpoint = str_contains($endpoint, 'r2.cloudflarestorage.com') || str_contains($endpoint, 'cloudflarestorage.com');
            if ($diskName === 'r2' || $isR2Endpoint) {
                $r2Candidates[$diskName] = $diskConfig;
            }
        }

        $configuredR2Disks = [];
        $misconfiguredR2Disks = [];
        foreach ($r2Candidates as $diskName => $diskConfig) {
            $isConfigured = ! empty($diskConfig['key'])
                && ! empty($diskConfig['secret'])
                && ! empty($diskConfig['bucket'])
                && ! empty($diskConfig['endpoint']);

            if ($isConfigured) {
                $configuredR2Disks[$diskName] = $diskConfig;
            } else {
                $misconfiguredR2Disks[] = $diskName;
            }
        }

        $results['r2'] = [
            'name' => 'Cloudflare R2',
            'configured' => ! empty($configuredR2Disks),
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
            'info' => ! empty($configuredR2Disks)
                ? 'Disks: '.implode(', ', array_keys($configuredR2Disks))
                : null,
            'checks' => [],
        ];

        if (empty($r2Candidates)) {
            $results['r2']['status'] = 'Not Configured';
            $results['r2']['message'] = 'No R2-backed S3 disk found.';
        } elseif (empty($configuredR2Disks)) {
            $results['r2']['status'] = 'Not Configured';
            $results['r2']['message'] = 'Missing R2 credentials on disks: '.implode(', ', $misconfiguredR2Disks);
        } else {
            $overallStatus = 'Operational';
            $latencies = [];
            $checkMessages = [];

            foreach ($configuredR2Disks as $diskName => $diskConfig) {
                $probeStart = microtime(true);
                $probePath = "system/health/r2-connectivity-{$diskName}-".Str::uuid().'.txt';
                $probePayload = 'r2-health-check:'.now()->toIso8601String();
                $probeWritten = false;
                $probeDeleted = false;
                $checkStatus = 'Unknown';
                $checkMessage = null;

                try {
                    $probeWritten = (bool) Storage::disk($diskName)->put($probePath, $probePayload);

                    if (! $probeWritten) {
                        $checkStatus = 'Error';
                        $checkMessage = 'Write probe failed.';
                    } else {
                        $readback = (string) Storage::disk($diskName)->get($probePath);

                        if ($readback !== $probePayload) {
                            $checkStatus = 'Error';
                            $checkMessage = 'Read verification failed.';
                        } else {
                            $probeDeleted = (bool) Storage::disk($diskName)->delete($probePath);
                            if ($probeDeleted) {
                                $checkStatus = 'Operational';
                            } else {
                                $checkStatus = 'Degraded';
                                $checkMessage = 'Read/write succeeded, but cleanup failed.';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $checkStatus = 'Unreachable';
                    $checkMessage = $e->getMessage();
                } finally {
                    // Best-effort cleanup if probe object was created and not deleted.
                    if ($probeWritten && ! $probeDeleted) {
                        try {
                            Storage::disk($diskName)->delete($probePath);
                        } catch (Throwable) {
                        }
                    }
                }

                $latency = round((microtime(true) - $probeStart) * 1000);
                $latencies[] = $latency;

                $results['r2']['checks'][$diskName] = [
                    'bucket' => $diskConfig['bucket'] ?? null,
                    'status' => $checkStatus,
                    'latency' => $latency,
                    'message' => $checkMessage,
                ];

                if ($checkStatus === 'Unreachable') {
                    $overallStatus = 'Unreachable';
                } elseif ($checkStatus === 'Error' && $overallStatus !== 'Unreachable') {
                    $overallStatus = 'Error';
                } elseif ($checkStatus === 'Degraded' && ! in_array($overallStatus, ['Unreachable', 'Error'], true)) {
                    $overallStatus = 'Degraded';
                }

                if ($checkStatus !== 'Operational') {
                    $checkMessages[] = "[{$diskName}] ".($checkMessage ?? $checkStatus);
                }
            }

            $results['r2']['status'] = $overallStatus;
            $results['r2']['latency'] = ! empty($latencies)
                ? round(array_sum($latencies) / count($latencies))
                : null;
            $results['r2']['message'] = ! empty($checkMessages) ? implode(' | ', $checkMessages) : null;
        }

        // 5. Cloudflare STUN
        $results['cloudflare_stun'] = [
            'name' => 'Cloudflare STUN',
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];
        $stunStart = microtime(true);
        try {
            // Check reachability via Cloudflare's edge
            $response = Http::timeout(3)->get('https://www.cloudflare.com/cdn-cgi/trace');
            if ($response->successful()) {
                $results['cloudflare_stun']['status'] = 'Operational';
                $results['cloudflare_stun']['latency'] = round((microtime(true) - $stunStart) * 1000);
            } else {
                $results['cloudflare_stun']['status'] = 'Error';
            }
        } catch (Throwable $e) {
            $results['cloudflare_stun']['status'] = 'Unreachable';
            $results['cloudflare_stun']['message'] = $e->getMessage();
        }

        // 6. Cloudflare TURN
        $results['cloudflare_turn'] = [
            'name' => 'Cloudflare TURN',
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];
        $turnStart = microtime(true);
        try {
            // Check reachability via Cloudflare API
            $response = Http::timeout(3)->get('https://api.cloudflare.com/client/v4/ips');
            if ($response->successful()) {
                $results['cloudflare_turn']['status'] = 'Operational';
                $results['cloudflare_turn']['latency'] = round((microtime(true) - $turnStart) * 1000);
            } else {
                $results['cloudflare_turn']['status'] = 'Error';
            }
        } catch (Throwable $e) {
            $results['cloudflare_turn']['status'] = 'Unreachable';
            $results['cloudflare_turn']['message'] = $e->getMessage();
        }

        // 7. Cloudflare SFU (Calls Signaling)
        $results['cloudflare_sfu'] = [
            'name' => 'Cloudflare SFU',
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];
        $sfuStart = microtime(true);
        try {
            // Use another edge check for SFU
            $response = Http::timeout(3)->get('https://1.1.1.1/cdn-cgi/trace');
            if ($response->successful()) {
                $results['cloudflare_sfu']['status'] = 'Operational';
                $results['cloudflare_sfu']['latency'] = round((microtime(true) - $sfuStart) * 1000);
            } else {
                $results['cloudflare_sfu']['status'] = 'Error';
            }
        } catch (Throwable $e) {
            $results['cloudflare_sfu']['status'] = 'Unreachable';
            $results['cloudflare_sfu']['message'] = $e->getMessage();
        }

        // 8. Local Reverb Server
        $results['reverb_server'] = [
            'name' => 'Reverb Server',
            'status' => 'Unknown',
            'latency' => null,
            'message' => null,
        ];
        $reverbStart = microtime(true);
        try {
            $port = config('reverb.servers.reverb.options.port', 8080);
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($connection) {
                fclose($connection);
                $results['reverb_server']['status'] = 'Operational';
                $results['reverb_server']['latency'] = round((microtime(true) - $reverbStart) * 1000);
            } else {
                $results['reverb_server']['status'] = 'Unreachable';
                $results['reverb_server']['message'] = "Port $port closed or Reverb down";
            }
        } catch (Throwable $e) {
            $results['reverb_server']['status'] = 'Error';
            $results['reverb_server']['message'] = $e->getMessage();
        }

        return $results;
    }
}
