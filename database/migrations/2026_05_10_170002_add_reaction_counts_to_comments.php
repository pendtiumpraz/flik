<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised reaction counters on the comments table.
 *
 *   reactions_count — total number of reactions across all types. Used
 *     by the "sort by Top Reactions" list view and the achievement
 *     trigger (community_favorite at >= 10).
 *
 *   top_reaction    — name of the most-used reaction on this comment
 *     ('love'|'laugh'|...). Lets the list view show a single mini-pill
 *     summary (e.g. "12 love") without a JOIN.
 *
 * Both fields are kept in sync by CommentReactionObserver. Bulk
 * recomputes use a single subquery UPDATE, never a per-row loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->unsignedInteger('reactions_count')->default(0)->after('likes_count');
            $table->string('top_reaction', 10)->nullable()->after('reactions_count');

            // Backs ?sort=top_reactions list ordering. Composite with
            // movie_id so the index is selective for the per-movie
            // comment list query that already filters by movie_id.
            $table->index(['movie_id', 'reactions_count']);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['movie_id', 'reactions_count']);
            $table->dropColumn(['reactions_count', 'top_reaction']);
        });
    }
};
