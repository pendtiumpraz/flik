<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sweep the `audit_logs` table down to its retention policy.
 *
 * Privacy Policy promises 12 months of retention for general events but
 * tax/anti-fraud rules in Indonesia want security-flagged rows kept
 * longer (UU ITE + UU PDP investigative-evidence carve-outs). The
 * --keep-security flag (default ON) excludes is_security=1 rows from
 * the delete, so we keep compliance evidence while pruning the bulk
 * of routine telemetry.
 *
 * Designed to be cheap to run weekly:
 *   - Chunked deletes (max CHUNK_SIZE rows per DB::transaction) so a
 *     misconfigured monster retention sweep can't blow the buffer pool
 *     or lock the table for minutes.
 *   - Per-chunk transaction means a mid-sweep crash leaves a consistent
 *     audit log, not a half-truncated one.
 *
 * Usage:
 *   php artisan flik:audit:prune                              # 365-day cut, keep security rows
 *   php artisan flik:audit:prune --days=730                   # 2-year retention
 *   php artisan flik:audit:prune --no-keep-security           # also delete security rows past the cut
 *   php artisan flik:audit:prune --dry                        # report what would go without writing
 */
class PruneOldAuditLogs extends Command
{
    protected $signature = 'flik:audit:prune
        {--days=365 : Delete rows older than this many days}
        {--keep-security : Exclude is_security=1 rows from the delete (default ON)}
        {--no-keep-security : Include is_security=1 rows in the delete (compliance-risky)}
        {--dry : Count matching rows but do not delete anything}';

    protected $description = 'Prune audit_logs beyond retention policy (default 365 days). Security rows kept by default.';

    /**
     * Delete in chunks of this many IDs to avoid long-running transactions.
     */
    public const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        // --keep-security defaults true. --no-keep-security flips it off.
        $keepSecurity = !$this->option('no-keep-security');
        $dry = (bool) $this->option('dry');

        $cutoff = now()->subDays($days);

        if (!Schema::hasTable('audit_logs')) {
            $this->warn('audit_logs table not found — nothing to prune.');
            return self::SUCCESS;
        }

        $hasIsSecurityColumn = Schema::hasColumn('audit_logs', 'is_security');

        $this->info(sprintf(
            'Pruning audit_logs older than %s (%d days). keep_security=%s%s',
            $cutoff->toDateTimeString(),
            $days,
            $keepSecurity ? 'YES' : 'NO',
            $dry ? ' [DRY RUN]' : ''
        ));

        try {
            $query = AuditLog::query()->where('created_at', '<', $cutoff);

            // Honour --keep-security only when the column exists; otherwise
            // fall back to action-prefix exclusion so legacy schemas don't
            // accidentally delete security rows.
            if ($keepSecurity) {
                if ($hasIsSecurityColumn) {
                    $query->where(function ($q): void {
                        $q->where('is_security', false)
                          ->orWhereNull('is_security');
                    });
                } else {
                    $query->where(function ($q): void {
                        $q->where('action', 'not like', 'auth.%')
                          ->where('action', 'not like', 'security.%')
                          ->where('action', 'not like', 'privacy.%')
                          ->where('action', 'not like', 'drm.key%')
                          ->where('action', '!=', 'payment.chargeback');
                    });
                }
            }

            $matchCount = (int) $query->count();

            if ($matchCount === 0) {
                $this->info('No rows match the prune predicate — nothing to do.');
                return self::SUCCESS;
            }

            $this->info(sprintf('%d row(s) match.', $matchCount));

            if ($dry) {
                $this->comment('Dry run — no rows deleted.');
                return self::SUCCESS;
            }

            $totalDeleted = 0;
            $bar = $this->output->createProgressBar($matchCount);
            $bar->start();

            // Loop chunk-by-chunk so each transaction stays small.
            while (true) {
                // Re-resolve the cloneable base each iteration — Eloquent
                // doesn't deep-clone scopes implicitly.
                $idsQuery = AuditLog::query()->where('created_at', '<', $cutoff);
                if ($keepSecurity) {
                    if ($hasIsSecurityColumn) {
                        $idsQuery->where(function ($q): void {
                            $q->where('is_security', false)
                              ->orWhereNull('is_security');
                        });
                    } else {
                        $idsQuery->where(function ($q): void {
                            $q->where('action', 'not like', 'auth.%')
                              ->where('action', 'not like', 'security.%')
                              ->where('action', 'not like', 'privacy.%')
                              ->where('action', 'not like', 'drm.key%')
                              ->where('action', '!=', 'payment.chargeback');
                        });
                    }
                }

                $ids = $idsQuery
                    ->orderBy('id')
                    ->limit(self::CHUNK_SIZE)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted = DB::transaction(function () use ($ids): int {
                    return AuditLog::whereIn('id', $ids)->delete();
                });

                $totalDeleted += $deleted;
                $bar->advance($deleted);

                // Defensive: in the (impossible) case delete reports 0 we
                // must stop or this loop will spin forever.
                if ($deleted === 0) {
                    break;
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->info(sprintf('Done. deleted=%d', $totalDeleted));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('flik:audit:prune failed', [
                'days'  => $days,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Prune failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
