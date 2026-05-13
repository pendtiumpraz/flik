<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FLiK Encrypted Backup Configuration
|--------------------------------------------------------------------------
|
| Drives `App\Services\Backup\BackupService` and the `flik:backup` /
| `flik:backup:restore` console commands.
|
| Pipeline:
|   dump (mysqldump|pg_dump) -> archive (tar.gz) -> encrypt (AES-256-CBC,
|   PBKDF2 key derivation) -> upload (CdnStorageContract disk) -> prune.
|
| Operational notes:
|   - `encryption_key` MUST be set in production. Generate with:
|         php artisan key:generate --show
|     and store in your secrets manager (NOT in .env on shared hosts).
|     Losing this key means losing every encrypted backup permanently.
|   - `remote_disk` is the logical CDN disk name resolved by
|     BackupService::resolveRemote(); usually 'bunny' or 's3'.
|   - `max_media_bytes_per_backup` caps the size of media bundled into the
|     archive — over the cap, the media dirs are skipped and only the SQL
|     dump is included (media should already be in the CDN).
|
*/

return [

    /*
    | The shared secret used to derive the AES-256-CBC key. PBKDF2 with a
    | random salt is applied per-backup so the on-disk ciphertext is
    | non-deterministic even with the same input.
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /*
    | Remote disk identifier — resolved by name to either the BunnyStorage
    | or S3Storage CdnStorageContract implementation.
    */
    'remote_disk' => env('BACKUP_REMOTE_DISK', 'bunny'),

    /*
    | Backups older than this number of days are pruned from local disk
    | AND remote disk during the prune step.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    | Media directories larger than this byte budget are excluded from the
    | archive. Default 5 GB. The dump always contains the database; media
    | exclusion only affects the optional `extraDirs` bundled into the tar.
    */
    'max_media_bytes_per_backup' => (int) env('BACKUP_MAX_MEDIA_BYTES', 5_000_000_000),

    /*
    | When true, every successful or failed backup run sends a notification
    | to all super_admin users. Set false to silence on noisy schedules.
    */
    'notify_admins' => (bool) env('BACKUP_NOTIFY_ADMINS', true),

    /*
    | Local working directory inside storage/app/private. Backups are
    | written here before encrypt/upload, and copies are kept for the
    | retention window for fast local restore.
    */
    'local_path' => env('BACKUP_LOCAL_PATH', 'backups'),

    /*
    | Default media directories bundled into archives when the caller does
    | not pass an explicit list. Paths are relative to base_path().
    */
    'default_extra_dirs' => [
        'storage/app/public/movies',
    ],

    /*
    | Binary names — overridable for non-standard installs (e.g. MariaDB
    | shipping mysqldump under /usr/local/opt). When the binary is missing
    | the dump step logs a warning and skips (useful in dev environments).
    */
    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BIN', 'mysqldump'),
        'pg_dump'   => env('BACKUP_PG_DUMP_BIN', 'pg_dump'),
        'openssl'   => env('BACKUP_OPENSSL_BIN', 'openssl'),
        'tar'       => env('BACKUP_TAR_BIN', 'tar'),
    ],

    /*
    | When true, encrypt step prefers the openssl binary via proc_open
    | (faster on large files). When false (or binary missing) falls back
    | to a chunked pure-PHP openssl_encrypt implementation.
    */
    'prefer_openssl_binary' => (bool) env('BACKUP_PREFER_OPENSSL_BIN', true),
];
