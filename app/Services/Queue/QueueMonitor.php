<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * QueueMonitor — Horizon-lite queue introspection.
 *
 * Read-only metrics on top of the canonical `jobs` and `failed_jobs` tables.
 * Designed to work against the `database` queue driver out of the box;
 * Redis-backed queues will report empty depths from the DB tables (the
 * Redis driver doesn't write to `jobs`), which the dashboard view
 * documents in-page so operators aren't confused.
 *
 * Every query is wrapped in graceful fallback so a missing table or
 * driver-not-supported scenario surfaces an empty result instead of
 * blowing up the entire admin panel. Workers are NEVER read or mutated
 * by this class — only failed-job retry/forget actions performed via
 * Artisan touch the queue state, and those happen in the controller.
 */
final class QueueMonitor
{
    /**
     * Per-queue depth snapshot.
     *
     * Reads the canonical Laravel queue convention:
     *   - pending  → row exists, reserved_at IS NULL
     *   - reserved → row exists, reserved_at IS NOT NULL (in-flight)
     *   - total    → pending + reserved
     *
     * @return list<array{queue:string,pending:int,reserved:int,total:int}>
     */
    public function queueDepths(): array
    {
        $queues = $this->knownQueues();

        if (! $this->tableExists('jobs')) {
            return array_map(fn (string $q) => [
                'queue' => $q,
                'pending' => 0,
                'reserved' => 0,
                'total' => 0,
            ], $queues);
        }

        // Single aggregate query — for every known queue, compute pending +
        // reserved counts at once. Cheaper than N queries when the queue
        // list grows.
        try {
            $rows = DB::table('jobs')
                ->whereIn('queue', $queues)
                ->selectRaw('queue, '
                    .'SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS pending, '
                    .'SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');
        } catch (Throwable $e) {
            Log::warning('QueueMonitor::queueDepths query failed', ['error' => $e->getMessage()]);
            $rows = collect();
        }

        $out = [];
        foreach ($queues as $q) {
            $row = $rows->get($q);
            $pending = $row ? (int) $row->pending : 0;
            $reserved = $row ? (int) $row->reserved : 0;

            $out[] = [
                'queue' => $q,
                'pending' => $pending,
                'reserved' => $reserved,
                'total' => $pending + $reserved,
            ];
        }

        return $out;
    }

    /**
     * Paginated failed-jobs list with optional queue + text-search filters.
     *
     * Search matches anywhere inside `payload` or `exception` — heavy on
     * very large failed_jobs tables, but acceptable since failed jobs are
     * intentionally trimmed by the operator and never grow unbounded.
     *
     * @return LengthAwarePaginator<int,object>
     */
    public function failedJobs(int $limit = 100, ?string $queue = null, ?string $search = null): LengthAwarePaginator
    {
        if (! $this->tableExists('failed_jobs')) {
            // Empty paginator with the same shape the view expects.
            return new LengthAwarePaginator([], 0, max(1, $limit));
        }

        $query = DB::table('failed_jobs')->orderByDesc('failed_at');

        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        if ($search !== null && $search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('payload', 'like', $needle)
                    ->orWhere('exception', 'like', $needle);
            });
        }

        return $query->paginate(max(1, $limit));
    }

    /**
     * Failures per hour over the past 24h.
     *
     * Returned series is always length 24 (one bucket per hour, oldest first)
     * so the Chart.js consumer doesn't need to fill gaps. Hours with no
     * failures get a zero count, not a missing entry.
     *
     * Notes on completed throughput:
     *   - Without a Horizon-style ledger we can't reconstruct "jobs that
     *     completed successfully", so this method intentionally only
     *     exposes the failure series. Successful throughput would need
     *     an additional event listener writing to a `processed_jobs`
     *     log table — out of scope for this dashboard.
     *
     * @return list<array{hour:string,failed_count:int}>
     */
    public function throughputLast24h(): array
    {
        $now = now()->startOfHour();
        $start = $now->copy()->subHours(23);

        // Pre-seed every hourly bucket with 0 so the response is dense.
        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $hour = $start->copy()->addHours($i);
            $buckets[$hour->format('Y-m-d H:00')] = 0;
        }

        if (! $this->tableExists('failed_jobs')) {
            return $this->bucketsToList($buckets);
        }

        try {
            $rows = DB::table('failed_jobs')
                ->where('failed_at', '>=', $start)
                ->get(['failed_at']);
        } catch (Throwable $e) {
            Log::warning('QueueMonitor::throughputLast24h query failed', ['error' => $e->getMessage()]);

            return $this->bucketsToList($buckets);
        }

        foreach ($rows as $row) {
            try {
                $bucketKey = Carbon::parse($row->failed_at)->startOfHour()->format('Y-m-d H:00');
            } catch (Throwable) {
                continue;
            }
            if (isset($buckets[$bucketKey])) {
                $buckets[$bucketKey]++;
            }
        }

        return $this->bucketsToList($buckets);
    }

    /**
     * Oldest pending job for a queue — used by the dashboard to surface
     * starvation alerts when a worker has been down. `available_at` is the
     * canonical "ready to run" timestamp in Laravel's database driver, so
     * MIN(available_at) is the earliest moment something has been waiting.
     *
     * Returns null when the queue has no pending rows or the table is
     * missing entirely.
     */
    public function oldestPending(string $queue): ?Carbon
    {
        if (! $this->tableExists('jobs')) {
            return null;
        }

        try {
            $ts = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->min('available_at');
        } catch (Throwable $e) {
            Log::warning('QueueMonitor::oldestPending query failed', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($ts === null) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $ts);
    }

    /**
     * Best-effort worker discovery.
     *
     * This dashboard intentionally does NOT shell out to the OS process
     * list (would require shell access, fragile across platforms, and
     * leaks shell privileges through the web UI). If Horizon is ever
     * installed, its snapshot files live under storage/framework/horizon
     * and could be parsed; we look for that path and report what we find,
     * otherwise return an explicit empty result with a note.
     *
     * @return array{detected:list<array<string,mixed>>,note:?string}
     */
    public function queueWorkers(): array
    {
        $horizonDir = storage_path('framework/horizon');

        if (! is_dir($horizonDir)) {
            return [
                'detected' => [],
                'note' => 'Workers detected via system process list not implemented here. '
                    .'Install Laravel Horizon (composer require laravel/horizon) for full worker introspection, '
                    .'or run `supervisorctl status` on the host.',
            ];
        }

        $files = [];
        try {
            foreach (File::files($horizonDir) as $file) {
                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified_at' => Carbon::createFromTimestamp($file->getMTime())->toIso8601String(),
                ];
            }
        } catch (Throwable $e) {
            Log::warning('QueueMonitor::queueWorkers Horizon scan failed', ['error' => $e->getMessage()]);
        }

        return [
            'detected' => $files,
            'note' => $files === []
                ? 'Horizon directory present but empty — no worker snapshots written yet.'
                : null,
        ];
    }

    /**
     * Canonical list of queues this dashboard cares about. Driven by
     * config('queue.flik_queues') so adding a new logical queue in
     * config/queue.php automatically surfaces it here. 'default' is
     * always present even if the operator drops it from the config.
     *
     * @return list<string>
     */
    public function knownQueues(): array
    {
        /** @var array<string,string> $configured */
        $configured = (array) config('queue.flik_queues', []);
        $queues = array_values($configured);
        if (! in_array('default', $queues, true)) {
            $queues[] = 'default';
        }

        return array_values(array_unique($queues));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $e) {
            Log::warning('QueueMonitor::tableExists check failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string,int>  $buckets
     * @return list<array{hour:string,failed_count:int}>
     */
    private function bucketsToList(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $hour => $count) {
            $out[] = ['hour' => $hour, 'failed_count' => $count];
        }

        return $out;
    }
}
