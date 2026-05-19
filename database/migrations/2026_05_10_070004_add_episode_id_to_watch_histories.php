<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend `watch_histories` so it can track per-episode progress.
 *
 * For a standalone movie: episode_id stays NULL (unchanged behaviour).
 * For a series episode: episode_id is set + movie_id continues to point
 * at the parent series row so existing aggregations (top-N by series,
 * "Continue Watching" cards) keep working without joins through
 * episodes → seasons.
 *
 * Set-null on episode delete (not cascade) so wiping an episode does
 * NOT silently nuke a user's history entry — the row remains pointing
 * at the parent movie row for audit / aggregate accuracy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watch_histories')) {
            return;
        }

        Schema::table('watch_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('watch_histories', 'episode_id')) {
                $table->foreignId('episode_id')
                    ->nullable()
                    ->after('movie_id')
                    ->constrained('episodes')
                    ->nullOnDelete();

                $table->index(['user_id', 'episode_id']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('watch_histories')) {
            return;
        }

        Schema::table('watch_histories', function (Blueprint $table) {
            if (Schema::hasColumn('watch_histories', 'episode_id')) {
                // dropForeign expects the auto-generated key name.
                $table->dropForeign(['episode_id']);
                $table->dropIndex(['user_id', 'episode_id']);
                $table->dropColumn('episode_id');
            }
        });
    }
};
