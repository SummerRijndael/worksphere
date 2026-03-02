<?php

return [
    'task' => [
        'auto_archive' => [
            'enabled' => env('TASK_AUTO_ARCHIVE_ENABLED', true),
            'days_after_completion' => env('TASK_AUTO_ARCHIVE_DAYS', 30),
        ],
    ],
    'meetings' => [
        // Meeting Participant Limits
        // If true, the system will use the pro tier limits (50 max).
        // If false, it falls back to the legacy free tier limits (25 max).
        // Using MEETING_RECORDING_ENABLED as the global pro tier toggle.
        'pro_mode' => env('MEETING_RECORDING_ENABLED', false),
        
        'limits' => [
            'free_max_participants' => 25,
            'pro_max_participants' => 50,
        ],
    ],
];
