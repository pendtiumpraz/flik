<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_list_items
 * --------------------------------------------------------------------------
 * Pivot rows linking a {@see user_lists} entry to a {@see movies} entry, with
 * extra metadata (manual `position` ordering + optional `note` from the
 * list owner). UNIQUE (user_list_id, movie_id) so the same film can't appear
 * twice in the same list — the controller's `addMovie` would otherwise need
 * to do its own dedupe at write time.
 *
 * `position` is a smallint deliberately — 32k items per list is well past
 * the point where the UI breaks anyway, and we save bytes per row vs `int`.
 *
 * `added_at` is a separate timestamp (vs created_at) so a future
 * "imported from another list" feature can backdate items while still
 * showing accurate "added X minutes ago" timestamps. We keep created_at /
 * updated_at off by setting timestamps() only conditionally — but to stay
 * compatible with Eloquent's default expectations we DO emit timestamps()
 * here and treat `added_at` as the primary display field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_list_items', function (Blueprint $table) {
            $table->id();

            // Parent list. Cascade so deleting a list wipes its items in
            // one statement (no orphans, no manual cleanup in the model).
            $table->foreignId('user_list_id')
                ->constrained('user_lists')
                ->cascadeOnDelete();

            // Movie reference. Cascade on movie delete: a deleted movie
            // is removed from every list that contained it (the alternative
            // — keeping dangling rows — would render broken cards).
            $table->foreignId('movie_id')
                ->constrained('movies')
                ->cascadeOnDelete();

            // Manual ordering. Default 0 lets a quick "addMovie at end"
            // skip a SELECT MAX(position) when the list is empty.
            $table->smallInteger('position')->default(0);

            // Owner's free-text note ("watch this if you liked X"). Optional;
            // most items will be note-less.
            $table->text('note')->nullable();

            // Distinct from created_at — see class docblock.
            $table->timestamp('added_at')->useCurrent();

            $table->timestamps();

            // The list cannot contain the same film twice. Enforced at DB
            // level so a race between two concurrent POSTs cannot bypass it.
            $table->unique(['user_list_id', 'movie_id'], 'user_list_items_list_movie_unique');

            // Drives "ORDER BY position" reads on the list-show page. The
            // leading user_list_id matches every query (we never read items
            // across lists in a single query).
            $table->index(['user_list_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_list_items');
    }
};
