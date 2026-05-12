<?php

namespace App\Services\Storage;

use App\Contracts\Storage\CdnStorageContract;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * S3 driver — used as the cold-storage / backup tier for master files
 * (originals, ProRes mezzanines, raw subtitle archives) while Bunny
 * fronts the public-facing CDN delivery.
 *
 * Thin wrapper over Laravel's Storage::disk('s3') so the rest of the
 * app can depend on the CdnStorageContract abstraction. Adds two
 * convenience methods that are S3-specific:
 *  - temporaryUrl()        — server-side presigned GET for downloads.
 *  - presignedUploadUrl()  — presigned PUT for browser direct uploads.
 *
 * The underlying disk is Flysystem-backed (league/flysystem-aws-s3-v3),
 * which generates signed URLs via the AWS SDK; we surface that via the
 * filesystem adapter rather than building it manually.
 */
class S3StorageService implements CdnStorageContract
{
    protected string $disk;

    public function __construct(string $disk = 's3')
    {
        $this->disk = $disk;
    }

    public function put(string $path, string $contents, array $headers = []): bool
    {
        try {
            $options = [];

            if (! empty($headers['Content-Type'])) {
                $options['ContentType'] = $headers['Content-Type'];
            }
            if (! empty($headers['Cache-Control'])) {
                $options['CacheControl'] = $headers['Cache-Control'];
            }
            if (! empty($headers['ACL'])) {
                $options['ACL'] = $headers['ACL'];
            }

            return $this->driver()->put($path, $contents, $options);
        } catch (\Throwable $e) {
            Log::error('S3 put() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  resource  $resource
     */
    public function putStream(string $path, $resource): bool
    {
        if (! is_resource($resource)) {
            Log::error('S3 putStream() received non-resource', [
                'path' => $path,
                'type' => gettype($resource),
            ]);

            return false;
        }

        try {
            // Storage::putFileAs requires an UploadedFile; for raw streams
            // use writeStream from the underlying Flysystem adapter.
            return $this->driver()->writeStream($path, $resource);
        } catch (\Throwable $e) {
            Log::error('S3 putStream() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            return $this->driver()->delete($path);
        } catch (\Throwable $e) {
            Log::error('S3 delete() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->driver()->exists($path);
        } catch (\Throwable $e) {
            Log::error('S3 exists() exception', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Time-limited presigned GET URL (download).
     */
    public function temporaryUrl(string $path, int $ttlSeconds = 3600): string
    {
        return $this->driver()->temporaryUrl(
            $path,
            now()->addSeconds(max(1, $ttlSeconds))
        );
    }

    /**
     * Presigned PUT URL for browser/native client direct uploads.
     *
     * Returns:
     *   ['url' => string, 'headers' => array<string, string>]
     *
     * The client must PUT the file body to $url with the supplied headers.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignedUploadUrl(string $path, int $ttlSeconds = 900): array
    {
        $driver = $this->driver();

        // Laravel 12: Filesystem has temporaryUploadUrl() on S3 adapters
        // and returns ['url' => ..., 'headers' => ...].
        if (method_exists($driver, 'temporaryUploadUrl')) {
            $result = $driver->temporaryUploadUrl(
                $path,
                now()->addSeconds(max(1, $ttlSeconds))
            );

            // Normalise to expected shape.
            return [
                'url'     => $result['url'] ?? (string) $result,
                'headers' => $result['headers'] ?? [],
            ];
        }

        // Fallback: drop down to the raw AWS S3 client for a presigned PUT.
        // This path is exercised on adapters that don't expose
        // temporaryUploadUrl() (older flysystem-aws-s3-v3 builds).
        $adapter = $driver->getAdapter();
        $client  = method_exists($adapter, 'getClient') ? $adapter->getClient() : null;
        $bucket  = config("filesystems.disks.{$this->disk}.bucket");

        if ($client === null || $bucket === null) {
            throw new \RuntimeException('S3StorageService::presignedUploadUrl requires AWS SDK client + bucket config.');
        }

        $command = $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key'    => $path,
        ]);

        $request = $client->createPresignedRequest($command, '+' . max(1, $ttlSeconds) . ' seconds');

        return [
            'url'     => (string) $request->getUri(),
            'headers' => ['Content-Type' => 'application/octet-stream'],
        ];
    }

    /**
     * URL builders required by CdnStorageContract.
     *
     * Public S3 URLs assume the bucket / object is publicly readable
     * (or fronted by CloudFront). For private buckets prefer
     * temporaryUrl() instead.
     */
    public function signedUrl(string $path, int $ttlSeconds = 3600): string
    {
        return $this->temporaryUrl($path, $ttlSeconds);
    }

    public function publicUrl(string $path): string
    {
        return $this->driver()->url($path);
    }

    protected function driver(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
