<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Async Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, non-critical audit logs will be written asynchronously
    | after the response is sent to improve performance.
    |
    */

    'async' => env('AUDIT_ASYNC', true),

    /*
    |--------------------------------------------------------------------------
    | Log Read Operations
    |--------------------------------------------------------------------------
    |
    | Whether to log GET requests and read operations via the AuditRequest
    | middleware. Generally, you want to keep this disabled for performance.
    |
    */

    'log_reads' => env('AUDIT_LOG_READS', false),

    /*
    |--------------------------------------------------------------------------
    | Auditable Models
    |--------------------------------------------------------------------------
    |
    | Models that should be automatically audited via the AuditableObserver.
    | Add model class names here to enable automatic CRUD audit logging.
    |
    */

    'models' => [
        \App\Models\User::class,
        \App\Models\Team::class,
        \App\Models\Client::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Fields
    |--------------------------------------------------------------------------
    |
    | Fields that should never be included in audit logs. These are typically
    | sensitive fields like passwords, tokens, and secrets.
    |
    */

    'excluded_fields' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_key',
        'api_secret',
        'secret',
        'token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Masked Fields
    |--------------------------------------------------------------------------
    |
    | Fields that should be partially masked in audit logs. These fields
    | will show only the first 2 and last 2 characters.
    |
    */

    'masked_fields' => [
        'email',
        'phone',
        'ip_address',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | URL paths that should not be audited by the AuditRequest middleware.
    | Supports wildcards (*).
    |
    */

    'excluded_paths' => [
        'api/user',
        'api/sanctum/*',
        'up',
        'health',
        '_debugbar/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Period
    |--------------------------------------------------------------------------
    |
    | Number of days to retain audit logs before automatic pruning.
    | Set to 0 to disable automatic pruning.
    |
    */

    'retention_days' => env('AUDIT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for caching recent audit logs.
    |
    */

    'cache' => [
        'enabled' => env('AUDIT_CACHE_ENABLED', true),
        'ttl' => 300, // 5 minutes
        'recent_logs_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Agent Parsing
    |--------------------------------------------------------------------------
    |
    | Whether to parse and store structured user agent information.
    |
    */

    'parse_user_agent' => env('AUDIT_PARSE_USER_AGENT', true),

];
