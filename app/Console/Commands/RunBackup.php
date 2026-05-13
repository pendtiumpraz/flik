<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BackupCompletedNotification;
use App\Services\Backup\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Run the FLiK encrypted backup pipeline.
 *
 * Usage:
 *   php artisan flik:backup                 # full pipeline (dump → archive → encrypt → upload → prune)
 *   php artisan flik:backup --no-upload     # skip the remote upload step (local-only run)
 *   php artisan flik:backup --dry           # don't actually do anything destructive — log only
 */
class RunBackup extends Command
{
    protected $signature = 'flik:backup
        {--no-upload : Skip the remote upload step (write the encrypted archive locally only).}
        {--dry : Dry-run — log the steps that would execute without dumping/archiving/uploading.}';

    protected $description = 'Run the encrypted backup pipeline (dump → archive → encrypt → upload → prune).';

    public function __construct(protected BackupService $backups)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = CarbonImmutable::now();
        $start = microtime(true);

        $report = [
            'success'         => false,
            'started_at'      => $startedAt->toIso8601String(),
            'finished_at'     => null,
            'duration_seconds' => 0.0,
            'steps'           => [],
            'remote_path'     => null,
            'remote_disk'     => null,
            'error'           => null,
        ];

        if ($this->option('dry')) {
            $this->info('DRY RUN — no files will be created and nothing will be uploaded.');
            foreach (['dump', 'archive', 'encrypt', 'upload', 'prune'] as $step) {
                $this->line("  would run: {$step}");
                $report['steps'][] = ['name' => $step, 'status' => 'skipped', 'detail' => 'dry-run'];
            }
            $report['success'] = true;
            $report['finished_at'] = CarbonImmutable::now()->toIso8601String();
            $report['duration_seconds'] = round(microtime(true) - $start, 3);
            $this->maybeNotify($report);
            return self::SUCCESS;
        }

        try {
            // Step 1 — dump --------------------------------------------------
            $sqlPath = $this->timed('dump', $report, function () {
                $path = $this->backups->dump();
                return [$path, ['detail' => basename($path), 'bytes' => (int) (@filesize($path) ?: 0)]];
            });
            $this->line("  dump  → {$sqlPath}");

            // Step 2 — archive -----------------------------------------------
            $tarPath = $this->timed('archive', $report, function () use ($sqlPath) {
                $extras = (array) config('backup.default_extra_dirs', ['storage/app/public/movies']);
                $path = $this->backups->archive($sqlPath, $extras);
                return [$path, ['detail' => basename($path), 'bytes' => (int) (@filesize($path) ?: 0)]];
            });
            $this->line("  archive → {$tarPath}");

            // Step 3 — encrypt -----------------------------------------------
            $encPath = $this->timed('encrypt', $report, function () use ($tarPath) {
                $path = $this->backups->encrypt($tarPath);
                // Plain tar can be removed once encrypted — only keep the .enc.
                @unlink($tarPath);
                return [$path, ['detail' => basename($path), 'bytes' => (int) (@filesize($path) ?: 0)]];
            });
            $this->line("  encrypt → {$encPath}");

            // Step 4 — upload ------------------------------------------------
            if ($this->option('no-upload')) {
                $this->line('  upload  → skipped (--no-upload)');
                $report['steps'][] = ['name' => 'upload', 'status' => 'skipped', 'detail' => '--no-upload'];
            } else {
                $disk = (string) config('backup.remote_disk', 'bunny');
                $remotePath = $this->timed('upload', $report, function () use ($encPath, $disk) {
                    $remote = $this->backups->upload($encPath, $disk);
                    return [$remote, ['detail' => $remote]];
                });
                $report['remote_disk'] = $disk;
                $report['remote_path'] = $remotePath;
                $this->line("  upload  → {$disk}:{$remotePath}");
            }

            // Step 5 — prune -------------------------------------------------
            $keepDays = (int) config('backup.retention_days', 30);
            $pruneReport = $this->timed('prune', $report, function () use ($keepDays) {
                $r = $this->backups->prune($keepDays);
                $detail = sprintf(
                    'local=%d remote=%d errors=%d',
                    (int) $r['local_deleted'],
                    (int) $r['remote_deleted'],
                    count($r['errors']),
                );
                return [$r, ['detail' => $detail]];
            });
            if (! empty($pruneReport['errors'])) {
                foreach ($pruneReport['errors'] as $err) {
                    $this->warn('  prune ! ' . $err);
                }
            }
            $this->line(sprintf(
                '  prune   → %d local + %d remote deleted (>%d days)',
                (int) $pruneReport['local_deleted'],
                (int) $pruneReport['remote_deleted'],
                $keepDays,
            ));

            $report['success'] = true;
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
            $this->error('  Backup pipeline failed: ' . $e->getMessage());
            Log::error('flik:backup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $report['finished_at'] = CarbonImmutable::now()->toIso8601String();
        $report['duration_seconds'] = round(microtime(true) - $start, 3);

        $this->line('');
        $this->info(sprintf(
            'Backup %s in %.2fs.',
            $report['success'] ? 'COMPLETED' : 'FAILED',
            $report['duration_seconds'],
        ));

        $this->maybeNotify($report);

        return $report['success'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Time a step, push its result onto the report, and return the inner value.
     *
     * Callable contract: returns [returnValue, extraReportFields].
     *
     * @template T
     * @param array<string, mixed> $report  Passed by reference.
     * @param callable(): array{0: T, 1: array<string, mixed>} $work
     * @return T
     */
    protected function timed(string $name, array &$report, callable $work): mixed
    {
        $t = microtime(true);
        try {
            [$result, $extra] = $work();
            $report['steps'][] = array_merge(
                ['name' => $name, 'status' => 'ok', 'ms' => (int) round((microtime(true) - $t) * 1000)],
                $extra,
            );
            return $result;
        } catch (\Throwable $e) {
            $report['steps'][] = [
                'name'   => $name,
                'status' => 'fail',
                'ms'     => (int) round((microtime(true) - $t) * 1000),
                'detail' => substr($e->getMessage(), 0, 300),
            ];
            throw $e;
        }
    }

    /**
     * Notify all super_admins (or fall back to a single email) of the outcome.
     *
     * @param array<string, mixed> $report
     */
    protected function maybeNotify(array $report): void
    {
        if (! (bool) config('backup.notify_admins', true)) {
            return;
        }

        try {
            $admins = User::query()
                ->where('role', User::ROLE_SUPER_ADMIN)
                ->whereNotNull('email')
                ->get();

            if ($admins->isEmpty()) {
                Log::info('flik:backup — no super_admin recipients; skipping notification.');
                return;
            }

            Notification::send($admins, new BackupCompletedNotification($report));
        } catch (\Throwable $e) {
            Log::warning('flik:backup — failed to send admin notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
