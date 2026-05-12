<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * movie_schedules
 * --------------------------------------------------------------------------
 * "Save for Friday Night" — per-user calendar entries scheduling when they
 * intend to watch a film. Powers the user-facing schedule manager + the
 * 1-hour-out reminder job (flik:schedule:remind).
 *
 * Lookups are heavily by (user_id, scheduled_for) so we add a composite
 * index there. We DO NOT add a unique constraint on (user_id, movie_id,
 * scheduled_for) — a user may legitimately schedule the same film at the
 * same minute on retry, and we don't want a duplicate save to throw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('movie_id')
                ->constrained('movies')
                ->cascadeOnDelete();

            // When the user plans to watch.
            $table->timestamp('scheduled_for');

            // Optional free-form note ("Date night w/ Sarah", "Watch with popcorn").
            $table->text('notes')->nullable();

            // Stamped by flik:schedule:remind once the 1-hour-out reminder fires.
            $table->timestamp('reminder_sent_at')->nullable();

            // Stamped when the user marks the scheduled session as watched.
            $table->timestamp('watched_at')->nullable();

            $table->timestamps();

            // Primary access pattern: "give me USER X's schedules sorted by
            // scheduled_for" + reminder cron's "scheduled_for between now and
            // now+1h" sweep.
            $table->index(['user_id', 'scheduled_for'], 'movie_schedules_user_when_idx');

            // Reminder cron sweep is global ("any user whose schedule fires
            // soon and hasn't been reminded yet"). Helps the WHERE filter.
            $table->index(['scheduled_for', 'reminder_sent_at'], 'movie_schedules_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_schedules');
    }
};
