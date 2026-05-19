<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure the canonical Laravel `jobs` table exists.
 *
 * Laravel publishes this table via `php artisan queue:table` whenever the
 * database queue driver is used. Most fresh installs already have it, but
 * this project historically only ships the `failed_jobs` migration — so
 * the Horizon-lite QueueMonitor dashboard would break against a fresh
 * database. This migration guards with `Schema::hasTable()` so it's a no-op
 * on environments that already published the table from `artisan vendor:publish`
 * or a previous `queue:table` run.
 *
 * Column shape mirrors Laravel 12's stock queue table (no added columns,
 * no dropped columns) so the framework's database queue driver keeps
 * working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        // Intentionally do NOT drop the table on rollback — workers across the
        // cluster might still be reading from it. The administrator should
        // drain queues, then drop the table by hand if they really need to.
    }
};
