<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * X-Ray (O14) — Movie scene actor presence map.
 *
 * One row = "this cast member is on screen between start_seconds and end_seconds,
 * roughly at coordinate (screen_x%, screen_y%)". Powers the X-Ray overlay's
 * clickable hotspots that surface actor bios while viewers watch.
 *
 * NOTE: Actual face recognition is NOT implemented. This table is the data
 * structure for either manual annotation (admin tool) or future ML-based
 * population (e.g. TMDB scene credits + face detection pipeline).
 *
 * Idempotent: safe to re-run if the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('movie_scene_actors')) {
            return;
        }

        Schema::create('movie_scene_actors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('movie_id')
                ->constrained('movies')
                ->cascadeOnDelete();

            $table->foreignId('cast_id')
                ->constrained('casts')
                ->cascadeOnDelete();

            // Time window where the actor is visible (3-decimal precision aligns with
            // HLS segment boundaries — same convention as intro_start_seconds etc.)
            $table->decimal('start_seconds', 8, 3);
            $table->decimal('end_seconds', 8, 3);

            // On-screen position as percentages (0–100). Nullable when unknown
            // (e.g. populated only with time-range, no spatial data yet).
            $table->decimal('screen_x', 5, 2)->nullable();
            $table->decimal('screen_y', 5, 2)->nullable();

            // Detection confidence (0.00–1.00). 1.00 = manual annotation.
            $table->decimal('confidence', 4, 2)->default(1.00);

            $table->timestamps();

            $table->index('movie_id');
            $table->index('cast_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_scene_actors');
    }
};
