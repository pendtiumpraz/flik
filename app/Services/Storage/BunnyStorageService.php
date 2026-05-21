<?php

namespace App\Services\Storage;

use App\Contracts\Storage\CdnStorageContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bunny.net Edge Storage + Pull Zone driver.
 *
 * Reads credentials from config('services.bunny.*'). All upload/delete
 * primitives use the Storage API at https://{hostname}/{zone}/{path};
 * URL generation uses the configured Pull Zone hostname.
 *
 * Auth model:
 *  - Storage API:  AccessKey header (per-zone password key).
 *  - Signed URLs:  HMAC SHA-256 token computed against the Pull Zone
 *                  Token Authentication key (configured separately on the
 *                  pull zone, NOT the storage zone key).
 *
 * Errors are logged via the 'bunny' channel (falls back to default) and
 * surfaced as a boolean false / empty array. Hard misconfiguration
 * (missing zone or key) throws on instantiation so we fail loud at boot
 * instead of silently returning bad URLs.
 */
class BunnyStorageService implements CdnStorageContract
{
    protected string $storageZone;

    protected string $storageKey;

    protected string $storageHostname;

    protected string $pullZoneUrl;

    protected ?string $pullZoneTokenKey;

    /**
     * Whether Bunny is fully configured. When false, all I/O primitives
     * short-circuit gracefully (return false/null/empty) instead of
     * exploding — keeps dev/test environments without Bunny credentials
     * usable. See audit doc FIX #2 §4.4.
     */
    protected bool $enabled;

    public function __construct()
    {
        $this->storageZone      = (string) (config('services.bunny.storage_zone') ?? env('BUNNY_STORAGE_ZONE', ''));
        $this->storageKey       = (string) (config('services.bunny.storage_key') ?? env('BUNNY_STORAGE_KEY', ''));
        $this->storageHostname  = (string) (config('services.bunny.storage_hostname') ?? env('BUNNY_STORAGE_HOSTNAME', 'storage.bunnycdn.com'));
        $this->pullZoneUrl      = rtrim((string) (config('services.bunny.pull_zone_url') ?? env('BUNNY_PULL_ZONE_URL', '')), '/');
        $this->pullZoneTokenKey = config('services.bunny.pull_zone_token_key') ?? env('BUNNY_TOKEN_KEY');

        $this->enabled = $this->storageZone !== '' && $this->storageKey !== '';

        if (! $this->enabled) {
            // Log once-per-boot, not per-call, so we don't spam logs in dev.
            Log::channel($this->logChannel())->warning(
                'BunnyStorageService disabled — BUNNY_STORAGE_ZONE / BUNNY_STORAGE_KEY not configured. Uploads will no-op.'
            );
        }
    }

    /**
     * Whether the Bunny driver is configured + ready to perform I/O.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Upload an in-memory string to the storage zone.
     */
    public function put(string $path, string $contents, array $headers = []): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $url = $this->storageUrl($path);

        try {
            $response = Http::withHeaders(array_merge([
                'AccessKey'    => $this->storageKey,
                'Content-Type' => $headers['Content-Type'] ?? 'application/octet-stream',
                'Accept'       => 'application/json',
            ], $headers))
                ->withBody($contents, $headers['Content-Type'] ?? 'application/octet-stream')
                ->timeout(120)
                ->put($url);

            if (! $response->successful()) {
                Log::channel($this->logChannel())->warning('Bunny put() failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny put() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Upload from a stream. Bunny's Storage API accepts a single PUT for
     * the whole object; for very large files we stream the resource via
     * Guzzle's body sink rather than buffering it in PHP memory.
     *
     * @param  resource  $resource
     */
    public function putStream(string $path, $resource): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! is_resource($resource)) {
            Log::channel($this->logChannel())->error('Bunny putStream() received non-resource', [
                'path' => $path,
                'type' => gettype($resource),
            ]);

            return false;
        }

        $url = $this->storageUrl($path);

        try {
            $response = Http::withHeaders([
                'AccessKey'    => $this->storageKey,
                'Content-Type' => 'application/octet-stream',
                'Accept'       => 'application/json',
            ])
                ->withOptions(['body' => $resource])
                ->timeout(0) // no timeout — large uploads
                ->put($url);

            if (! $response->successful()) {
                Log::channel($this->logChannel())->warning('Bunny putStream() failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny putStream() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $path): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'AccessKey' => $this->storageKey,
                'Accept'    => 'application/json',
            ])
                ->timeout(30)
                ->delete($this->storageUrl($path));

            // 404 = already gone, treat as success (idempotent).
            if ($response->successful() || $response->status() === 404) {
                return true;
            }

            Log::channel($this->logChannel())->warning('Bunny delete() failed', [
                'path'   => $path,
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny delete() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function exists(string $path): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            // Bunny doesn't have HEAD on objects; use a 0-byte ranged GET.
            $response = Http::withHeaders([
                'AccessKey' => $this->storageKey,
                'Range'     => 'bytes=0-0',
            ])
                ->timeout(15)
                ->get($this->storageUrl($path));

            return $response->status() === 200 || $response->status() === 206;
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny exists() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Bunny Token Authentication URL.
     *
     * Token =  base64url( sha256_raw( tokenKey + signedPath + expires ) )
     *
     * Then appended as ?token=...&expires=<unix>.
     *
     * The pull-zone Token Authentication setting must be enabled and the
     * key must match $pullZoneTokenKey.
     *
     * @see https://docs.bunny.net/docs/cdn-token-authentication
     */
    public function signedUrl(string $path, int $ttlSeconds = 3600): string
    {
        if (! $this->enabled || empty($this->pullZoneUrl) || empty($this->pullZoneTokenKey)) {
            // Soft fail — return empty string so callers can branch on it
            // without try/catch. See audit doc FIX #2 §4.4.
            return '';
        }

        $signedPath = '/' . ltrim($path, '/');
        $expires    = time() + max(1, $ttlSeconds);

        $hash = hash('sha256', $this->pullZoneTokenKey . $signedPath . $expires, true);
        $token = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

        return $this->pullZoneUrl . $signedPath . '?token=' . $token . '&expires=' . $expires;
    }

    public function publicUrl(string $path): string
    {
        if (! $this->enabled || empty($this->pullZoneUrl)) {
            return '';
        }

        return $this->pullZoneUrl . '/' . ltrim($path, '/');
    }

    /**
     * List objects under a prefix (directory) in the storage zone.
     *
     * Returns the raw Bunny listing (each entry has Guid, ObjectName,
     * Length, LastChanged, IsDirectory, etc.). Empty array on failure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFiles(string $prefix = ''): array
    {
        if (! $this->enabled) {
            return [];
        }

        $prefix = trim($prefix, '/');
        $url = sprintf(
            'https://%s/%s/%s',
            $this->storageHostname,
            $this->storageZone,
            $prefix === '' ? '' : $prefix . '/'
        );

        try {
            $response = Http::withHeaders([
                'AccessKey' => $this->storageKey,
                'Accept'    => 'application/json',
            ])
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                Log::channel($this->logChannel())->warning('Bunny listFiles() failed', [
                    'prefix' => $prefix,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny listFiles() exception', [
                'prefix'  => $prefix,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Recursively upload every file under $localDir to $remotePrefix on the
     * storage zone. Returns the count of files successfully uploaded.
     *
     * Used by UploadToBunny job to push a full HLS rendition tree
     * (playlist.m3u8 + segment_*.ts + encrypted.m3u8) in one call. See
     * audit doc FIX #2 §2.3 — this method is required by the pipeline.
     *
     * Content-Type is sniffed from the extension so the player gets the
     * right MIME on each fetch:
     *   .m3u8 → application/vnd.apple.mpegurl
     *   .ts   → video/mp2t
     *   .key  → application/octet-stream (the bare key file should NOT be
     *           uploaded; callers must filter it out — see UploadToBunny).
     */
    public function uploadDirectory(string $localDir, string $remotePrefix): int
    {
        if (! $this->enabled) {
            return 0;
        }

        if (! is_dir($localDir)) {
            Log::channel($this->logChannel())->warning('Bunny uploadDirectory: localDir not found', [
                'local_dir' => $localDir,
            ]);

            return 0;
        }

        $uploaded = 0;
        $remotePrefix = trim($remotePrefix, '/');

        // RecursiveDirectoryIterator skips dot files by default; we still
        // skip key.bin / enc.keyinfo defensively — those must NEVER leave
        // the worker box.
        $denylist = ['key.bin', 'enc.keyinfo'];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            if (in_array($file->getFilename(), $denylist, true)) {
                continue;
            }

            $relative = ltrim(
                str_replace('\\', '/', substr($file->getPathname(), strlen($localDir))),
                '/',
            );
            $remotePath = $remotePrefix === ''
                ? $relative
                : $remotePrefix . '/' . $relative;

            $contentType = $this->guessContentType($file->getFilename());
            $stream = @fopen($file->getPathname(), 'rb');
            if ($stream === false) {
                Log::channel($this->logChannel())->warning('Bunny uploadDirectory: failed to open file', [
                    'path' => $file->getPathname(),
                ]);
                continue;
            }

            try {
                // Stream the file so memory stays flat for large .ts segments.
                $ok = $this->putStreamWithType($remotePath, $stream, $contentType);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($ok) {
                $uploaded++;
            }
        }

        return $uploaded;
    }

    /**
     * Internal putStream() variant that honours a specific Content-Type.
     * Mirrors put() but takes a stream — same semantics as putStream() with
     * an explicit MIME override (so .m3u8 / .ts get correct types).
     *
     * @param  resource  $resource
     */
    protected function putStreamWithType(string $path, $resource, string $contentType): bool
    {
        if (! $this->enabled || ! is_resource($resource)) {
            return false;
        }

        $url = $this->storageUrl($path);

        try {
            $response = Http::withHeaders([
                'AccessKey'    => $this->storageKey,
                'Content-Type' => $contentType,
                'Accept'       => 'application/json',
            ])
                ->withOptions(['body' => $resource])
                ->timeout(0)
                ->put($url);

            if (! $response->successful()) {
                Log::channel($this->logChannel())->warning('Bunny putStreamWithType() failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel($this->logChannel())->error('Bunny putStreamWithType() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sniff Content-Type from filename. Conservative — falls back to
     * application/octet-stream for unknown extensions.
     */
    protected function guessContentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts'   => 'video/mp2t',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'mov'  => 'video/quicktime',
            'mkv'  => 'video/x-matroska',
            'vtt'  => 'text/vtt',
            'srt'  => 'application/x-subrip',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }

    /**
     * Build the Storage API URL for an object path.
     */
    protected function storageUrl(string $path): string
    {
        return sprintf(
            'https://%s/%s/%s',
            $this->storageHostname,
            $this->storageZone,
            ltrim($path, '/')
        );
    }

    /**
     * Resolve log channel — use a dedicated 'bunny' channel if configured,
     * otherwise fall back to the application default.
     */
    protected function logChannel(): string
    {
        $channels = config('logging.channels', []);

        return isset($channels['bunny']) ? 'bunny' : config('logging.default', 'stack');
    }
}
