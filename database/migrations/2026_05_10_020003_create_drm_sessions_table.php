<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-movie DRM playback session.
 *
 * `session_token` is handed to the player and presented on every key
 * request. `content_key` is the AES-128 content key, stored encrypted
 * via Laravel's Crypt facade (hence BLOB to hold ciphertext).
 *
 * `last_key_request_at` + `key_request_count` enable rate-limiting
 * and replay-attack detection at the key delivery endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drm_sessions')) {
            return;
        }

        Schema::create('drm_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();
            $table->string('session_token', 128)->unique();
            $table->string('device_fingerprint', 128)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->binary('content_key')->nullable()
                ->comment('AES-128 content key encrypted via Crypt::encrypt()');
            $table->timestamp('last_key_request_at')->nullable();
            $table->unsignedInteger('key_request_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'movie_id']);
            $table->index('expires_at');
            // session_token already has unique index from ->unique() above.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drm_sessions');
    }
};
