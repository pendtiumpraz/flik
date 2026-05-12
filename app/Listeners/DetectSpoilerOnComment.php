<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\DetectSpoilerInComment;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Events\Created;

/**
 * Listener for Comment::created (Eloquent model event).
 *
 * Dispatches the DetectSpoilerInComment job onto the ai-realtime queue so
 * the HTTP response thread is not blocked by an LLM call.
 *
 * Registered alongside ModerateNewComment in EventServiceProvider::boot().
 */
class DetectSpoilerOnComment
{
    /**
     * Handle the event.
     *
     * Accepts either the raw Comment model (when invoked as a model-event
     * subscriber) or a Created event wrapping it.
     */
    public function handle(Created|Comment $event): void
    {
        $comment = $event instanceof Comment ? $event : $event->model;

        if (! $comment instanceof Comment) {
            return;
        }

        // Skip if already checked (e.g. admin-seeded data or re-saved row).
        if ($comment->spoiler_checked_at !== null) {
            return;
        }

        DetectSpoilerInComment::dispatch($comment->id);
    }
}
