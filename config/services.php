<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio SMS Service
    |--------------------------------------------------------------------------
    |
    | Used for SMS-based two-factor authentication.
    |
    */

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'verify_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        'from' => env('TWILIO_FROM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Providers (Socialite)
    |--------------------------------------------------------------------------
    |
    | Used for social login with Google, GitHub, and Facebook.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'redirect_calendar' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        'safe_browsing_key' => env('GOOGLE_SAFE_BROWSING_KEY'),
        'pubsub_topic' => env('GOOGLE_PUBSUB_TOPIC', 'projects/'.env('GOOGLE_PROJECT_ID').'/topics/email-notifications'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
    ],

    'giphy' => [
        'key' => env('GIPHY_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare TURN/STUN (WebRTC Video Calls)
    |--------------------------------------------------------------------------
    |
    | Used for NAT traversal in peer-to-peer video/audio calls.
    | Create a TURN App in Cloudflare Dashboard → Calls to get credentials.
    | Free tier: 1 TB/month.
    |
    */

    'cloudflare' => [
        'turn_key_id' => env('TURN_KEY_ID'),
        'turn_api_token' => env('TURN_KEY_API_TOKEN'),
        'app_id' => env('CLOUDFLARE_APP_ID_SFU'),
        'app_secret' => env('CLOUDFLARE_APP_SECRET_SFU'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare RealtimeKit (PRO Meeting Recording)
    |--------------------------------------------------------------------------
    |
    | Used for server-side meeting recordings via Cloudflare RealtimeKit v2.
    |
    | Get credentials from: dash.realtime.cloudflare.com → API Keys
    |   - org_id  → "Organization ID" on the API Keys page
    |   - api_key → API Key value
    |   - app_id  → App ID after creating a RealtimeKit App
    |
    | MEETING_RECORDING_ENABLED=true acts as the dev toggle (no real
    | subscription check yet — replace with billing gate later).
    |
    */
    'cloudflare_realtime' => [
        'account_id' => env('CLOUDFLARE_REALTIME_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_REALTIME_API_TOKEN'),
        'app_id' => env('CLOUDFLARE_REALTIME_APP_ID'),
        'recording_enabled' => env('MEETING_RECORDING_ENABLED', false),
        'watermark_url' => env('RECORDING_WATERMARK_URL', env('APP_URL').'/static/images/brands/logo.svg'),
        'watermark_position' => env('RECORDING_WATERMARK_POSITION', 'right bottom'),
        'watermark_height' => (int) env('RECORDING_WATERMARK_HEIGHT', 40),
        'watermark_width' => (int) env('RECORDING_WATERMARK_WIDTH', 160),
    ],

];
