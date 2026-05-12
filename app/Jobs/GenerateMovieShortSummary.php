<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\MovieSummaryGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate AI-produced short (3-sentence, spoiler-free) summary for a Movie.
 *
 * Queue: ai-realtime — used for low-latency, user-facing AI tasks
 * (preview cards rendered on demand). Bulk catalog backfills should go through
 * the AiGenerateShortSummary console command, which can dispatch to a slower
 * queue if needed.
 */
class GenerateMovieShortSummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of attempts before the job is considered failed.
     */
    public int $tries = 3;

    /**
     * Backoff (seconds) between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    /**
     * Max execution time (seconds) — AI calls have a 30s upstream timeout.
     */
    public int $timeout = 60;

    public function __construct(
        public Movie $movie,
        public bool $force = false,
    ) {
        $this->onQueue('ai-realtime');
    }

    public function handle(MovieSummaryGenerator $generator): void
    {
        // Skip if we already have a summary and caller didn't force a refresh.
        if (!$this->force
            && filled($this->movie->ai_short_summary)
            && filled($this->movie->ai_short_summary_generated_at)
        ) {
            return;
        }

        $generator->generate($this->movie);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateMovieShortSummary failed', [
            'movie_id'    => $this->movie->id,
            'movie_title' => $this->movie->title,
            'error'       => $e->getMessage(),
        ]);
    }
}
