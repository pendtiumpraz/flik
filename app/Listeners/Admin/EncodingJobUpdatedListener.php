<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Models\EncodingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EncodingJobUpdatedListener
 * --------------------------------------------------------------------------
 * Observer hook for EncodingJob::updated. Emits an admin bell notification
 * ONLY when the job transitions into the 'completed' or 'failed' terminal
 * states — intermediate status flips (queued → transcoding → encrypting →
 * uploading) are ignored because the encoding dashboard polls them at a
 * higher resolution than a bell can absorb.
 *
 * Transition detection uses Eloquent's getOriginal('status') to make sure
 * a no-op save while already-completed doesn't double-emit.
 */
class EncodingJobUpdatedListener implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;

    public string $queue = 'default';

    public function handle(EncodingJob $job): void
    {
        try {
            $job->loadMissing('movie');

            $movieTitle = $job->movie?->title ?? "movie #{$job->movie_id}";
            $movieId = $job->movie_id;
            $actionUrl = $movieId
                ? $this->safeRoute('admin.movies.encoding-status', ['movie' => $movieId])
                : null;

            if ($job->status === EncodingJob::STATUS_COMPLETED) {
                $this->notify(
                    category: 'encoding.done',
                    title: "Encoding done: {$movieTitle}",
                    message: "All renditions ready for {$movieTitle}.",
                    meta: [
                        'encoding_job_id' => $job->id,
                        'movie_id' => $movieId,
                        'renditions' => $job->output_paths,
                    ],
                    severity: 'info',
                    audience: 'content_editor',
                    actionUrl: $actionUrl,
                );
                return;
            }

            if ($job->status === EncodingJob::STATUS_FAILED) {
                $reason = $job->error_message
                    ? mb_substr((string) $job->error_message, 0, 200)
                    : 'unknown';

                $this->notify(
                    category: 'encoding.failed',
                    title: "Encoding FAILED: {$movieTitle}",
                    message: "Encoding for {$movieTitle} failed: {$reason}",
                    meta: [
                        'encoding_job_id' => $job->id,
                        'movie_id' => $movieId,
                        'failure_reason' => $reason,
                    ],
                    severity: 'warning',
                    audience: ['content_editor', 'admin'],
                    actionUrl: $actionUrl,
                );
            }
        } catch (Throwable $e) {
            $this->swallow($e, ['encoding_job_id' => $job->id ?? null]);
        }
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

    private function safeRoute(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            return null;
        }
    }

    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('EncodingJobUpdatedListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
