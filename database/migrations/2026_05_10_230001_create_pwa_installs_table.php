<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pwa_installs — append-only ledger of PWA install events fired by the
 * client-side `appinstalled` listener (resources/js/pwa-install.js) and
 * the user-driven prompt outcome.
 *
 * One row per event, never updated. Reads:
 *   - admin dashboard "installs over time" (group by date)
 *   - rough device-mix breakdown via the UA + device columns.
 *
 * user_id is nullable because the install endpoint accepts unauthenticated
 * hits (guests can install the PWA too — they just won't be in our user
 * table yet when they do it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pwa_installs', function (Blueprint $table) {
            $table->id();

            // Optional auth — the install banner shows for guests too. ON
            // DELETE SET NULL keeps the history row alive after a user
            // deletion (anonymised install count is still useful).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Short device descriptor from navigator.platform — kept short
            // because the same info is also in `ua`. Indexed for quick
            // group-by-device aggregates.
            $table->string('device', 64)->nullable()->index();

            // Full UA — kept for forensic detail (browser version,
            // device model on Android). 1024 covers even verbose UA strings.
            $table->string('ua', 1024)->nullable();

            // Outcome — 'installed' (appinstalled fired), 'accepted' /
            // 'dismissed' (from userChoice on Chromium). Nullable in case
            // the JS path doesn't have the value.
            $table->string('outcome', 32)->nullable();

            // Hashed/truncated IP for ops triage without storing PII.
            $table->string('ip_hash', 64)->nullable();

            $table->timestamp('installed_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pwa_installs');
    }
};
