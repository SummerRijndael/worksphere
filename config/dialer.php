<?php

return [
    'default_adapter' => env('DIALER_ADAPTER', 'demo'),
    'history_limit' => (int) env('DIALER_HISTORY_LIMIT', 15),
    'demo' => [
        'label' => 'Demo Line',
        'caller_id' => env('DIALER_DEMO_CALLER_ID', '+10000000000'),
    ],
    'acd_pipe' => [
        'prepared' => (bool) env('DIALER_ACD_PIPE_PREPARED', true),
        'connected' => (bool) env('DIALER_ACD_PIPE_CONNECTED', false),
    ],
];
