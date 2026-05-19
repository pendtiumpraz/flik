<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily-watch streak tracker (Gamification — peer GAMI #1).
 *
 * One row per user (unique FK) so `firstOrCreate` + an UPDATE is the hot path.
 * `current_streak` is the live count; `longest_streak` is the historical best
 * (never decremented). `freeze_credits` is the saved "skip-day" pool earned
 * monthly by active subscribers (see flik:streak:grant-freeze-credits) and
 * purchasable with coins via POST /streak/freeze.
 *
 * NOTE: this lives alongside the legacy `user_levels.watch_streak` column —
 * that column is now considered display-only (kept for backwards compat with
 * the existing profile view). All NEW streak logic should read/write here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_streaks', function (Blueprint $table) {
            $table->id();

            // Unique FK — one streak record per user. Cascade so streak data
            // is gone when the user account is deleted (privacy / GDPR).
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // smallint caps at 32767 — a 90-year-old user with a perfect daily
            // streak would land at ~32850; we'll deal with that in 2110.
            $table->unsignedSmallInteger('current_streak')->default(0);
            $table->unsignedSmallInteger('longest_streak')->default(0);

            // Asia/Jakarta-local date — NOT a datetime. The day boundary is
            // computed in PHP (StreakService::today()) to match the user's
            // experience of "today" regardless of server tz.
            $table->date('last_watch_date')->nullable();

            // Skip-day tokens. Earned monthly by active subs (1/month) or
            // purchasable for 50 coins each via POST /streak/freeze.
            $table->unsignedSmallInteger('freeze_credits')->default(0);

            // Only updated_at — we don't care when the row was created; we
            // care about the last activity timestamp. Skip Laravel's
            // standard timestamps() to keep the row tight.
            $table->timestamp('updated_at')->nullable();

            // Leaderboard index — covering query on (current_streak desc).
            // MySQL/MariaDB ignores the direction at the index level but
            // the optimizer still uses the index for ORDER BY.
            $table->index('current_streak', 'watch_streaks_current_streak_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_streaks');
    }
};
