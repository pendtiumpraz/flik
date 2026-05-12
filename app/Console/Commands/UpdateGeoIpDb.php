<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Geo\GeoIpResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Throwable;

/**
 * Download the latest MaxMind GeoLite2-Country.mmdb file.
 *
 * Usage:
 *   php artisan flik:geo:update
 *   php artisan flik:geo:update --force   # overwrite even if mtime is recent
 *
 * Requires MAXMIND_LICENSE_KEY in .env. Without it the command exits
 * cleanly with a warning so it is safe to wire into the scheduler in
 * environments that intentionally skip MaxMind (e.g. dev / CI).
 */
class UpdateGeoIpDb extends Command
{
    protected $signature = 'flik:geo:update
        {--force : Re-download even if the existing database is fresh (<7 days old)}';

    protected $description = 'Download/refresh the MaxMind GeoLite2-Country database';

    private const FRESHNESS_DAYS = 7;

    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    public function handle(GeoIpResolver $resolver): int
    {
        if (!$resolver->hasLicenseKey()) {
            $this->warn('MAXMIND_LICENSE_KEY is not set — skipping GeoIP database update.');
            $this->line('Sign up at https://www.maxmind.com/en/geolite2/signup for a free key.');

            return self::SUCCESS;
        }

        $targetPath = $resolver->databasePath();
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir) && !File::makeDirectory($targetDir, 0755, true, true)) {
            $this->error("Unable to create target directory: {$targetDir}");

            return self::FAILURE;
        }

        if (!$this->option('force') && $this->isFresh($targetPath)) {
            $this->info(sprintf(
                'Database is already fresh (<%d days old) at %s — use --force to override.',
                self::FRESHNESS_DAYS,
                $targetPath,
            ));

            return self::SUCCESS;
        }

        $url = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=%s&suffix=tar.gz',
            urlencode((string) $resolver->licenseKey()),
        );

        $tarPath = $targetDir . DIRECTORY_SEPARATOR . 'GeoLite2-Country.tar.gz';
        $extractDir = $targetDir . DIRECTORY_SEPARATOR . 'extract-' . bin2hex(random_bytes(4));

        $this->info('Downloading GeoLite2-Country database from MaxMind...');

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->withOptions(['sink' => $tarPath])
                ->get($url);

            if (!$response->successful()) {
                $this->error("Download failed (HTTP {$response->status()}). Check your license key.");
                $this->cleanup($tarPath, $extractDir);

                return self::FAILURE;
            }

            if (!is_file($tarPath) || filesize($tarPath) < 1024) {
                $this->error('Downloaded archive is missing or too small — aborting.');
                $this->cleanup($tarPath, $extractDir);

                return self::FAILURE;
            }

            $this->info('Extracting archive...');

            File::ensureDirectoryExists($extractDir);

            // PharData handles .tar.gz: decompress first, then extract.
            $phar = new PharData($tarPath);
            $phar->decompress(); // produces .tar alongside .tar.gz
            $tarOnly = preg_replace('/\.gz$/', '', $tarPath);

            if (!is_string($tarOnly) || !is_file($tarOnly)) {
                $this->error('Failed to decompress .tar.gz archive.');
                $this->cleanup($tarPath, $extractDir);

                return self::FAILURE;
            }

            $tarPhar = new PharData($tarOnly);
            $tarPhar->extractTo($extractDir, null, true);

            // Locate the .mmdb (nested in a versioned directory like
            // GeoLite2-Country_20260512/GeoLite2-Country.mmdb).
            $found = $this->findMmdb($extractDir);

            if ($found === null) {
                $this->error('Could not locate GeoLite2-Country.mmdb inside the archive.');
                $this->cleanup($tarPath, $extractDir, $tarOnly);

                return self::FAILURE;
            }

            // Atomic-ish replace.
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            if (!@rename($found, $targetPath)) {
                if (!@copy($found, $targetPath)) {
                    $this->error("Failed to move .mmdb into place at {$targetPath}");
                    $this->cleanup($tarPath, $extractDir, $tarOnly);

                    return self::FAILURE;
                }
            }

            $this->cleanup($tarPath, $extractDir, $tarOnly);

            $this->info(sprintf(
                'GeoIP database updated: %s (%s bytes).',
                $targetPath,
                number_format((int) filesize($targetPath)),
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('GeoIP update failed: ' . $e->getMessage());
            $this->cleanup($tarPath, $extractDir, $tarOnly ?? null);

            return self::FAILURE;
        }
    }

    private function isFresh(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $age = time() - (int) filemtime($path);

        return $age < (self::FRESHNESS_DAYS * 86400);
    }

    private function findMmdb(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'mmdb') {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function cleanup(?string ...$paths): void
    {
        foreach ($paths as $path) {
            if ($path === null || $path === '') {
                continue;
            }

            try {
                if (is_dir($path)) {
                    File::deleteDirectory($path);
                } elseif (is_file($path)) {
                    @unlink($path);
                }
            } catch (Throwable) {
                // Best-effort cleanup.
            }
        }
    }
}
