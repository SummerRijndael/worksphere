<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA v3 Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Google reCAPTCHA v3 credentials here. You can obtain
    | these from https://www.google.com/recaptcha/admin
    |
    */

    'enabled' => env('RECAPTCHA_ENABLED', false),

    'site_key' => env('RECAPTCHA_SITE_KEY', ''),

    'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),

    'v2_site_key' => env('RECAPTCHA_V2_SITE_KEY', ''),

    'v2_secret_key' => env('RECAPTCHA_V2_SECRET_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Score Threshold
    |--------------------------------------------------------------------------
    |
    | The minimum score required for a request to be considered valid.
    | reCAPTCHA v3 returns a score between 0.0 and 1.0, where 1.0 is very
    | likely a good interaction and 0.0 is very likely a bot.
    |
    | Recommended: 0.5 for most sites
    |
    */

    'score_threshold' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Verification URL
    |--------------------------------------------------------------------------
    |
    | The Google reCAPTCHA API endpoint for verification.
    |
    */

    'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',

    /*
    |--------------------------------------------------------------------------
    | Protected Actions
    |--------------------------------------------------------------------------
    |
    | Define which actions should be protected by reCAPTCHA. The key is the
    | action name (used for analytics) and the value is the minimum score.
    |
    */

    'actions' => [
        'login' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
        'register' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
        'forgot_password' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
        'contact' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
        'support_ticket' => env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
    ],

];
