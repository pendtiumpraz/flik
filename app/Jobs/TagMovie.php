<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\MovieTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queueable wrapper around MovieTagger::tag().
 *
 * Routed to the `ai-realtime` queue so it competes with other low-latency AI tasks
 * (chat, search) rather than blocking long-running batch queues. Bounded retries
 * (2) keep flaky-network failures recoverable without thrashing if the provider
 * is genuinely down.
 */
class TagMovie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts before the job is marked as permanently failed.
     */
    public int $tries = 2;

    /**
     * Seconds to wait before retrying a failed attempt.
     */
    public int $backoff = 60;

    /**
     * Hard cap on a single tagging attempt (covers the AI HTTP call).
     */
    public int $timeout = 90;

    public function __construct(
        public Movie $movie,
    ) {
        $this->onQueue('ai-realtime');
    }

    public function handle(MovieTagger $tagger): void
    {
        // MovieTagger swallows its own errors and logs them, but if anything
        // bubbles up here we let the queue worker apply retry/backoff.
        $tagger->tag($this->movie);
    }

    /**
     * Final-failure hook (after all retries exhausted).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('TagMovie job permanently failed', [
            'movie_id' => $this->movie->id ?? null,
            'slug' => $this->movie->slug ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
