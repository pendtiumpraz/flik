<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * movie_views — raw per-view event log.
 *
 * One row per "watch happened" signal. Powers the trending engine
 * (see App\Services\Trending\*). Multiple sources record here:
 *   - WatchHistoryController::updateProgress (first progress write per session
 *     — the canonical "they actually pressed play" signal).
 *   - VelflixController::show (lower-weight "page view" signal — they at
 *     least clicked through to the detail page).
 *
 * ─── PRIVACY ─────────────────────────────────────────────────────────
 * `ip_hash` is sha256(ip + APP_KEY) — peppered so it cannot be reversed
 * back to the originating IP even if the table is leaked. It exists ONLY
 * to deduplicate / count unique anonymous viewers; it is never exposed
 * to admin views and is never decoded back to a raw IP.
 *
 * `user_id` is nullable on purpose — we want trending to work for
 * pre-login browsing too (anonymous discovery feeds back into ranking
 * via session_id + ip_hash uniqueness).
 *
 * ─── INDEXES ─────────────────────────────────────────────────────────
 * (movie_id, viewed_at) — TrendingAggregator scans per-movie windows.
 * (viewed_at)           — global "what's hot right now" sweeps.
 * (user_id, viewed_at)  — future "your viewing history" reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movie_views')) {
            return;
        }

        Schema::create('movie_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 40)->nullable();
            $table->char('ip_hash', 64)->nullable()
                ->comment('sha256(ip + APP_KEY pepper) — anonymous unique counter, NOT reversible to IP');
            $table->timestamp('viewed_at')->index();
            $table->unsignedInteger('duration_seconds')->nullable()
                ->comment('Seconds actually watched (from WatchHistory) — null for page-view-only events');
            $table->string('device', 20)->nullable();

            $table->index(['movie_id', 'viewed_at']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_views');
    }
};
