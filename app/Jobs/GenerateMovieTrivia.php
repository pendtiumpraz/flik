<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\TriviaGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMovieTrivia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Timeout (seconds). Web search + AI call can be slow.
     */
    public int $timeout = 120;

    /**
     * Backoff between retries (seconds).
     */
    public int $backoff = 30;

    public function __construct(
        public int $movieId,
        public int $count = 8,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(TriviaGenerator $generator): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('GenerateMovieTrivia: movie not found', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        $result = $generator->generate($movie, $this->count);

        Log::info('GenerateMovieTrivia: done', [
            'movie_id'    => $movie->id,
            'title'       => $movie->title,
            'count_saved' => $result->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateMovieTrivia: failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }

    /**
     * Unique identifier for de-duplicating queued runs per movie.
     */
    public function uniqueId(): string
    {
        return 'movie-trivia-' . $this->movieId;
    }
}
