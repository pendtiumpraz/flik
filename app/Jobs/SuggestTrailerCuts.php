<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\TrailerSuggester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate trailer-cut suggestions for a single movie.
 *
 * Queue: ai-batch (slow, AI-heavy work — keep off the default queue).
 * Tries: 1 (FFmpeg/AI is expensive; retries should be a manual decision).
 */
class SuggestTrailerCuts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public $tries = 1;

    /** Stop the job at 30 minutes — FFmpeg loudnorm sweep can be slow. */
    public int $timeout = 1800;

    public function __construct(
        public int $movieId,
        public int $count = 3,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(TrailerSuggester $suggester): void
    {
        $movie = Movie::find($this->movieId);
        if (!$movie) {
            Log::warning('SuggestTrailerCuts: movie not found', ['movie_id' => $this->movieId]);
            return;
        }

        $suggestions = $suggester->suggest($movie, $this->count);

        Log::info('SuggestTrailerCuts: done', [
            'movie_id'    => $movie->id,
            'suggestions' => $suggestions->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SuggestTrailerCuts: failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }
}
