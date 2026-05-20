<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_state — single-row singleton table that drives the custom
 * App-level maintenance switch (separate from Laravel's native
 * `php artisan down` file marker).
 *
 * Why a table instead of a JSON flag file?
 *   - We want the admin UI to flip it without shelling out.
 *   - Multi-server installs need a shared source of truth.
 *   - We want history: enabled_by_user_id + enabled_at give us "who hit
 *     the kill switch and when" for the audit-log dashboard.
 *
 * The CheckCustomMaintenance middleware reads this row on every request,
 * so the singleton pattern (id == 1) is enforced both at the application
 * layer (MaintenanceState::current) and DB layer (single primary-key row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_state', function (Blueprint $table) {
            $table->id();

            // The single global on/off bit. When false everything else is
            // metadata: the message + allow lists are still editable so an
            // admin can pre-stage a scheduled outage.
            $table->boolean('is_enabled')->default(false);

            // Free-form message shown on the 503 page. Markdown is NOT
            // rendered — we treat this as plaintext (escaped in Blade) to
            // avoid giving an attacker who somehow flipped this a stored
            // XSS vector on every request.
            $table->text('message')->nullable();

            // Bypass lists — anyone matching either list reaches the app
            // even when is_enabled is true. JSON arrays of strings.
            //   allow_ips:   raw IPv4/IPv6 strings, no CIDR (yet).
            //   allow_roles: role names from the RolePermissionSeeder
            //                taxonomy. Defaults to ['super_admin'] so the
            //                person who flipped the switch can always get
            //                back in to flip it off.
            $table->json('allow_ips')->nullable();
            $table->json('allow_roles')->nullable();

            // When set, the 503 page shows a live countdown and (optional
            // future enhancement) the middleware could auto-disable itself
            // past this point.
            $table->timestamp('scheduled_until')->nullable();

            // Audit trail — who flipped it on, when. Kept nullable so a
            // system actor (cron, signal handler) can still drive it.
            $table->foreignId('enabled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();

            $table->timestamp('updated_at')->nullable();
        });

        // Seed the singleton row so MaintenanceState::current() never has
        // to deal with an empty table on the very first request. id is
        // explicitly 1 so every code path can rely on that constant.
        DB::table('maintenance_state')->insert([
            'id'         => 1,
            'is_enabled' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_state');
    }
};
