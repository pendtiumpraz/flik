<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ab_experiments
 * --------------------------------------------------------------------------
 * Lightweight A/B testing framework (D6).
 *
 * One row per experiment. Variants are a flat JSON array of variant keys
 * (e.g. `["control","variant_a","variant_b"]`) paired with a parallel
 * `traffic_split` JSON array of weights that sum to 1.0 (e.g.
 * `[0.5,0.25,0.25]`). Sticky per-user / per-session assignment lives in
 * `ab_assignments` so a user keeps the same bucket across requests.
 *
 * Lifecycle:
 *   draft     → created, no traffic exposed yet
 *   active    → assigner mints + returns variants, tracker records conversions
 *   paused    → assigner is frozen (existing assignments still readable)
 *   completed → ended; ended_at + winner can be set
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ab_experiments', function (Blueprint $table) {
            $table->id();

            // Stable identifier referenced by application code:
            //   $ab->assign('home-hero-copy-v2', $user)
            $table->string('name')->unique();

            $table->text('description')->nullable();

            // Parallel arrays — variants[i] gets traffic_split[i] share.
            $table->json('variants');
            $table->json('traffic_split');

            $table->enum('status', ['draft', 'active', 'paused', 'completed'])
                ->default('draft');

            // Free-form metric label so the dashboard can show "we are
            // measuring X" without coupling to a registry of metrics.
            //   e.g. "subscription_conversion", "ctr_home_hero", "watch_completion"
            $table->string('primary_metric');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // Dashboard pattern: "show me everything currently active".
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_experiments');
    }
};
