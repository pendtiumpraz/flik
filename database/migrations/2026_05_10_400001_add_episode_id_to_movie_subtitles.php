<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let movie_subtitles also belong to an episode.
 *
 * `episode_id` is nullable: NULL = a movie-level subtitle (existing rows),
 * set = an episode-level subtitle. movie_id stays NOT NULL (we store the
 * parent series movie id alongside episode_id), so existing relations and
 * the cascade-on-movie-delete still hold.
 *
 * The unique key is widened to include episode_id so an episode can carry
 * the same language as its parent movie without colliding. MySQL treats
 * NULLs as distinct in a unique index, so the existing movie-level rows
 * (episode_id = NULL) remain valid under the new key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_subtitles', function (Blueprint $table) {
            if (! Schema::hasColumn('movie_subtitles', 'episode_id')) {
                $table->foreignId('episode_id')->nullable()->after('movie_id')
                    ->constrained()->cascadeOnDelete();
            }
        });

        // Drop the old (movie_id, language_code, variant) unique if present.
        try {
            Schema::table('movie_subtitles', function (Blueprint $table) {
                $table->dropUnique('movie_lang_variant_unique');
            });
        } catch (\Throwable $e) {
            // already dropped / different name — ignore
        }

        // Add the episode-aware unique key.
        try {
            Schema::table('movie_subtitles', function (Blueprint $table) {
                $table->unique(
                    ['movie_id', 'episode_id', 'language_code', 'variant'],
                    'movie_ep_lang_variant_unique'
                );
            });
        } catch (\Throwable $e) {
            // already exists — ignore
        }
    }

    public function down(): void
    {
        try {
            Schema::table('movie_subtitles', function (Blueprint $table) {
                $table->dropUnique('movie_ep_lang_variant_unique');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('movie_subtitles', function (Blueprint $table) {
            if (Schema::hasColumn('movie_subtitles', 'episode_id')) {
                $table->dropConstrainedForeignId('episode_id');
            }
        });

        try {
            Schema::table('movie_subtitles', function (Blueprint $table) {
                $table->unique(['movie_id', 'language_code', 'variant'], 'movie_lang_variant_unique');
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
