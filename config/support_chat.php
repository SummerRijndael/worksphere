<?php

use App\Services\Support\Ai\SimulatedSupportAiAdapter;

return [
    /*
    |--------------------------------------------------------------------------
    | AI Assistant Toggle
    |--------------------------------------------------------------------------
    */
    'ai_enabled' => env('SUPPORT_AI_ENABLED', true),
    'ai_adapter' => env('SUPPORT_AI_ADAPTER', SimulatedSupportAiAdapter::class),

    /*
    |--------------------------------------------------------------------------
    | Laravel AI SDK Adapter Settings
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'assistant_name' => env('SUPPORT_AI_ASSISTANT_NAME', 'Eden'),
        'laravel_sdk' => [
            'enabled' => (bool) env('SUPPORT_AI_LARAVEL_SDK_ENABLED', true),
            'provider' => env('SUPPORT_AI_LARAVEL_SDK_PROVIDER', 'openai'),
            'model' => env('SUPPORT_AI_LARAVEL_SDK_MODEL', 'gpt-4.1-mini'),
            'timeout' => (int) env('SUPPORT_AI_LARAVEL_SDK_TIMEOUT', 20),
            'history_messages' => (int) env('SUPPORT_AI_LARAVEL_SDK_HISTORY_MESSAGES', 12),
            'system_prompt' => env('SUPPORT_AI_LARAVEL_SDK_SYSTEM_PROMPT'),
            'budgets' => [
                'incoming_message_max_chars' => (int) env('SUPPORT_AI_BUDGET_INCOMING_MESSAGE_MAX_CHARS', 1200),
                'history_message_max_chars' => (int) env('SUPPORT_AI_BUDGET_HISTORY_MESSAGE_MAX_CHARS', 500),
                'history_total_max_chars' => (int) env('SUPPORT_AI_BUDGET_HISTORY_TOTAL_MAX_CHARS', 2600),
                'instructions_max_chars' => (int) env('SUPPORT_AI_BUDGET_INSTRUCTIONS_MAX_CHARS', 9000),
                'reply_max_chars' => (int) env('SUPPORT_AI_BUDGET_REPLY_MAX_CHARS', 700),
            ],
            'tools' => [
                'account_status' => [
                    'enabled' => (bool) env('SUPPORT_AI_TOOL_ACCOUNT_STATUS_ENABLED', true),
                ],
            ],
            'handoff' => [
                'max_bot_turns_before_handoff' => (int) env('SUPPORT_AI_HANDOFF_MAX_BOT_TURNS', 6),
                'escalate_on_human_request' => (bool) env('SUPPORT_AI_HANDOFF_ON_HUMAN_REQUEST', true),
                'escalate_on_backoff_request' => (bool) env('SUPPORT_AI_HANDOFF_ON_BACKOFF_REQUEST', true),
                'escalate_on_low_confidence' => (bool) env('SUPPORT_AI_HANDOFF_ON_LOW_CONFIDENCE', true),
                'low_confidence_threshold' => (float) env('SUPPORT_AI_HANDOFF_LOW_CONFIDENCE_THRESHOLD', 0.55),
            ],
            'policy' => [
                'block_religion' => (bool) env('SUPPORT_AI_POLICY_BLOCK_RELIGION', true),
                'block_politics' => (bool) env('SUPPORT_AI_POLICY_BLOCK_POLITICS', true),
                'block_gossip' => (bool) env('SUPPORT_AI_POLICY_BLOCK_GOSSIP', true),
                'offtopic_action' => env('SUPPORT_AI_POLICY_OFFTOPIC_ACTION', 'refuse'),
                'reply_scope_guard_enabled' => (bool) env('SUPPORT_AI_POLICY_REPLY_SCOPE_GUARD_ENABLED', true),
            ],
            'gemini_context_cache' => [
                'enabled' => (bool) env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_ENABLED', false),
                'model' => env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_MODEL', ''),
                'ttl_seconds' => (int) env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_TTL_SECONDS', 900),
                'min_payload_chars' => (int) env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_MIN_PAYLOAD_CHARS', 1800),
                'max_payload_chars' => (int) env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_MAX_PAYLOAD_CHARS', 64000),
                'failure_cooldown_seconds' => (int) env('SUPPORT_AI_GEMINI_CONTEXT_CACHE_FAILURE_COOLDOWN_SECONDS', 300),
            ],
            'health_check' => [
                'enabled' => (bool) env('SUPPORT_AI_HEALTH_CHECK_ENABLED', true),
                'cache_ttl_seconds' => (int) env('SUPPORT_AI_HEALTH_CHECK_CACHE_TTL_SECONDS', 60),
                'timeout' => (int) env('SUPPORT_AI_HEALTH_CHECK_TIMEOUT', 8),
                'prompt' => env('SUPPORT_AI_HEALTH_CHECK_PROMPT', 'Reply with OK only.'),
            ],
            'faq_context' => [
                'enabled' => (bool) env('SUPPORT_AI_FAQ_CONTEXT_ENABLED', true),
                'max_articles' => (int) env('SUPPORT_AI_FAQ_CONTEXT_MAX_ARTICLES', 3),
                'max_chars_per_article' => (int) env('SUPPORT_AI_FAQ_CONTEXT_MAX_CHARS_PER_ARTICLE', 1800),
                'max_total_chars' => (int) env('SUPPORT_AI_FAQ_CONTEXT_MAX_TOTAL_CHARS', 2200),
                'cache_enabled' => (bool) env('SUPPORT_AI_FAQ_CONTEXT_CACHE_ENABLED', true),
                'cache_ttl_seconds' => (int) env('SUPPORT_AI_FAQ_CONTEXT_CACHE_TTL_SECONDS', 600),
            ],
        ],
    ],

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
