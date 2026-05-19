<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure the canonical Laravel `failed_jobs` table exists.
 *
 * The original 2019 migration creates this table, but if a fresh install
 * is missing it (or running against a database where the older migration
 * never ran) the QueueMonitor dashboard would 500 on the first query.
 * This guard adds the table only when missing, so re-running migrations
 * is safe and idempotent regardless of starting state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Same rationale as the jobs-table guard — never drop on rollback.
    }
};
