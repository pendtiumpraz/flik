<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\CommentReaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Comment.reactions_count + Comment.top_reaction in sync with
 * comment_reactions row writes, and busts the per-comment cache used
 * by Comment::reactionsByType().
 *
 * Counter recompute is a single subquery UPDATE (no N+1):
 *
 *   UPDATE comments
 *      SET reactions_count = (SELECT COUNT(*) FROM comment_reactions WHERE comment_id = comments.id),
 *          top_reaction    = (... grouped winner subquery ...)
 *    WHERE id = ?
 *
 * Cheaper than incrementing manually because we sidestep the "what was
 * the old value? did the user swap reaction?" branching logic. The
 * comment_reactions(comment_id, reaction) index makes both subqueries
 * sargable.
 *
 * Additionally fires the optional CommentReactionUpdated event so the
 * realtime frontend can update other connected viewers' pill counts
 * without polling. The event no-ops gracefully if BROADCAST_DRIVER is
 * `null` (default) — see the event class for the dispatch guard.
 */
class CommentReactionObserver
{
    public function created(CommentReaction $reaction): void
    {
        $this->resync($reaction);
    }

    public function updated(CommentReaction $reaction): void
    {
        $this->resync($reaction);
    }

    public function deleted(CommentReaction $reaction): void
    {
        $this->resync($reaction);
    }

    /**
     * Recompute denormalised counters + bust cache + (optionally) broadcast.
     */
    private function resync(CommentReaction $reaction): void
    {
        $commentId = (int) $reaction->comment_id;
        if ($commentId === 0) {
            return;
        }

        // ── Single-statement recompute ───────────────────────────────
        // Driver-specific top-reaction subquery: MySQL/PostgreSQL/SQLite
        // all support an ORDER BY ... LIMIT 1 subselect.
        DB::table('comments')
            ->where('id', $commentId)
            ->update([
                'reactions_count' => DB::raw(
                    '(SELECT COUNT(*) FROM comment_reactions WHERE comment_id = ' . $commentId . ')'
                ),
                'top_reaction' => DB::raw(
                    '(SELECT reaction FROM comment_reactions '
                    . 'WHERE comment_id = ' . $commentId . ' '
                    . 'GROUP BY reaction '
                    . 'ORDER BY COUNT(*) DESC, MIN(id) ASC '
                    . 'LIMIT 1)'
                ),
            ]);

        // ── Bust the 5-min reactionsByType cache ─────────────────────
        $comment = Comment::find($commentId);
        if ($comment === null) {
            return;
        }
        Cache::forget($comment->reactionsCacheKey());

        // ── Achievement: 10+ reactions on a single comment ───────────
        // Best-effort: skip on any error so a missing achievement row
        // or pivot table can never break the reaction toggle flow.
        try {
            $this->maybeAwardCommunityFavorite($comment);
        } catch (\Throwable $e) {
            Log::warning('community_favorite achievement check failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
        }

        // ── Optional realtime broadcast ──────────────────────────────
        // The event itself short-circuits when BROADCAST_DRIVER is unset
        // (`null` driver), so we can dispatch unconditionally.
        try {
            \App\Events\CommentReactionUpdated::dispatchIfBroadcastable(
                $comment,
                $reaction
            );
        } catch (\Throwable $e) {
            Log::warning('CommentReactionUpdated broadcast failed', [
                'comment_id' => $commentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unlock the `community_favorite` achievement for the comment author
     * once their comment crosses 10 reactions. Idempotent via the
     * user_achievements unique (user_id, achievement_id) pivot.
     */
    private function maybeAwardCommunityFavorite(Comment $comment): void
    {
        $freshCount = (int) $comment->reactions()->count();
        if ($freshCount < 10) {
            return;
        }

        $achievement = \App\Models\Achievement::query()
            ->where('slug', 'community_favorite')
            ->first();
        if ($achievement === null) {
            // Seeder hasn't been run on this install yet. Silently skip
            // — adding the row later will pick up future reactions.
            return;
        }

        $author = $comment->user;
        if ($author === null) {
            return;
        }

        // syncWithoutDetaching is idempotent (no duplicate pivot rows).
        $author->achievements()->syncWithoutDetaching([
            $achievement->id => ['unlocked_at' => now()],
        ]);
    }
}
