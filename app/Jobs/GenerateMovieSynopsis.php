<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\SynopsisGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job: generate the AI synopsis for a single Movie.
 *
 * Runs on the `ai-realtime` queue so admin-triggered single-movie generations
 * don't block on the heavier `ai-batch` queue (subtitles, translations, etc.).
 */
class GenerateMovieSynopsis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted before being marked as failed.
     */
    public int $tries = 3;

    /**
     * Number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Backoff in seconds between retries (exponential-ish).
     *
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function __construct(
        public int $movieId,
        public int $maxWords = 150,
        public bool $force = false
    ) {
        $this->onQueue('ai-realtime');
    }

    /**
     * Execute the job.
     */
    public function handle(SynopsisGenerator $generator): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('GenerateMovieSynopsis: movie not found, skipping', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        // Idempotency: skip if already generated unless caller forced it.
        if (!$this->force && !empty($movie->ai_synopsis) && !empty($movie->ai_synopsis_generated_at)) {
            Log::info('GenerateMovieSynopsis: synopsis already exists, skipping', [
                'movie_id' => $movie->id,
                'generated_at' => (string) $movie->ai_synopsis_generated_at,
            ]);
            return;
        }

        try {
            $movie->loadMissing('genres');
            $generator->generate($movie, $this->maxWords);

            Log::info('GenerateMovieSynopsis: success', [
                'movie_id' => $movie->id,
                'title' => $movie->title,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateMovieSynopsis: failed', [
                'movie_id' => $movie->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry per $tries / backoff()
        }
    }

    /**
     * Called when all retries have been exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateMovieSynopsis: permanently failed', [
            'movie_id' => $this->movieId,
            'error' => $exception->getMessage(),
        ]);
    }
}
