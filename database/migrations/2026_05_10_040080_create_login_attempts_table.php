<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login attempt audit table — backs the brute-force protection layer
 * (App\Services\Security\LoginThrottle).
 *
 * Every POST /login records one row regardless of outcome so we can:
 *   - count recent failures per email   (account lockout)
 *   - count recent failures per IP      (IP lockout — slows credential stuffing)
 *   - apply progressive delay before validation
 *   - surface a "this account is locked, contact support" message
 *
 * Successful attempts are recorded too so the admin "unlock" view can show
 * a complete history. The `unlock` operation deletes only failed rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');                       // lowercased for indexed lookup
            $table->string('ip', 45);                      // IPv6 max length
            $table->string('user_agent')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('attempted_at')->useCurrent();

            // Query shapes we care about — both are time-windowed counts.
            $table->index(['email', 'attempted_at']);
            $table->index(['ip', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
