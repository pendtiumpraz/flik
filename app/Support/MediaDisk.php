<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for the "public media" disk — every user-uploaded
 * image (posters, backdrops, avatars, cover banners, cast photos, mirrored
 * TMDB art) is WRITTEN to and READ from this one disk.
 *
 * Locally it defaults to `public` (storage/app/public symlinked into
 * public/storage). In production set MEDIA_DISK=s3 and everything moves to
 * Google Cloud Storage — the `s3` disk is wired to the GCS interoperability
 * endpoint (see config/filesystems.php) — with zero code changes.
 *
 * Why a helper instead of scattering config('filesystems.media') everywhere:
 * the upload side and the URL-resolution side MUST agree on the disk name,
 * and the URL builder (`Storage::disk($d)->url()`) behaves identically for the
 * local public disk (→ /storage/…) and any cloud disk (→ CDN URL), so callers
 * never branch on the backend.
 */
final class MediaDisk
{
    /** Disk name that uploads land on and URLs resolve against. */
    public static function name(): string
    {
        return (string) config('filesystems.media', 'public');
    }

    /** The configured Storage disk instance. */
    public static function disk(): Filesystem
    {
        return Storage::disk(self::name());
    }

    /**
     * Resolve a stored value to a browser URL. Absolute URLs (TMDB CDN, legacy
     * OAuth avatars, hand-entered CDN links) pass through untouched; null/empty
     * → null; anything else is treated as a relative path on the media disk.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::disk()->url(ltrim($path, '/'));
    }
}
