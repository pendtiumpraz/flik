<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

/**
 * Horizon-lite admin dashboard for the database-backed queue.
 *
 * Operations that mutate the queue go through `Artisan::call` against
 * the framework's first-party commands (`queue:retry`, `queue:forget`,
 * `queue:flush`) — never shell out, never write SQL directly. This keeps
 * the dashboard compatible with whichever queue driver the operator has
 * configured, and avoids reinventing Laravel's job-replay semantics
 * (which know how to rebuild the payload + dispatch back onto the
 * original queue).
 *
 * Every destructive call writes an audit row via {@see AuditLogger}. The
 * flush endpoint additionally re-prompts for the admin password since
 * it can wipe thousands of debugging-grade rows in a single click.
 */
final class QueueDashboardController extends Controller
{
    public function __construct(
        private readonly QueueMonitor $monitor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /admin/queues
     */
    public function index(): View
    {
        $depths = $this->monitor->queueDepths();

        // Decorate each row with its oldest-pending age (Carbon|null) so the
        // view doesn't have to make extra service calls per queue.
        $depths = array_map(function (array $row) {
            $row['oldest_pending'] = $this->monitor->oldestPending($row['queue']);

            return $row;
        }, $depths);

        $totalPending = array_sum(array_column($depths, 'pending'));
        $totalReserved = array_sum(array_column($depths, 'reserved'));

        $failedRecent = $this->monitor->failedJobs(20);
        $throughput = $this->monitor->throughputLast24h();

        // Last-hour failure count, surfaced as a KPI card.
        $failuresLastHour = ! empty($throughput)
            ? (int) end($throughput)['failed_count']
            : 0;

        // Oldest pending across ALL queues (the worst case).
        $oldestPendingAll = collect($depths)
            ->pluck('oldest_pending')
            ->filter()
            ->min();

        // Total failed-jobs count (uses the paginator total since the
        // query already runs without filters here).
        $failedTotal = $this->monitor->failedJobs(1)->total();

        $workers = $this->monitor->queueWorkers();

        return view('admin.queues.dashboard', [
            'depths' => $depths,
            'totalPending' => $totalPending,
            'totalReserved' => $totalReserved,
            'failedTotal' => $failedTotal,
            'failedRecent' => $failedRecent,
            'throughput' => $throughput,
            'failuresLastHour' => $failuresLastHour,
            'oldestPendingAll' => $oldestPendingAll,
            'workers' => $workers,
        ]);
    }

    /**
     * GET /admin/queues/failed
     *
     * Returns JSON when the client requests it (Accept: application/json or
     * ?format=json), otherwise renders the full failed-jobs page.
     */
    public function failed(Request $request): View|JsonResponse
    {
        $queue = $request->filled('queue') ? (string) $request->input('queue') : null;
        $search = $request->filled('search') ? (string) $request->input('search') : null;
        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(10, min(200, $perPage));

        $paginator = $this->monitor->failedJobs($perPage, $queue, $search);

        if ($request->wantsJson() || $request->input('format') === 'json') {
            return response()->json([
                'data' => collect($paginator->items())->map(fn ($row) => $this->presentFailedRow($row)),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        return view('admin.queues.failed', [
            'failed' => $paginator,
            'queue' => $queue,
            'search' => $search,
            'queueOptions' => $this->monitor->knownQueues(),
        ]);
    }

    /**
     * POST /admin/queues/retry/{id}
     *
     * `id` is the failed_jobs.uuid string (Laravel's queue:retry expects
     * the UUID, not the numeric PK, when failed jobs use the
     * `database-uuids` driver — which is the default in this project).
     */
    public function retry(Request $request, string $id): RedirectResponse
    {
        try {
            // `queue:retry` accepts an array of IDs even for a single retry.
            Artisan::call('queue:retry', ['id' => [$id]]);
            $output = trim((string) Artisan::output());
        } catch (Throwable $e) {
            return back()->with('error', 'Retry failed: '.$e->getMessage());
        }

        $this->safeAudit('queue.failed.retry', [
            'failed_job_id' => $id,
            'artisan_output' => $output,
        ]);

        return back()->with('success', "Job {$id} requeued.");
    }

    /**
     * POST /admin/queues/retry-all
     *
     * Optional `queue` filter — when present, only failed jobs from that
     * queue are requeued. Without it, every failed job is retried.
     *
     * Internally we resolve the UUIDs first when a queue is supplied
     * (`queue:retry all` doesn't take a queue filter), then hand them to
     * the framework command in a single call.
     */
    public function retryAll(Request $request): RedirectResponse
    {
        $queue = $request->filled('queue') ? (string) $request->input('queue') : null;

        try {
            if ($queue !== null) {
                $ids = \Illuminate\Support\Facades\DB::table('failed_jobs')
                    ->where('queue', $queue)
                    ->pluck('uuid')
                    ->all();

                if ($ids === []) {
                    return back()->with('success', "No failed jobs to retry for queue '{$queue}'.");
                }

                Artisan::call('queue:retry', ['id' => $ids]);
            } else {
                Artisan::call('queue:retry', ['id' => ['all']]);
            }
            $output = trim((string) Artisan::output());
        } catch (Throwable $e) {
            return back()->with('error', 'Bulk retry failed: '.$e->getMessage());
        }

        $this->safeAudit('queue.failed.retry_all', [
            'queue' => $queue,
            'artisan_output' => $output,
        ]);

        return back()->with('success', $queue !== null
            ? "Retried all failed jobs on queue '{$queue}'."
            : 'Retried all failed jobs.');
    }

    /**
     * DELETE /admin/queues/forget/{id}
     */
    public function forget(string $id): RedirectResponse
    {
        try {
            Artisan::call('queue:forget', ['id' => $id]);
            $output = trim((string) Artisan::output());
        } catch (Throwable $e) {
            return back()->with('error', 'Delete failed: '.$e->getMessage());
        }

        $this->safeAudit('queue.failed.forget', [
            'failed_job_id' => $id,
            'artisan_output' => $output,
        ]);

        return back()->with('success', "Failed job {$id} deleted.");
    }

    /**
     * POST /admin/queues/flush
     *
     * Wipes EVERY row from failed_jobs. Requires the admin to re-enter
     * their current password (form field name: `confirm_password`) so a
     * stray click or CSRF-replay can't nuke days of debugging context.
     */
    public function flushFailed(Request $request): RedirectResponse
    {
        $request->validate([
            'confirm_password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if ($user === null || ! Hash::check((string) $request->input('confirm_password'), (string) $user->password)) {
            // Audit failed confirmation attempts — useful trail when someone
            // is fishing around the destructive endpoint.
            $this->safeAudit('queue.failed.flush_denied', [
                'reason' => 'bad_password',
            ], security: true);

            return back()->with('error', 'Password confirmation failed. Flush aborted.');
        }

        // Capture row count BEFORE flushing so the audit entry records the
        // blast radius even after the rows are gone.
        $countBefore = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

        try {
            Artisan::call('queue:flush');
            $output = trim((string) Artisan::output());
        } catch (Throwable $e) {
            return back()->with('error', 'Flush failed: '.$e->getMessage());
        }

        $this->safeAudit('queue.failed.flush', [
            'count_flushed' => $countBefore,
            'artisan_output' => $output,
        ], security: true);

        return back()->with('success', "Flushed {$countBefore} failed jobs.");
    }

    /**
     * GET /admin/queues/live
     *
     * Lightweight JSON endpoint polled by the dashboard. Returns only the
     * counter data needed to update the KPI cards + per-queue table —
     * no Carbon objects, no relations, no view rendering.
     */
    public function liveCounts(): JsonResponse
    {
        $depths = $this->monitor->queueDepths();
        $depthsWithAge = array_map(function (array $row) {
            $oldest = $this->monitor->oldestPending($row['queue']);
            $row['oldest_pending_iso'] = $oldest?->toIso8601String();
            $row['oldest_pending_seconds'] = $oldest ? now()->diffInSeconds($oldest, true) : null;

            return $row;
        }, $depths);

        $failedTotal = $this->monitor->failedJobs(1)->total();
        $throughput = $this->monitor->throughputLast24h();
        $failuresLastHour = ! empty($throughput) ? (int) end($throughput)['failed_count'] : 0;

        return response()->json([
            'depths' => $depthsWithAge,
            'total_pending' => array_sum(array_column($depths, 'pending')),
            'total_reserved' => array_sum(array_column($depths, 'reserved')),
            'failed_total' => $failedTotal,
            'failures_last_hour' => $failuresLastHour,
            'throughput_24h' => $throughput,
            'as_of' => now()->toIso8601String(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Decode a failed_jobs row into a flat array suitable for the JSON API
     * and the Blade view. Extracts the queued job class name from the
     * payload so the operator sees "App\Jobs\SomeJob" rather than the
     * 2KB serialized blob.
     *
     * @param  object  $row  DB row from failed_jobs
     * @return array<string,mixed>
     */
    private function presentFailedRow(object $row): array
    {
        $payload = [];
        try {
            $decoded = json_decode((string) $row->payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (Throwable) {
            // Leave payload empty on decode failure.
        }

        $jobClass = $payload['displayName']
            ?? ($payload['data']['commandName'] ?? null)
            ?? 'unknown';

        $exception = (string) $row->exception;
        $exceptionFirstLine = strtok($exception, "\n") ?: '';

        return [
            'id' => $row->id,
            'uuid' => $row->uuid,
            'connection' => $row->connection,
            'queue' => $row->queue,
            'job_class' => $jobClass,
            'failed_at' => $row->failed_at,
            'exception_first_line' => mb_strimwidth($exceptionFirstLine, 0, 240, '…'),
        ];
    }

    /**
     * Audit-log helper that never breaks the response on failure. Routes
     * to either {@see AuditLogger::log()} or {@see AuditLogger::security()}
     * depending on severity.
     *
     * @param  array<string,mixed>  $meta
     */
    private function safeAudit(string $action, array $meta, bool $security = false): void
    {
        try {
            if ($security) {
                $this->audit->security($action, null, $meta);
            } else {
                $this->audit->log($action, null, $meta);
            }
        } catch (Throwable) {
            // Audit failures must never surface as a user-visible error.
        }
    }
}
