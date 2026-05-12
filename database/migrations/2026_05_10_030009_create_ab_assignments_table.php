<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ab_assignments
 * --------------------------------------------------------------------------
 * Sticky variant assignment + conversion record.
 *
 * Either `user_id` or `session_id` is set (logged-in users get sticky
 * assignment by user id; anonymous visitors get it by session id). Two
 * partial-uniqueness rules are enforced via composite indexes so we never
 * mint two variants for the same identity.
 *
 * `converted_at` is null until `AbService::track()` is called. Conversion
 * is intentionally a single binary event per assignment — for funnels with
 * multiple gates, run multiple experiments.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ab_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ab_experiment_id')
                ->constrained('ab_experiments')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            // Anonymous-visitor identifier (Laravel session id, cookie hash, etc.).
            $table->string('session_id')->nullable();

            $table->string('variant');

            $table->boolean('converted')->default(false);
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            // One variant per (experiment, user) and one per (experiment, session).
            // We enforce sticky-bucket via two composite UNIQUE indexes; either
            // user_id or session_id is non-null per row, so each row violates
            // at most one of them on collision.
            $table->unique(['ab_experiment_id', 'user_id'], 'ab_assignments_exp_user_unique');
            $table->unique(['ab_experiment_id', 'session_id'], 'ab_assignments_exp_session_unique');

            // Report query pattern: "for this experiment, group by variant".
            $table->index(['ab_experiment_id', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_assignments');
    }
};
