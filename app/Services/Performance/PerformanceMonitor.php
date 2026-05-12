<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Models\AiUsageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * PerformanceMonitor — read-only aggregator for the admin Performance Dashboard.
 *
 * Backs `/admin/performance` (Phase 1). Every method is best-effort and returns
 * a structured array even if the underlying driver/table is missing — callers
 * (the controller + view) should never have to wrap in try/catch.
 *
 * NOTE: All queries are bounded (LIMIT, time-window) so this is safe to call
 * on a hot path; the controller still caches the aggregate for 5 minutes.
 */
class PerformanceMonitor
{
    /**
     * Per-task AI latency + cost + error rate over the given window.
     *
     * Returns:
     *   [
     *     'window_hours' => int,
     *     'since'        => ISO-8601 string,
     *     'totals'       => ['calls' => int, 'errors' => int, 'cost_usd' => float, 'avg_latency_ms' => float|null, 'p95_latency_ms' => int|null, 'error_rate_pct' => float],
     *     'per_task'     => list<array{task_type:string, calls:int, errors:int, error_rate_pct:float, avg_latency_ms:int|null, p95_latency_ms:int|null, cost_usd:float, health:string}>,
     *   ]
     *
     * @return array<string,mixed>
     */
    public function aiServiceStats(int $hours = 24): array
    {
        $since = Carbon::now()->subHours(max(1, $hours));

        $perTaskRows = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->select(
                'task_type',
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as errors'),
                DB::raw('AVG(latency_ms) as avg_latency'),
                DB::raw('SUM(cost_usd) as cost_usd'),
            )
            ->groupBy('task_type')
            ->orderByDesc('calls')
            ->get();

        $perTask = [];
        $totalCalls = 0;
        $totalErrors = 0;
        $totalCost = 0.0;
        $allLatencies = [];

        foreach ($perTaskRows as $row) {
            $calls = (int) $row->calls;
            $errors = (int) $row->errors;
            $errRate = $calls > 0 ? round(($errors / $calls) * 100, 2) : 0.0;

            // p95 latency per task — small windowed query, capped at 5k rows / task.
            $latencies = AiUsageLog::query()
                ->where('created_at', '>=', $since)
                ->where('task_type', $row->task_type)
                ->whereNotNull('latency_ms')
                ->orderByDesc('id')
                ->limit(5000)
                ->pluck('latency_ms')
                ->map(fn ($v) => (int) $v)
                ->all();

            $p95 = $this->percentile($latencies, 95);

            $avgLatency = $row->avg_latency !== null ? (int) round((float) $row->avg_latency) : null;

            $perTask[] = [
                'task_type'        => (string) $row->task_type,
                'calls'            => $calls,
                'errors'           => $errors,
                'error_rate_pct'   => $errRate,
                'avg_latency_ms'   => $avgLatency,
                'p95_latency_ms'   => $p95,
                'cost_usd'         => round((float) $row->cost_usd, 4),
                'health'           => $this->healthFor($errRate, $p95),
            ];

            $totalCalls += $calls;
            $totalErrors += $errors;
            $totalCost += (float) $row->cost_usd;
            foreach ($latencies as $l) {
                $allLatencies[] = $l;
            }
        }

        $overallAvg = $totalCalls > 0 && $allLatencies !== []
            ? round(array_sum($allLatencies) / count($allLatencies), 1)
            : null;
        $overallP95 = $this->percentile($allLatencies, 95);
        $overallErrRate = $totalCalls > 0 ? round(($totalErrors / $totalCalls) * 100, 2) : 0.0;

        return [
            'window_hours' => $hours,
            'since'        => $since->toIso8601String(),
            'totals'       => [
                'calls'            => $totalCalls,
                'errors'           => $totalErrors,
                'cost_usd'         => round($totalCost, 4),
                'avg_latency_ms'   => $overallAvg,
                'p95_latency_ms'   => $overallP95,
                'error_rate_pct'   => $overallErrRate,
                'health'           => $this->healthFor($overallErrRate, $overallP95),
            ],
            'per_task'     => $perTask,
        ];
    }

    /**
     * Failed-job count + pending jobs grouped by queue (database driver only).
     *
     * Returns:
     *   [
     *     'driver'        => string,
     *     'failed_total'  => int,
     *     'failed_24h'    => int,
     *     'per_queue'     => list<array{queue:string, pending:int, health:string}>,
     *     'pending_total' => int,
     *     'note'          => string|null,
     *   ]
     *
     * @return array<string,mixed>
     */
    public function queueLag(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $note = null;
        $failedTotal = 0;
        $failed24h = 0;
        $perQueue = [];
        $pendingTotal = 0;

        // failed_jobs is a standard Laravel table — present regardless of queue driver.
        try {
            if (Schema::hasTable('failed_jobs')) {
                $failedTotal = (int) DB::table('failed_jobs')->count();
                $failed24h = (int) DB::table('failed_jobs')
                    ->where('failed_at', '>=', Carbon::now()->subDay())
                    ->count();
            }
        } catch (Throwable $e) {
            $note = 'failed_jobs lookup failed: ' . $e->getMessage();
        }

        // Pending-by-queue count only makes sense for the `database` driver.
        try {
            if ($driver === 'database' && Schema::hasTable('jobs')) {
                $rows = DB::table('jobs')
                    ->select('queue', DB::raw('COUNT(*) as pending'))
                    ->groupBy('queue')
                    ->orderByDesc('pending')
                    ->get();
                foreach ($rows as $row) {
                    $pending = (int) $row->pending;
                    $pendingTotal += $pending;
                    $perQueue[] = [
                        'queue'   => (string) $row->queue,
                        'pending' => $pending,
                        'health'  => $this->queueHealth($pending),
                    ];
                }
            } elseif ($driver !== 'database') {
                $note = $note ?? "Per-queue pending counts unavailable for driver '{$driver}'. Use Horizon or the queue:monitor command for {$driver}-backed queues.";
            }
        } catch (Throwable $e) {
            $note = ($note ? $note . ' | ' : '') . 'jobs lookup failed: ' . $e->getMessage();
        }

        // Always surface the four logical FLiK queues even when empty so ops can see them.
        $known = ['default', 'transcoding', 'ai-realtime', 'ai-batch'];
        $seen = array_column($perQueue, 'queue');
        foreach ($known as $q) {
            if (!in_array($q, $seen, true)) {
                $perQueue[] = [
                    'queue'   => $q,
                    'pending' => 0,
                    'health'  => 'green',
                ];
            }
        }

        return [
            'driver'        => $driver,
            'failed_total'  => $failedTotal,
            'failed_24h'    => $failed24h,
            'per_queue'     => $perQueue,
            'pending_total' => $pendingTotal,
            'note'          => $note,
        ];
    }

    /**
     * Cache driver + simple set/get roundtrip latency sample (5 iterations).
     *
     * Returns:
     *   [
     *     'driver'         => string,
     *     'samples'        => list<float>,
     *     'avg_ms'         => float|null,
     *     'min_ms'         => float|null,
     *     'max_ms'         => float|null,
     *     'p95_ms'         => float|null,
     *     'healthy'        => bool,
     *     'error'          => string|null,
     *   ]
     *
     * @return array<string,mixed>
     */
    public function cacheStats(): array
    {
        $driver = (string) config('cache.default', 'file');
        $samples = [];
        $healthy = true;
        $error = null;

        for ($i = 0; $i < 5; $i++) {
            $key = '_perf_probe_' . bin2hex(random_bytes(6));
            $payload = ['ts' => microtime(true), 'n' => $i];
            $t0 = hrtime(true);
            try {
                Cache::put($key, $payload, 5);
                $got = Cache::get($key);
                Cache::forget($key);
                $elapsedMs = (hrtime(true) - $t0) / 1_000_000;
                if (!is_array($got) || ($got['n'] ?? null) !== $i) {
                    $healthy = false;
                    $error = $error ?? 'roundtrip returned unexpected value';
                }
                $samples[] = round($elapsedMs, 3);
            } catch (Throwable $e) {
                $healthy = false;
                $error = $e->getMessage();
                break;
            }
        }

        $avg = $samples !== [] ? round(array_sum($samples) / count($samples), 3) : null;
        $min = $samples !== [] ? min($samples) : null;
        $max = $samples !== [] ? max($samples) : null;
        $p95 = $this->percentileFloat($samples, 95);

        return [
            'driver'  => $driver,
            'samples' => $samples,
            'avg_ms'  => $avg,
            'min_ms'  => $min,
            'max_ms'  => $max,
            'p95_ms'  => $p95,
            'healthy' => $healthy,
            'error'   => $error,
        ];
    }

    /**
     * DB connection + version + table count + top-N row counts.
     *
     * Returns:
     *   [
     *     'connection' => string,
     *     'driver'     => string,
     *     'database'   => string|null,
     *     'version'    => string|null,
     *     'table_count'=> int,
     *     'tables'     => list<array{name:string, rows:int}>,
     *     'error'      => string|null,
     *   ]
     *
     * @return array<string,mixed>
     */
    public function dbStats(): array
    {
        $connectionName = (string) config('database.default');
        $config = config("database.connections.{$connectionName}", []);
        $driver = (string) ($config['driver'] ?? 'unknown');
        $database = $config['database'] ?? null;
        $version = null;
        $tableCount = 0;
        $tables = [];
        $error = null;

        try {
            $pdo = DB::connection()->getPdo();
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $e) {
            $error = 'PDO unavailable: ' . $e->getMessage();
        }

        // Sample row counts for a curated list of "hot" tables. Each query is a
        // cheap COUNT(*) — we deliberately avoid full information_schema scans
        // since some shared-hosting MySQL users don't have privileges.
        $candidates = [
            'movies', 'users', 'ratings', 'comments', 'watchlists',
            'watch_histories', 'ai_usage_logs', 'audit_logs',
            'movie_subtitles', 'subscriptions', 'notifications', 'jobs',
        ];

        foreach ($candidates as $tbl) {
            try {
                if (Schema::hasTable($tbl)) {
                    $tableCount++;
                    $tables[] = [
                        'name' => $tbl,
                        'rows' => (int) DB::table($tbl)->count(),
                    ];
                }
            } catch (Throwable) {
                // skip tables we cannot count
            }
        }

        // Sort by row count desc so the largest surface first.
        usort($tables, fn ($a, $b) => $b['rows'] <=> $a['rows']);

        return [
            'connection'  => $connectionName,
            'driver'      => $driver,
            'database'    => is_string($database) ? $database : null,
            'version'     => $version,
            'table_count' => $tableCount,
            'tables'      => $tables,
            'error'       => $error,
        ];
    }

    /**
     * Best-effort slow-query surface. Reads the tail of the laravel log for
     * lines matching common "slow query" markers. If telescope is installed
     * (table `telescope_entries`), prefer that.
     *
     * Returns: list of opaque strings (one per finding) — the view renders them
     * verbatim. Falls back to a single placeholder string when nothing is wired.
     *
     * @return list<string>
     */
    public function slowQueries(int $count = 20): array
    {
        $count = max(1, min(100, $count));

        // 1) Telescope, if installed.
        try {
            if (Schema::hasTable('telescope_entries')) {
                $rows = DB::table('telescope_entries')
                    ->where('type', 'query')
                    ->orderByDesc('created_at')
                    ->limit($count)
                    ->get(['content', 'created_at']);

                $out = [];
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->content, true);
                    $sql = is_array($payload) ? ($payload['sql'] ?? null) : null;
                    $time = is_array($payload) ? ($payload['time'] ?? null) : null;
                    if (is_string($sql)) {
                        $out[] = sprintf(
                            '[%s] %sms — %s',
                            (string) $row->created_at,
                            $time !== null ? (string) $time : '?',
                            $this->truncate($sql, 240),
                        );
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (Throwable) {
            // ignore — fall through to log scan
        }

        // 2) Tail the latest laravel log and grep for slow-query markers.
        $logPath = storage_path('logs/laravel.log');
        try {
            if (File::exists($logPath)) {
                $size = File::size($logPath);
                $readBytes = (int) min($size, 512 * 1024); // last 512 KB
                $fh = fopen($logPath, 'rb');
                if ($fh !== false) {
                    fseek($fh, -$readBytes, SEEK_END);
                    $blob = (string) fread($fh, $readBytes);
                    fclose($fh);
                    $lines = preg_split("/\r?\n/", $blob) ?: [];
                    $hits = [];
                    foreach ($lines as $line) {
                        if ($line === '') {
                            continue;
                        }
                        // common markers Laravel/MySQL produce when query log is on
                        if (stripos($line, 'slow query') !== false
                            || stripos($line, 'slow_query') !== false
                            || preg_match('/\((\d{3,})\s?ms\)/i', $line) === 1
                        ) {
                            $hits[] = $this->truncate($line, 320);
                        }
                    }
                    if ($hits !== []) {
                        return array_slice(array_reverse($hits), 0, $count);
                    }
                }
            }
        } catch (Throwable) {
            // swallow — placeholder below
        }

        return ['Telescope or query log not enabled'];
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /**
     * Integer p-th percentile (1-99). Returns null on empty input.
     *
     * @param  array<int,int> $values
     */
    private function percentile(array $values, int $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        $idx = max(0, min($idx, count($values) - 1));
        return (int) $values[$idx];
    }

    /**
     * Float p-th percentile (1-99). Returns null on empty input.
     *
     * @param  array<int,float> $values
     */
    private function percentileFloat(array $values, int $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        $idx = max(0, min($idx, count($values) - 1));
        return round((float) $values[$idx], 3);
    }

    /**
     * Map an AI task's error rate + p95 latency to a green/yellow/red tag.
     */
    private function healthFor(float $errorRatePct, ?int $p95LatencyMs): string
    {
        if ($errorRatePct >= 5.0 || ($p95LatencyMs !== null && $p95LatencyMs >= 10_000)) {
            return 'red';
        }
        if ($errorRatePct >= 1.0 || ($p95LatencyMs !== null && $p95LatencyMs >= 4_000)) {
            return 'yellow';
        }
        return 'green';
    }

    /**
     * Map a queue depth to green/yellow/red.
     */
    private function queueHealth(int $pending): string
    {
        if ($pending >= 500) {
            return 'red';
        }
        if ($pending >= 100) {
            return 'yellow';
        }
        return 'green';
    }

    private function truncate(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
