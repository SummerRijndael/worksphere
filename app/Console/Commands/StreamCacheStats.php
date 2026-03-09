<?php

namespace App\Console\Commands;

use App\Events\CacheStatsUpdated;
use App\Services\MaintenanceService;
use Illuminate\Console\Command;

class StreamCacheStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maintenance:stream-cache-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stream cache statistics updates via WebSockets';

    /**
     * Execute the console command.
     */
    public function handle(MaintenanceService $maintenanceService)
    {
        $this->info('Starting cache stats stream...');

        // Run for slightly less than 60 seconds to allow overlap with scheduler
        $endTime = time() + 58;

        while (time() < $endTime) {
            try {
                $cacheInfo = $maintenanceService->getDetailedCacheInfo();

                CacheStatsUpdated::dispatch([
                    'cache_driver' => $cacheInfo['driver'],
                    'cache_status' => $cacheInfo['status'],
                    'cache_keys' => $cacheInfo['keys'] ?? 0,
                    'cache_keys_cache_db' => $cacheInfo['keys_cache_db'] ?? null,
                    'cache_keys_default_db' => $cacheInfo['keys_default_db'] ?? null,
                    'cache_memory_used' => $cacheInfo['memory_used'] ?? '0 B',
                    'cache_memory_peak' => $cacheInfo['memory_peak'] ?? '0 B',
                    'cache_memory_limit' => $cacheInfo['memory_limit'] ?? 'Unlimited',
                    'cache_hits' => $cacheInfo['hits'] ?? '0',
                    'cache_misses' => $cacheInfo['misses'] ?? '0',
                    'cache_hit_rate' => $cacheInfo['hit_rate'] ?? null,
                    'cache_hit_rate_5m' => $cacheInfo['hit_rate_5m'] ?? null,
                    'redis_instance_metrics' => $cacheInfo['redis_instance_metrics'] ?? null,
                    'laravel_cache_metrics' => $cacheInfo['laravel_cache_metrics'] ?? null,
                    'reverb_connections' => $cacheInfo['reverb_connections'] ?? 0,
                ]);

                // Update every 3 seconds
                sleep(3);
            } catch (\Throwable $e) {
                $this->error('Error streaming cache stats: '.$e->getMessage());
                sleep(5);
            }
        }

        $this->info('Cache stats stream finished.');
    }
}
