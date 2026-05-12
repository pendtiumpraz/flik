<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * quiz_attempts
 * --------------------------------------------------------------------------
 * One row per finished quiz attempt. Users can replay a movie's quiz any
 * number of times — leaderboard queries the best score per user.
 *
 * `score` is a normalized 0–100 number (correct_count / total_questions * 100)
 * so leaderboards across movies with different question counts stay
 * comparable. `time_seconds` is the wall-clock time the user took from
 * start to submit; future tiebreaker for the leaderboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_attempts')) {
            return;
        }

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('score');
            $table->unsignedInteger('total_questions');
            $table->unsignedInteger('correct_count');
            $table->unsignedInteger('time_seconds')->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'movie_id']);
            $table->index(['movie_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
