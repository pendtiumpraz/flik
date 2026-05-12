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

    public function __construct()
    {
        $this->storageZone      = (string) (config('services.bunny.storage_zone') ?? env('BUNNY_STORAGE_ZONE', ''));
        $this->storageKey       = (string) (config('services.bunny.storage_key') ?? env('BUNNY_STORAGE_KEY', ''));
        $this->storageHostname  = (string) (config('services.bunny.storage_hostname') ?? env('BUNNY_STORAGE_HOSTNAME', 'storage.bunnycdn.com'));
        $this->pullZoneUrl      = rtrim((string) (config('services.bunny.pull_zone_url') ?? env('BUNNY_PULL_ZONE_URL', '')), '/');
        $this->pullZoneTokenKey = config('services.bunny.pull_zone_token_key') ?? env('BUNNY_TOKEN_KEY');

        if ($this->storageZone === '' || $this->storageKey === '') {
            throw new \RuntimeException(
                'BunnyStorageService requires BUNNY_STORAGE_ZONE and BUNNY_STORAGE_KEY to be configured.'
            );
        }
    }

    /**
     * Upload an in-memory string to the storage zone.
     */
    public function put(string $path, string $contents, array $headers = []): bool
    {
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
        if (empty($this->pullZoneUrl)) {
            throw new \RuntimeException('BunnyStorageService::signedUrl requires BUNNY_PULL_ZONE_URL.');
        }

        if (empty($this->pullZoneTokenKey)) {
            throw new \RuntimeException('BunnyStorageService::signedUrl requires BUNNY_TOKEN_KEY (pull-zone token auth key).');
        }

        $signedPath = '/' . ltrim($path, '/');
        $expires    = time() + max(1, $ttlSeconds);

        $hash = hash('sha256', $this->pullZoneTokenKey . $signedPath . $expires, true);
        $token = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

        return $this->pullZoneUrl . $signedPath . '?token=' . $token . '&expires=' . $expires;
    }

    public function publicUrl(string $path): string
    {
        if (empty($this->pullZoneUrl)) {
            throw new \RuntimeException('BunnyStorageService::publicUrl requires BUNNY_PULL_ZONE_URL.');
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
