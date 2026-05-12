<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\BehindScenesGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job: generate the 6-section "Behind the Scenes" article
 * for a single Movie. Runs on the `ai-batch` queue alongside other
 * heavy AI tasks (trivia, year-in-review, director analyses).
 */
class GenerateBehindScenes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Timeout (seconds). Web search + AI call can be slow.
     */
    public int $timeout = 180;

    /**
     * Backoff between retries (seconds).
     */
    public int $backoff = 30;

    public function __construct(
        public int $movieId,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(BehindScenesGenerator $generator): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('GenerateBehindScenes: movie not found', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        $result = $generator->generate($movie);

        Log::info('GenerateBehindScenes: done', [
            'movie_id'    => $movie->id,
            'title'       => $movie->title,
            'count_saved' => $result->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateBehindScenes: failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }

    /**
     * Unique identifier for de-duplicating queued runs per movie.
     */
    public function uniqueId(): string
    {
        return 'movie-bts-' . $this->movieId;
    }
}
