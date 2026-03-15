<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chat Models
    |--------------------------------------------------------------------------
    |
    | The models used by the chat package. You can override these to use
    | your own custom models if they extend the package's base models.
    |
    */
    'models' => [
        'user' => \App\Models\User::class,
        'chat' => \WorkSphere\Chat\Models\Chat::class,
        'message' => \WorkSphere\Chat\Models\ChatMessage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | The table names used by the chat package.
    |
    */
    'tables' => [
        'chats' => 'pkg_chats',
        'messages' => 'pkg_chat_messages',
        'participants' => 'pkg_chat_participants',
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Configuration for real-time events.
    |
    */
    'broadcast' => [
        'connection' => 'reverb',
        'prefix' => 'chat',
    ],
];
