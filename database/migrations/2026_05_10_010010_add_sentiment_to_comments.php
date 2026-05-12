<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add sentiment-analysis columns to the `comments` table.
 *
 * Companion to the existing moderation columns (see 2026_05_10_010005_*):
 *   - `moderation_*` answers "is this allowed?" (safe/toxic/spam)
 *   - `sentiment*`   answers "how does the user *feel* about the film?"
 *
 * `sentiment_score` is in [-1.0, 1.0] (positive ↔ negative axis); `decimal(4,3)`
 * keeps three digits of precision while still fitting "-1.000".
 *
 * Composite index (movie_id, sentiment) supports per-movie aggregate queries
 * in the SentimentDashboardController without a full table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->enum('sentiment', ['positive', 'negative', 'neutral', 'mixed'])
                ->nullable()
                ->after('is_visible');
            $table->decimal('sentiment_score', 4, 3)
                ->nullable()
                ->after('sentiment');
            $table->timestamp('sentiment_analyzed_at')
                ->nullable()
                ->after('sentiment_score');

            $table->index(['movie_id', 'sentiment'], 'comments_movie_sentiment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_movie_sentiment_idx');
            $table->dropColumn(['sentiment', 'sentiment_score', 'sentiment_analyzed_at']);
        });
    }
};
