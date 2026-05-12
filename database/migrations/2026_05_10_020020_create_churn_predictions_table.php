<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * churn_predictions
 * --------------------------------------------------------------------------
 * One row per user that the ChurnPredictor has scored. Latest snapshot only —
 * the predictor uses updateOrCreate(user_id) so we keep a single, fresh
 * estimate per user rather than a time-series. (If we ever want history,
 * snapshot this table into a `churn_prediction_history` table from a daily
 * cron — leave that out for now.)
 *
 * `risk_score` is a calibrated 0.000–1.000 float, where higher = more likely
 * to churn. `risk_level` is a human-friendly bucket derived from the score
 * at write time (low/medium/high/critical) so the dashboard can filter
 * without re-evaluating thresholds.
 *
 * `signals` is a JSON blob of the raw inputs the predictor considered
 * (days_since_watch, declining_engagement, etc.) — useful for explaining
 * the score in the admin UI without recomputing it.
 *
 * `suggested_action` is an AI-generated single sentence (Indonesian) for
 * high/critical risk users only — for low/medium it stays NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('churn_predictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // 0.000 — 1.000.  decimal(4,3) covers the full range.
            $table->decimal('risk_score', 4, 3)->default(0);

            // Bucketed risk band — sortable + filterable in the admin UI.
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])
                ->default('low');

            // Raw signal payload (heuristic inputs + computed sub-scores).
            $table->json('signals')->nullable();

            // AI-suggested win-back action (only populated for high/critical).
            $table->string('suggested_action')->nullable();

            // When the predictor last ran for this user.
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            // One snapshot per user (predictor uses updateOrCreate).
            $table->unique('user_id');

            // Dashboard filter pattern: "show me critical users from today".
            $table->index(['risk_level', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('churn_predictions');
    }
};
