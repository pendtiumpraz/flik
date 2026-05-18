<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Events\Created;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NewCommentListener
 * --------------------------------------------------------------------------
 * Fires an admin realtime notification (via AdminNotifier) whenever a NEW
 * top-level comment is created. Replies (parent_id !== null) are skipped
 * because they would be too noisy for the moderator bell — moderators can
 * still see them through the normal moderation queue.
 *
 * Severity escalates to "warning" + a spoiler-tagged title when the
 * downstream AI flags the comment as a potential spoiler (is_spoiler=true).
 *
 * Queued on the `default` queue with $tries=2 so a flaky notification
 * service can't impact the originating comment POST request.
 */
class NewCommentListener implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Queue tries before the job is marked failed. Two attempts is enough
     * to absorb a transient cache / DB blip without retrying forever.
     */
    public int $tries = 2;

    /**
     * Logical queue. Per CLAUDE.md `default` is the bucket for misc /
     * low-priority jobs — admin bell notifications fit that profile.
     */
    public string $queue = 'default';

    /**
     * Accepts either the raw Comment model (when invoked as a model-event
     * subscriber via Comment::created(...)) OR a wrapping Eloquent Created
     * event — mirroring the pattern in ModerateNewComment so this listener
     * can be registered either way.
     */
    public function handle(Created|Comment $event): void
    {
        try {
            $comment = $event instanceof Comment ? $event : $event->model;

            if (!$comment instanceof Comment) {
                return;
            }

            // Skip replies — only top-level comments hit the bell.
            if ($comment->parent_id !== null) {
                return;
            }

            $comment->loadMissing(['user', 'movie']);

            $userName = $comment->user?->name ?? 'Anonymous';
            $movieTitle = $comment->movie?->title ?? 'unknown title';
            $isSpoiler = (bool) ($comment->is_spoiler ?? false);

            $title = $isSpoiler
                ? "Possible spoiler from {$userName} on {$movieTitle}"
                : "New comment from {$userName} on {$movieTitle}";

            $body = (string) ($comment->body ?? '');
            // Strip whatever HTML the sanitizer kept (admins want the gist
            // in the bell, not formatted markup).
            $message = mb_substr(trim(strip_tags($body)), 0, 120);
            if ($message === '') {
                $message = '(empty body)';
            }

            $this->notify(
                category: 'comment.new',
                title: $title,
                message: $message,
                meta: [
                    'comment_id' => $comment->id,
                    'movie_id' => $comment->movie_id,
                    'user_id' => $comment->user_id,
                    'is_spoiler' => $isSpoiler,
                ],
                severity: $isSpoiler ? 'warning' : 'info',
                audience: 'moderator',
                actionUrl: $this->safeRoute('admin.comments.queue'),
            );
        } catch (Throwable $e) {
            $this->swallow($e, ['comment_id' => $event->id ?? null]);
        }
    }

    /**
     * Resolve the AdminNotifier and dispatch — no-op when peer NOTIF #1
     * has not yet shipped (binding absent). This guarantees this listener
     * is safe to deploy ahead of (or alongside) the AdminNotifier service.
     */
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

    /** Safely resolve a route name → URL; returns null when the route is missing. */
    private function safeRoute(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Listener exceptions must NEVER bubble up to break the originating
     * request that produced the source event. Log + swallow.
     */
    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('NewCommentListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
