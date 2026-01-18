<?php

$cloudDisks = [];
$cloudConfigJson = $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] ?? $_ENV['LARAVEL_CLOUD_DISK_CONFIG'] ?? null;

if ($cloudConfigJson) {
    $cloudConfig = json_decode($cloudConfigJson, true) ?? [];

    foreach ($cloudConfig as $diskConfig) {
        $cloudDisks[$diskConfig['disk']] = [
            'driver' => 's3',
            'key' => $diskConfig['access_key_id'],
            'secret' => $diskConfig['access_key_secret'],
            'region' => $diskConfig['default_region'] ?? 'auto',
            'bucket' => $diskConfig['bucket'],
            'endpoint' => $diskConfig['endpoint'],
            'url' => $diskConfig['url'],
            'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'] ?? false,
            'visibility' => $diskConfig['disk'] === 'public' ? 'public' : 'private',
            'throw' => false,
            'report' => false,
        ];
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'private' => $cloudDisks['private'] ?? [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
        ],

        'faq_media' => [
            'driver' => 'local',
            'root' => storage_path('app/private/faq_media'),
            'serve' => false,
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
        ],

        'public' => $cloudDisks['public'] ?? [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
