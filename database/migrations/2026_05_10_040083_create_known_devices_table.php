<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * known_devices — per-user device fingerprint registry.
 *
 * Backs the "new device login" alert flow:
 *   1. On every successful login we compute a stable fingerprint
 *      (sha256(ip + ua + accept-language)) and check whether the
 *      (user_id, fingerprint) tuple already exists.
 *   2. New row → email + in-app notification ("we noticed a sign-in
 *      from a new device"). Existing row → bump last_seen_at.
 *   3. Users can mark devices as `trusted` from /profile/sessions to
 *      suppress future alerts (alerts only fire for untrusted rows).
 *
 * The unique index on (user_id, device_fingerprint) is the canonical
 * identity of a device — both columns are part of every lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // sha256 hex digest — always 64 chars
            $table->string('device_fingerprint', 64);

            // IPv6 max length is 45 chars
            $table->string('ip', 45);

            // ISO 3166-1 alpha-2; nullable for private/loopback IPs
            $table->string('country', 2)->nullable();

            // UA strings can be long; use TEXT to be safe
            $table->text('user_agent')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // User-controlled flag; once true no future alerts fire for
            // this fingerprint.
            $table->boolean('trusted')->default(false);

            $table->timestamps();

            // Canonical identity — also fast-paths the per-login lookup.
            $table->unique(['user_id', 'device_fingerprint'], 'known_devices_user_fp_unique');

            // Recent-activity sort + dashboard listing.
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
    }
};
