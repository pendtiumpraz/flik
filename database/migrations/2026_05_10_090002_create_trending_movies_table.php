<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trending_movies — pre-aggregated trending cache.
 *
 * Written by App\Services\Trending\TrendingAggregator (invoked by
 * flik:trending:recompute on the scheduler — see App\Console\Kernel).
 * One row per (movie_id, window). Top 100 movies kept per window.
 *
 * Score formula (per window):
 *   score = view_count
 *         + unique_viewer_count * 2
 *         + recency_decay (newer half-life bonus)
 *         * featured_penalty (0.5x for is_popular flag — see aggregator)
 *
 * `rank` is the 1-based ordinal AFTER scoring (1 = hottest). Stored so
 * the UI doesn't have to ORDER BY score on every render — it just
 * ORDERs BY rank, which has a covering index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trending_movies')) {
            return;
        }

        Schema::create('trending_movies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->enum('window', ['1h', '24h', '7d', '30d']);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('unique_viewer_count')->default(0);
            $table->double('score')->default(0);
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['movie_id', 'window']);
            $table->index(['window', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trending_movies');
    }
};
