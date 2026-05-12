<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watch Party room — synchronized playback session shared by a host
 * and up to N members. The host owns playback state; members follow
 * via broadcasted `WatchPartySync` events.
 *
 * Lifecycle:
 *  - row created on POST /watch-party (host = creator, room_code random 8-char)
 *  - is_playing + current_position_seconds reflect the host's player state;
 *    every play/pause/seek updates them so latecomers can catch up at join.
 *  - ended_at non-null = room closed (queryable via scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('watch_parties')) {
            return;
        }

        Schema::create('watch_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('room_code', 8)->unique();
            $table->decimal('current_position_seconds', 10, 3)->default(0);
            $table->boolean('is_playing')->default(false);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_updated_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('max_members')->default(8);
            $table->timestamps();

            $table->index('room_code');
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_parties');
    }
};
