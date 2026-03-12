<?php

$cloudDisks = [];
$cloudConfigJson = env('LARAVEL_CLOUD_DISK_CONFIG');

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

$autoCloudStorage = env('FILESYSTEM_AUTO_CLOUD', true);

$hasR2Config = ! empty(env('R2_ACCESS_KEY_ID'))
    && ! empty(env('R2_SECRET_ACCESS_KEY'))
    && ! empty(env('R2_BUCKET'))
    && ! empty(env('R2_ENDPOINT'));

$hasS3Config = ! empty(env('AWS_ACCESS_KEY_ID'))
    && ! empty(env('AWS_SECRET_ACCESS_KEY'))
    && ! empty(env('AWS_BUCKET'));

$buildR2Disk = static function (string $visibility) {
    return [
        'driver' => 's3',
        'key' => env('R2_ACCESS_KEY_ID'),
        'secret' => env('R2_SECRET_ACCESS_KEY'),
        'region' => env('R2_REGION', 'auto'),
        'bucket' => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
        'url' => env('R2_URL'),
        'use_path_style_endpoint' => false,
        'visibility' => $visibility,
        'throw' => false,
        'report' => false,
    ];
};

$buildS3Disk = static function (string $visibility) {
    return [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'visibility' => $visibility,
        'throw' => false,
        'report' => false,
    ];
};

$localPrivateDisk = [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'serve' => false,
    'throw' => false,
    'report' => false,
    'visibility' => 'private',
];

$localPublicDisk = [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => env('APP_URL').'/storage',
    'visibility' => 'public',
    'throw' => false,
    'report' => false,
];

$resolvedPrivateDisk = $cloudDisks['private'] ?? null;
if (! $resolvedPrivateDisk) {
    if ($autoCloudStorage && $hasR2Config) {
        $resolvedPrivateDisk = $buildR2Disk('private');
    } elseif ($autoCloudStorage && $hasS3Config) {
        $resolvedPrivateDisk = $buildS3Disk('private');
    } else {
        $resolvedPrivateDisk = $localPrivateDisk;
    }
}

$resolvedPublicDisk = $cloudDisks['public'] ?? null;
if (! $resolvedPublicDisk) {
    if ($autoCloudStorage && $hasR2Config) {
        $resolvedPublicDisk = $buildR2Disk('public');
    } elseif ($autoCloudStorage && $hasS3Config) {
        $resolvedPublicDisk = $buildS3Disk('public');
    } else {
        $resolvedPublicDisk = $localPublicDisk;
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

        'private' => $resolvedPrivateDisk,

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'url' => env('R2_URL'),
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'public' => $resolvedPublicDisk,

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
