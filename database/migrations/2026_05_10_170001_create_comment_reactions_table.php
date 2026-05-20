<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * comment_reactions — one row per (user, comment) pair.
 *
 * The unique (comment_id, user_id) constraint enforces "one reaction per
 * user per comment" at the database level, so the toggle flow on the
 * Comment model can rely on updateOrCreate / delete semantics without
 * a race window. The (comment_id, reaction) composite index backs the
 * GROUP BY reaction aggregate that powers the reaction-pill counts.
 *
 * Reaction is stored as a fixed-length string column (not Postgres enum)
 * so that adding a new reaction in a future migration is a code change,
 * not a schema migration. Allowed values are enforced application-side
 * in CommentReaction::REACTIONS + the controller validator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')
                ->constrained('comments')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // 'like', 'love', 'laugh', 'wow', 'sad', 'angry' — see
            // \App\Models\CommentReaction::REACTIONS.
            $table->string('reaction', 10);
            $table->timestamps();

            // One reaction per user per comment. Changing reaction is an
            // UPDATE of the existing row, not a new row.
            $table->unique(['comment_id', 'user_id']);

            // Backs the GROUP BY reaction count aggregate used by
            // Comment::reactionsByType() and the observer recompute.
            $table->index(['comment_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reactions');
    }
};
