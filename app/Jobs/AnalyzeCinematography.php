<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\CinematographyAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job: run the cinematography / colour analyser over a single Movie.
 *
 * Queue:   `ai-batch` — pairs FFmpeg keyframe extraction with a vision-AI pass.
 * Tries:   1 — re-runs reread the source video and re-spend tokens.
 * Timeout: 900s (15 min) — generous to cover slow remote-disk downloads.
 */
class AnalyzeCinematography implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Maximum attempts. */
    public int $tries = 1;

    /** @var int Seconds before the worker kills the job. */
    public int $timeout = 900;

    public function __construct(
        public int $movieId,
        public int $sampleCount = 6,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(CinematographyAnalyzer $analyzer): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('AnalyzeCinematography: movie not found', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        $record = $analyzer->analyze($movie, $this->sampleCount);

        Log::info('AnalyzeCinematography: done', [
            'movie_id'        => $movie->id,
            'title'           => $movie->title,
            'has_analysis'    => $record->hasAnalysis(),
            'palette_entries' => is_array($record->color_palette) ? count($record->color_palette) : 0,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('AnalyzeCinematography: failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }

    /**
     * De-duplicate queued runs per movie.
     */
    public function uniqueId(): string
    {
        return 'movie-cinematography-' . $this->movieId;
    }
}
