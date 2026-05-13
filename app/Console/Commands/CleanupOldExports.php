<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Privacy\UserDataExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * CleanupOldExports
 * --------------------------------------------------------------------------
 * GDPR data exports are kept on the `private` disk until either the user
 * downloads them OR this sweep nukes anything older than --days (default 7).
 *
 * The 24-hour signed download URL expires long before this sweep runs, so
 * any stragglers represent users who requested an export and never came
 * back to download it. Holding their PII indefinitely would itself be a
 * GDPR violation — we minimise.
 *
 * Designed to run nightly. Safe to run multiple times: nothing is touched
 * unless its mtime is older than the cutoff, and missing-files are logged
 * and skipped.
 *
 * Usage:
 *   php artisan flik:privacy:cleanup-exports
 *   php artisan flik:privacy:cleanup-exports --days=14
 *   php artisan flik:privacy:cleanup-exports --dry        # log only
 */
class CleanupOldExports extends Command
{
    protected $signature = 'flik:privacy:cleanup-exports
                            {--days=7 : Maximum age of an export file in days before deletion.}
                            {--dry : Build the deletion list and report counts but do not touch the disk.}';

    protected $description = 'Delete GDPR data export files older than --days (default 7) from storage/app/private/exports.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $disk = Storage::disk('private');
        $dir  = UserDataExporter::EXPORT_DIR;

        if (! $disk->exists($dir)) {
            $this->info("No export directory at private://{$dir} — nothing to clean.");
            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        $files  = $disk->files($dir);

        $deleted = 0;
        $kept    = 0;
        $bytesFreed = 0;
        $errors  = 0;

        foreach ($files as $path) {
            // Only touch files that match our naming convention. Anything else
            // in the directory is foreign and left alone — better to leak a
            // few bytes than nuke an admin's manual upload by mistake.
            if (! preg_match('#^'.preg_quote($dir, '#').'/user_\d+_\d+\.json$#', $path)) {
                $kept++;
                continue;
            }

            try {
                $mtime = $disk->lastModified($path);
            } catch (\Throwable $e) {
                $this->warn("Could not stat {$path}: {$e->getMessage()}");
                $errors++;
                continue;
            }

            if ($mtime > $cutoff) {
                $kept++;
                continue;
            }

            $size = 0;
            try {
                $size = $disk->size($path);
            } catch (\Throwable) {
                // best-effort
            }

            if ($dry) {
                $this->line(sprintf('  [dry] would delete %s (%s, %s)',
                    $path,
                    $this->humanBytes($size),
                    Carbon::createFromTimestamp($mtime)->diffForHumans(),
                ));
                $deleted++;
                $bytesFreed += $size;
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
                $bytesFreed += $size;
            } else {
                $this->warn("Failed to delete {$path}");
                $errors++;
            }
        }

        $this->info(sprintf(
            '%s %d export file(s), kept %d, freed %s%s.',
            $dry ? '[dry] Would delete' : 'Deleted',
            $deleted,
            $kept,
            $this->humanBytes($bytesFreed),
            $errors > 0 ? " ({$errors} error(s))" : '',
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Format a byte count as a human-readable string for the CLI report.
     */
    protected function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024) {
                return sprintf('%.1f %s', $value, $unit);
            }
            $value /= 1024;
        }
        return sprintf('%.1f PB', $value);
    }
}
