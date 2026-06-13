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
    | Master Video Disk
    |--------------------------------------------------------------------------
    |
    | Where raw uploaded master videos (the transcode source) are stored.
    | These are large + sensitive (DRM source) and must NOT be public, and at
    | catalog scale (tens of TB) cannot live on the VM. Point at a PRIVATE GCS
    | disk (e.g. gcs_master → flik-masters bucket). The transcoding pipeline
    | streams the master from this disk into a local work area for ffmpeg, so
    | the VM only needs scratch space for in-flight jobs — not the whole library.
    |
    | Local dev: leave as "local". Production: set MASTER_DISK=gcs_master.
    |
    */

    'master' => env('MASTER_DISK', 'local'),

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
            // GCS (and other S3-compatible backends) reject the default
            // flexible-checksum headers (CRC32) the AWS SDK started sending —
            // PutObject fails with "InvalidArgument". Force the SDK to only
            // add checksums when the operation strictly requires it. Reads the
            // env override when present so AWS-proper deployments can opt back.
            'request_checksum_calculation' => env('AWS_REQUEST_CHECKSUM_CALCULATION', 'when_required'),
            'response_checksum_validation' => env('AWS_RESPONSE_CHECKSUM_VALIDATION', 'when_required'),
            'throw' => false,
        ],

        // Google Cloud Storage NATIVE disk (driver registered in
        // AppServiceProvider::boot). Keyless via Application Default Credentials
        // — the GCE VM's service account. Use this (MEDIA_DISK=gcs) instead of
        // the s3 disk when the bucket enforces uniform bucket-level access, since
        // the S3-interop adapter always sends ACLs which UBLA rejects.
        // Reuses AWS_BUCKET / AWS_URL so no extra env is required.
        'gcs' => [
            'driver' => 'gcs',
            'project_id' => env('GOOGLE_CLOUD_PROJECT', env('GOOGLE_CLOUD_PROJECT_ID')),
            'bucket' => env('GCS_BUCKET', env('AWS_BUCKET')),
            'root' => env('GCS_ROOT', ''),
            'url' => env('GCS_URL', env('AWS_URL')),
            'visibility' => 'public',
            'throw' => false,
        ],

        // PRIVATE GCS disk for master videos (transcode sources). Same native
        // 'gcs' driver (keyless ADC) but a separate, private bucket — NO 'url'
        // since masters are never served directly (HLS output goes to Bunny CDN).
        // Use a cheap storage class (Coldline/Archive) on the bucket for scale.
        'gcs_master' => [
            'driver' => 'gcs',
            'project_id' => env('GOOGLE_CLOUD_PROJECT', env('GOOGLE_CLOUD_PROJECT_ID')),
            'bucket' => env('GCS_MASTER_BUCKET', 'flik-masters'),
            'root' => env('GCS_MASTER_ROOT', ''),
            'visibility' => 'private',
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
