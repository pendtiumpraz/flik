lanjutkan
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cinematography / colour analysis per movie.
 *
 * One row per Movie (movie_id unique). Populated by
 * App\Services\Ai\Tasks\CinematographyAnalyzer which extracts keyframes via
 * FFmpeg and uses a Gemini vision pass to describe the film's visual style.
 *
 * Idempotent: regenerated via updateOrCreate(movie_id => ...).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('movie_cinematography')) {
            return;
        }

        Schema::create('movie_cinematography', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Array of {hex: "#aabbcc", weight: 0.0-1.0}
            $table->json('color_palette')->nullable();

            // e.g. "high-key", "low-key", "natural", "chiaroscuro"
            $table->string('lighting_style')->nullable();

            // e.g. "rule-of-thirds", "symmetric", "centered", "Dutch angle"
            $table->string('composition_style')->nullable();

            // Array of short descriptor strings, e.g. ["melancholic", "warm", "claustrophobic"]
            $table->json('mood_descriptors')->nullable();

            // 150-word Indonesian narrative paragraph synthesising the analysis
            $table->text('narrative_summary')->nullable();

            // Array of storage-relative paths to the sampled keyframes
            $table->json('sample_keyframes_paths')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_cinematography');
    }
};
