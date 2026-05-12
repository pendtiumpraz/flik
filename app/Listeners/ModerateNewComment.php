<?php

namespace App\Listeners;

use App\Jobs\ModerateComment;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Events\Created;

/**
 * Listener for Comment::created (Eloquent model event).
 *
 * Dispatches the ModerateComment job onto the ai-realtime queue so
 * the HTTP response thread is not blocked by an LLM call.
 *
 * Registered in app/Providers/EventServiceProvider.php via:
 *   Comment::created(fn ($comment) => app(ModerateNewComment::class)->handle(...))
 * or via the standard $listen array keyed by Eloquent's Created event.
 */
class ModerateNewComment
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

        if (!$comment instanceof Comment) {
            return;
        }

        // Skip if already moderated (e.g. admin-seeded data).
        if ($comment->moderated_at !== null) {
            return;
        }

        ModerateComment::dispatch($comment->id);
    }
}
