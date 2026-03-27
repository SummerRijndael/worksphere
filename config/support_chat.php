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
    'agent_roles' => ['administrator'],
    'agent_permissions' => [
        'support.chats.view',
        'support.chats.reply',
        'support.chats.assign',
        'support.chats.resolve',
        'tickets.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Adapter
    |--------------------------------------------------------------------------
    |
    | legacy: Existing role/permission based behavior.
    | skills: Skill membership + role capability routing.
    |
    */
    'access_adapter' => env('SUPPORT_ACCESS_ADAPTER', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Skill Routing + Membership Capabilities
    |--------------------------------------------------------------------------
    */
    'skills' => [
        'enabled' => env('SUPPORT_SKILLS_ENABLED', false),
        'allow_legacy_fallback' => env('SUPPORT_SKILLS_ALLOW_LEGACY_FALLBACK', true),
        'allow_unrouted_conversation_fallback' => env('SUPPORT_SKILLS_ALLOW_UNROUTED_FALLBACK', true),
        'default_skill_slug' => env('SUPPORT_DEFAULT_SKILL_SLUG', 'general-support'),
        'global_admin_roles' => ['administrator'],
        'global_admin_permissions' => ['tickets.manage'],

        // Queue/ability capabilities aligned for lead/sme/qa/agent role split.
        'role_capabilities' => [
            'team_lead' => ['view_queue', 'reply', 'assign', 'resolve', 'monitor'],
            'sme' => ['view_queue', 'reply', 'assign', 'resolve'],
            'qa' => ['view_queue', 'reply', 'resolve'],
            'agent' => ['view_queue', 'reply', 'resolve'],
        ],
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
        'talk to human',
        'real person',
        'human agent',
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
    | Auto Routing Queue
    |--------------------------------------------------------------------------
    */
    'routing' => [
        'enabled' => env('SUPPORT_ROUTING_ENABLED', true),
        'engine' => env('SUPPORT_ROUTING_ENGINE', 'database'), // 'database' or 'acd'
        'queue' => env('SUPPORT_ROUTING_QUEUE', env('SUPPORT_CHAT_JOBS_QUEUE', 'chats')),
        'default_agent_capacity' => (int) env('SUPPORT_ROUTING_DEFAULT_AGENT_CAPACITY', 3),
        'max_attempts' => (int) env('SUPPORT_ROUTING_MAX_ATTEMPTS', 20),
        'retry_delay_seconds' => (int) env('SUPPORT_ROUTING_RETRY_DELAY_SECONDS', 15),
        'lock_seconds' => (int) env('SUPPORT_ROUTING_LOCK_SECONDS', 15),
        'stale_routing_seconds' => (int) env('SUPPORT_ROUTING_STALE_SECONDS', 60),
        'sweeper_batch_size' => (int) env('SUPPORT_ROUTING_SWEEPER_BATCH_SIZE', 50),
        'require_online_agent' => (bool) env('SUPPORT_ROUTING_REQUIRE_ONLINE', true),
        'require_support_available' => (bool) env('SUPPORT_ROUTING_REQUIRE_AVAILABLE', true),
        'assignment_timeout_seconds' => (int) env('SUPPORT_ASSIGNMENT_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workbench UI
    |--------------------------------------------------------------------------
    |
    | The workbench currently supports up to 5 simultaneous chat panels.
    | Keep this at 5 (or lower) unless the UI is explicitly redesigned.
    |
    */
    'workbench' => [
        'max_panels' => (int) env('SUPPORT_WORKBENCH_MAX_PANELS', 5),
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

    /*
    |--------------------------------------------------------------------------
    | UI Timers
    |--------------------------------------------------------------------------
    |
    | Configurable timing for agent-side conversation duration and
    | "last support response elapsed" counters.
    |
    */
    'ui_timers' => [
        'tick_ms' => env('SUPPORT_UI_TIMER_TICK_MS', 1000),
        'last_response_warn_minutes' => env('SUPPORT_UI_LAST_RESPONSE_WARN_MINUTES', 5),
        'last_response_alert_minutes' => env('SUPPORT_UI_LAST_RESPONSE_ALERT_MINUTES', 15),
        'last_response_include_bot' => env('SUPPORT_UI_LAST_RESPONSE_INCLUDE_BOT', true),
    ],
];
