<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Avatar Storage
    |--------------------------------------------------------------------------
    |
    | Configure the disk and paths for avatar storage.
    |
    */
    'disk' => env('AVATAR_DISK', 'public'),
    'path' => 'avatars',

    /*
    |--------------------------------------------------------------------------
    | Size Limits
    |--------------------------------------------------------------------------
    |
    | Maximum file size for avatar uploads (in KB).
    |
    */
    'max_size_kb' => 3072, // 3MB

    /*
    |--------------------------------------------------------------------------
    | Image Sizes
    |--------------------------------------------------------------------------
    |
    | Define the image sizes generated for avatars.
    |
    */
    'sizes' => [
        'thumb' => ['width' => 100, 'height' => 100],
        'optimized' => ['width' => 800, 'height' => 800],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    |
    | Settings for image processing during upload.
    |
    */
    'format' => 'webp',
    'quality' => 85,
    'strip_exif' => true,

    /*
    |--------------------------------------------------------------------------
    | Fallback Avatar
    |--------------------------------------------------------------------------
    |
    | Default avatar when no avatar is set.
    |
    */
    'fallback' => '/static/images/avatar/blank.png',

    /*
    |--------------------------------------------------------------------------
    | Social Avatar Sync
    |--------------------------------------------------------------------------
    |
    | Settings for syncing avatars from social providers.
    |
    */
    'social_sync' => [
        'enabled' => true,
        'compare_filename' => true, // Skip download if filename matches
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Chat Avatars
    |--------------------------------------------------------------------------
    |
    | Settings for auto-generated group chat avatars.
    |
    */
    'group_chat' => [
        'max_visible_participants' => 3, // Show (u1)(u2)(u3)(4+)
        'fallback_single' => '/static/images/avatar/group-single.png',
        'fallback_group' => '/static/images/avatar/group.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Initials Avatar Colors
    |--------------------------------------------------------------------------
    |
    | Color palette for initials-based fallback avatars.
    |
    */
    'colors' => [
        '#6366f1', // Indigo
        '#8b5cf6', // Violet
        '#a855f7', // Purple
        '#ec4899', // Pink
        '#f43f5e', // Rose
        '#ef4444', // Red
        '#f97316', // Orange
        '#eab308', // Yellow
        '#22c55e', // Green
        '#14b8a6', // Teal
        '#06b6d4', // Cyan
        '#3b82f6', // Blue
    ],
];
