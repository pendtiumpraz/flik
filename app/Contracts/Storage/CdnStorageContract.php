<?php

namespace App\Contracts\Storage;

/**
 * Contract for CDN-backed storage drivers (Bunny, S3, Azure, etc.).
 *
 * Implementations are responsible for the upload/delete primitives plus
 * URL generation (public + signed). All methods MUST handle their own
 * error logging and either return false or throw — see implementations
 * for behaviour per method.
 */
interface CdnStorageContract
{
    /**
     * Upload a file from an in-memory string.
     *
     * @param  string  $path     Object path inside the storage zone (no leading slash).
     * @param  string  $contents Raw file bytes.
     * @param  array<string, string>  $headers  Optional extra HTTP headers (e.g. content type, cache control).
     * @return bool   True on HTTP 2xx, false on failure (errors are logged).
     */
    public function put(string $path, string $contents, array $headers = []): bool;

    /**
     * Stream-upload a (potentially large) file resource.
     *
     * Implementations should chunk where the underlying API supports it
     * to avoid loading the whole payload into memory.
     *
     * @param  string             $path
     * @param  resource           $resource Open readable stream.
     * @return bool
     */
    public function putStream(string $path, $resource): bool;

    /**
     * Delete an object from storage.
     *
     * @param  string  $path
     * @return bool    True on success or if the object did not exist.
     */
    public function delete(string $path): bool;

    /**
     * Check whether an object exists.
     */
    public function exists(string $path): bool;

    /**
     * Build a time-limited signed URL (token-authenticated).
     *
     * @param  string  $path
     * @param  int     $ttlSeconds Validity window from "now".
     * @return string  Fully qualified URL.
     */
    public function signedUrl(string $path, int $ttlSeconds = 3600): string;

    /**
     * Build a public CDN URL for an object (no token).
     */
    public function publicUrl(string $path): string;
}
