<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | The default cache store to use for CacheService. Redis is recommended
    | for production environments due to its support for tagged caching.
    |
    */

    'default_store' => env('CACHING_DEFAULT_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use when the default store (e.g., Redis) is
    | unavailable. This provides graceful degradation.
    |
    */

    'fallback_store' => env('CACHING_FALLBACK_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | TTL Configuration
    |--------------------------------------------------------------------------
    |
    | Time-to-live settings for different cache categories in seconds.
    |
    */

    'ttl' => [
        'permissions' => 3600,        // 1 hour
        'team_permissions' => 1800,   // 30 minutes
        'audit_logs' => 300,          // 5 minutes
        'user_data' => 600,           // 10 minutes
        'navigation' => 3600,         // 1 hour
        'default' => 600,             // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all cache tags to avoid conflicts with other applications
    | sharing the same Redis instance.
    |
    */

    'tag_prefix' => env('CACHE_TAG_PREFIX', 'coresync'),

    /*
    |--------------------------------------------------------------------------
    | Redis Health Check Interval
    |--------------------------------------------------------------------------
    |
    | How often (in seconds) to recheck Redis availability after a failure.
    | This prevents hammering Redis during outages.
    |
    */

    'health_check_interval' => env('CACHE_HEALTH_CHECK_INTERVAL', 30),

    /*
    |--------------------------------------------------------------------------
    | Cache Warming
    |--------------------------------------------------------------------------
    |
    | Settings for cache warming operations.
    |
    */

    'warming' => [
        'enabled' => env('CACHE_WARMING_ENABLED', true),
        'on_boot' => env('CACHE_WARMING_ON_BOOT', false),
    ],

];
