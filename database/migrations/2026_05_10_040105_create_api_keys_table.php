<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_keys — service-to-service auth tokens.
 *
 * Plaintext keys are NEVER stored. We keep:
 *   - key_hash   : sha256(plaintext) — looked up on every request, hence the
 *                  unique index. SHA-256 is fine here (input has 256+ bits of
 *                  entropy from random_bytes(32) — bcrypt would slow lookups
 *                  to a crawl with no practical security gain).
 *   - key_prefix : first 8 chars of the plaintext (e.g. "flk_a1b2") for
 *                  display in the admin UI without exposing the full token.
 *   - abilities  : JSON list of permission scopes ("*" for full access).
 *                  Future middleware can gate routes on specific abilities.
 *   - last_used_at / last_used_ip : updated on every successful verify().
 *                  Lets admins identify stale keys + spot anomalous origins.
 *   - expires_at / revoked_at : either set → key fails verify(). Soft-revoke
 *                  is preferred over delete so audit_logs entries that
 *                  reference the row remain joinable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 8)->index();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // Composite for the hot path: "is this hash currently usable?"
            $table->index(['revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
