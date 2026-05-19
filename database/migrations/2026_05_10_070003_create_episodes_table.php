<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `episodes` — leaves of the series tree.
 *
 * Both `season_id` AND `movie_id` are stored. The denormalised movie_id
 * lets us write efficient queries like "all episodes of this series, in
 * release order" without joining through seasons every time (used by the
 * front-end episode picker + the watch_histories index below).
 *
 * `generated_summary` lives here (not on a separate AI table) because
 * it is a per-episode field with a 1:1 relation to episodes.id, and the
 * EpisodeSummarizer service already writes via a single UPDATE. Keep
 * it nullable so episode rows without AI coverage stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('episodes')) {
            return;
        }

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('seasons')
                ->cascadeOnDelete();
            // Denormalised: lets us index/filter by movie without a join.
            $table->foreignId('movie_id')
                ->constrained('movies')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('episode_number');
            $table->string('title');
            $table->text('overview')->nullable();
            // AI-generated 50-word blurb (EpisodeSummarizer). Nullable so
            // an episode with no AI coverage is still a valid row.
            $table->text('generated_summary')->nullable();
            $table->timestamp('generated_summary_at')->nullable();
            $table->string('still_path')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->date('air_date')->nullable();

            // Playback assets — mirrored from the movies table layout so a
            // future episode-level DRM/HLS pipeline can land without
            // schema churn.
            $table->string('video_path')->nullable();
            $table->string('video_disk')->nullable();
            $table->string('hls_manifest_path')->nullable();

            // Per-episode auto-skip markers (intro/outro). Reuse the same
            // pattern movies use so the front-end auto-skip overlay
            // (initAutoSkip) keeps working without branching by content_type.
            $table->unsignedInteger('intro_start_seconds')->nullable();
            $table->unsignedInteger('intro_end_seconds')->nullable();
            $table->unsignedInteger('outro_start_seconds')->nullable();

            $table->timestamps();

            // Unique within a season — episode numbers don't repeat.
            $table->unique(['season_id', 'episode_number']);
            // Series-wide "release order" listing index.
            $table->index(['movie_id', 'air_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
