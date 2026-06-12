<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Public Media Disk
    |--------------------------------------------------------------------------
    |
    | Disk used for ALL user-uploaded public media (posters, backdrops, cast
    | photos, avatars, cover banners, mirrored TMDB art). Read + written via
    | App\Support\MediaDisk so the upload side and URL side always agree.
    |
    | Local dev: leave as "public". Production: set MEDIA_DISK=s3 to push every
    | upload to Google Cloud Storage (the s3 disk below targets the GCS
    | interoperability endpoint). No code changes needed to switch.
    |
    */

    'media' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],

        // Private disk — server-only, NEVER symlinked into public/.
        // Backs GDPR data exports (signed-URL gated downloads), audit dumps,
        // and any other PII payload that must not be web-reachable.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
        ],

        // S3-compatible disk. Works with AWS S3 and any S3-API-compatible
        // backend — including Google Cloud Storage via its XML / "inter-
        // operability" endpoint (https://storage.googleapis.com) using an
        // HMAC key as the AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY pair.
        // For GCS set AWS_DEFAULT_REGION=auto and AWS_USE_PATH_STYLE_ENDPOINT=true.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
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
