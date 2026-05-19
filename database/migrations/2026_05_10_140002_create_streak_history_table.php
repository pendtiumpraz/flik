<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of streak-day events (Gamification — peer GAMI #1).
 *
 * Every time StreakService::recordWatch advances a user's streak (whether by
 * extending, freezing, or resetting) we insert one row here so the user's
 * streak calendar / "fire days" history can be rendered without recomputing
 * from raw watch_histories. Unique (user_id, date) enforces the "one entry
 * per user per day" invariant the recorder relies on for idempotency.
 *
 * The reward_* columns capture what was granted that day so the rewards
 * page can show "Day 7 milestone: +100 XP, +20 coins" without re-resolving
 * the milestone table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streak_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('streak_after_day');
            $table->unsignedInteger('reward_xp')->default(0);
            $table->unsignedInteger('reward_coins')->default(0);

            // Milestone slug snapshot (e.g. 'week_warrior'). Stored as a
            // free-form string rather than FK to keep the history readable
            // even if the achievement is later renamed/removed.
            $table->string('milestone')->nullable();

            $table->timestamps();

            // Hot path for the streak calendar — fetch the last N days of
            // history for one user, newest first.
            $table->index(['user_id', 'date'], 'streak_history_user_date_idx');

            // Idempotency guard — StreakService::recordWatch checks current
            // state before inserting, but the unique constraint is the
            // last-line defence against double-counting if two concurrent
            // requests slip past the check.
            $table->unique(['user_id', 'date'], 'streak_history_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streak_history');
    }
};
