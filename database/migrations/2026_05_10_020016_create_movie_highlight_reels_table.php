<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the auto-generated 3-minute highlight reel for each movie.
 *
 * One row per movie (the latest re-generation overwrites the same row).
 * `scenes_json` keeps the per-scene breakdown (start/end/score/reason)
 * so the UI can show why each clip was picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_highlight_reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->cascadeOnDelete();

            // Storage location of the rendered .mp4
            $table->string('reel_path');
            $table->string('reel_disk')->default('public');

            // Generation parameters & breakdown
            $table->unsignedInteger('target_duration_seconds');
            $table->unsignedInteger('scene_count');
            $table->json('scenes_json')->comment('Array of {start, end, score, reason} clips stitched into the reel');

            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // Lifecycle
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_highlight_reels');
    }
};
