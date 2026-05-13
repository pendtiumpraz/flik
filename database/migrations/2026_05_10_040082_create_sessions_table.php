<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions table — Laravel default schema.
 *
 * Required when SESSION_DRIVER=database (driver switch happens in
 * .env / config/session.php). Backs the per-user "active sessions"
 * UI under /profile/sessions: each row maps a session cookie to the
 * authenticated user_id with last_activity, ip, and user_agent so we
 * can list / revoke individual devices.
 *
 * Idempotent: only creates the table if it doesn't already exist
 * (some environments may have run `php artisan session:table` already).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
