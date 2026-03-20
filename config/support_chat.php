<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Assistant Toggle
    |--------------------------------------------------------------------------
    */
    'ai_enabled' => env('SUPPORT_AI_ENABLED', true),
    'ai_adapter' => \App\Services\Support\Ai\SimulatedSupportAiAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | Human Agent Detection
    |--------------------------------------------------------------------------
    */
    'agent_roles' => ['administrator', 'it_support'],
    'agent_permissions' => [
        'support.chats.view',
        'support.chats.reply',
        'support.chats.assign',
        'support.chats.resolve',
        'tickets.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation Heuristics (Simulation)
    |--------------------------------------------------------------------------
    */
    'complexity_keywords' => [
        'outage',
        'downtime',
        'security',
        'hacked',
        'breach',
        'legal',
        'invoice mismatch',
        'refund',
        'chargeback',
        'cannot access',
        'data loss',
        'urgent',
        'critical',
    ],
    'complexity_min_length' => 420,

    /*
    |--------------------------------------------------------------------------
    | Inbox Defaults
    |--------------------------------------------------------------------------
    */
    'default_per_page' => 20,
    'max_per_page' => 100,

    /*
    |--------------------------------------------------------------------------
    | Realtime Token TTL (Seconds)
    |--------------------------------------------------------------------------
    */
    'realtime_token_ttl' => env('SUPPORT_REALTIME_TOKEN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Attachment URL TTL (Minutes)
    |--------------------------------------------------------------------------
    */
    'media_url_ttl_minutes' => env('SUPPORT_MEDIA_URL_TTL_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'customer_send' => [
            'max_attempts' => env('SUPPORT_CUSTOMER_SEND_MAX_ATTEMPTS', 6),
            'decay_seconds' => env('SUPPORT_CUSTOMER_SEND_DECAY_SECONDS', 30),
        ],
        'customer_typing' => [
            'max_attempts' => env('SUPPORT_CUSTOMER_TYPING_MAX_ATTEMPTS', 40),
            'decay_seconds' => env('SUPPORT_CUSTOMER_TYPING_DECAY_SECONDS', 30),
        ],
        'agent_send' => [
            'max_attempts' => env('SUPPORT_AGENT_SEND_MAX_ATTEMPTS', 12),
            'decay_seconds' => env('SUPPORT_AGENT_SEND_DECAY_SECONDS', 30),
        ],
        'agent_typing' => [
            'max_attempts' => env('SUPPORT_AGENT_TYPING_MAX_ATTEMPTS', 60),
            'decay_seconds' => env('SUPPORT_AGENT_TYPING_DECAY_SECONDS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcast Jobs
    |--------------------------------------------------------------------------
    |
    | sync_first: dispatch job synchronously and fallback to queue if sync fails.
    | queue_first: dispatch to queue and fallback to sync if queue push fails.
    |
    */
    'jobs' => [
        'enabled' => env('SUPPORT_CHAT_JOBS_ENABLED', true),
        'queue' => env('SUPPORT_CHAT_JOBS_QUEUE', 'chats'),
        'broadcast_mode' => env('SUPPORT_CHAT_BROADCAST_MODE', 'sync_first'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest Resume Session
    |--------------------------------------------------------------------------
    */
    'guest_resume_cookie' => env('SUPPORT_GUEST_RESUME_COOKIE', 'worksphere_support_guest'),
    'guest_resume_ttl_minutes' => env('SUPPORT_GUEST_RESUME_TTL_MINUTES', 4320),
    'guest_session_prune_days' => env('SUPPORT_GUEST_SESSION_PRUNE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Survey Tracking (CSAT / NPS)
    |--------------------------------------------------------------------------
    */
    'surveys' => [
        'enabled' => env('SUPPORT_SURVEYS_ENABLED', true),
        'comment_max_length' => env('SUPPORT_SURVEY_COMMENT_MAX_LENGTH', 1000),
        'csat' => [
            'enabled' => env('SUPPORT_CSAT_ENABLED', true),
            'ttl_hours' => env('SUPPORT_CSAT_TTL_HOURS', 168),
        ],
        'nps' => [
            'enabled' => env('SUPPORT_NPS_ENABLED', true),
            'ttl_hours' => env('SUPPORT_NPS_TTL_HOURS', 720),
            'cooldown_days' => env('SUPPORT_NPS_COOLDOWN_DAYS', 90),
        ],
    ],
];
