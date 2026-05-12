<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concurrent-stream enforcement.
 *
 * One row per active playback. Player heartbeats every N seconds
 * (`heartbeat_at` bumped). When `now() > expires_at` the row is
 * considered stale and may be garbage-collected. Counting non-expired
 * rows for a user gives current concurrent stream count, enforced
 * against the user's plan limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('playback_concurrent_locks')) {
            return;
        }

        Schema::create('playback_concurrent_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_token', 128);
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->timestamp('heartbeat_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_concurrent_locks');
    }
};
