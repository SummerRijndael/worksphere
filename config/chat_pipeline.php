<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meeting Chat Adapter
    |--------------------------------------------------------------------------
    |
    | Allows fast rollback to legacy MeetingController chat flow if needed.
    |
    */
    'meeting_chat_adapter_enabled' => env('MEETING_CHAT_ADAPTER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Meeting Chat Adapter Key
    |--------------------------------------------------------------------------
    |
    | Adapter key registered in App\Services\Chat\Adapters\AdapterFactory.
    |
    */
    'meeting_chat_adapter' => env('MEETING_CHAT_ADAPTER', 'meeting'),

    /*
    |--------------------------------------------------------------------------
    | Meeting Chat Media Signed URL TTL (minutes)
    |--------------------------------------------------------------------------
    */
    'meeting_chat_media_url_ttl_minutes' => env('MEETING_CHAT_MEDIA_URL_TTL_MINUTES', 30),
];
