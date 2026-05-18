<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FailedJobListener
 * --------------------------------------------------------------------------
 * Subscribes to Illuminate\Queue\Events\JobFailed. Emits an admin
 * notification on every distinct failure but escalates severity to
 * 'critical' when the same job class fails ≥3 times in an hour (i.e.,
 * a real outage, not a one-off blip).
 *
 * Self-protection: this listener itself is queued (ShouldQueue) — if the
 * NOTIFICATION job fails, we don't want THIS listener firing recursively
 * forever. We detect that case via the job class name and bail.
 */
class FailedJobListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 2;

    public string $queue = 'default';

    /** Failure-rate threshold above which severity escalates to critical. */
    private const CRITICAL_FAILURE_THRESHOLD = 3;

    /** Window for the failure-rate counter (1 hour). */
    private const FAILURE_WINDOW_SECONDS = 3600;

    public function handle(JobFailed $event): void
    {
        try {
            $jobName = $this->resolveJobName($event);
            $exception = $event->exception;

            // Recursion guard: if a notification-related job itself failed,
            // bail. Otherwise we'd queue another notif, which fails, which
            // queues another notif… infinite loop.
            if ($this->isSelfReferential($jobName)) {
                Log::warning('FailedJobListener: skipping self-referential failure', [
                    'job' => $jobName,
                    'error' => $exception?->getMessage(),
                ]);
                return;
            }

            $count = $this->incrementFailureCounter($jobName);
            $severity = $count >= self::CRITICAL_FAILURE_THRESHOLD ? 'critical' : 'warning';

            $message = $exception
                ? mb_substr((string) $exception->getMessage(), 0, 200)
                : '(no exception message)';

            $title = $severity === 'critical'
                ? "Job FAILING repeatedly: {$jobName} ({$count}x)"
                : "Job failed: {$jobName}";

            $this->notify(
                category: 'system.job_failed',
                title: $title,
                message: $message,
                meta: [
                    'job' => $jobName,
                    'connection' => $event->connectionName,
                    'queue' => $this->resolveQueue($event),
                    'attempts' => method_exists($event->job, 'attempts')
                        ? $event->job->attempts()
                        : null,
                    'failure_count_1h' => $count,
                ],
                severity: $severity,
                audience: ['admin', 'super_admin'],
                actionUrl: null,
            );
        } catch (Throwable $e) {
            $this->swallow($e, ['job' => $event->job ? $this->resolveJobName($event) : null]);
        }
    }

    /**
     * Resolve a stable job class name from the failed event. Different
     * queue drivers / job types expose this differently — we try the
     * common methods then fall back to the connection name.
     */
    private function resolveJobName(JobFailed $event): string
    {
        try {
            if (method_exists($event->job, 'resolveName')) {
                return (string) $event->job->resolveName();
            }
            if (method_exists($event->job, 'getName')) {
                return (string) $event->job->getName();
            }
        } catch (Throwable) {
            // fall through
        }

        return $event->connectionName ?? 'unknown_job';
    }

    private function resolveQueue(JobFailed $event): ?string
    {
        try {
            if (method_exists($event->job, 'getQueue')) {
                return (string) $event->job->getQueue();
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** Increment per-job hourly failure counter, returns the new count. */
    private function incrementFailureCounter(string $jobName): int
    {
        $key = 'admin_notif:job_fail:' . md5($jobName);

        try {
            $count = Cache::increment($key);
            if ($count === false || $count === 1) {
                Cache::put($key, 1, self::FAILURE_WINDOW_SECONDS);
                return 1;
            }
            return (int) $count;
        } catch (Throwable) {
            // Cache trouble — assume single failure so the notif still goes out.
            return 1;
        }
    }

    /**
     * Skip notif when the failed job is itself a notification listener
     * (e.g. AdminNotifier broadcast worker). Crude name-substring check
     * is good enough — we just need to break the cycle.
     */
    private function isSelfReferential(string $jobName): bool
    {
        $needles = [
            'App\\Listeners\\Admin\\FailedJobListener',
            'AdminNotifier',
            'App\\Listeners\\Admin\\',
        ];

        foreach ($needles as $needle) {
            if (str_contains($jobName, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function notify(
        string $category,
        string $title,
        string $message,
        array $meta,
        string $severity,
        string|array $audience,
        ?string $actionUrl,
    ): void {
        $class = 'App\\Services\\Notifications\\AdminNotifier';

        if (!app()->bound($class) && !class_exists($class)) {
            Log::warning('AdminNotifier binding missing — admin notif dropped', [
                'category' => $category,
                'title' => $title,
            ]);
            return;
        }

        app($class)->notify(
            category: $category,
            title: $title,
            message: $message,
            meta: $meta,
            severity: $severity,
            audience: $audience,
            actionUrl: $actionUrl,
        );
    }

    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('FailedJobListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
