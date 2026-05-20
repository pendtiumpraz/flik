<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Tmdb\MovieImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queueable wrapper around {@see MovieImporter::import()}.
 *
 * Routed to the `ai-batch` queue because a "heavy" TMDB import (download
 * images + translate synopsis + seed TV seasons) can run for a couple of
 * minutes — too long to block an admin request, light enough to share a
 * queue with the nightly recommendation recompute and friends.
 *
 * Bounded retries (2) prevent thrash if TMDB rate-limits us, and the long
 * timeout (10 minutes) accommodates the slowest case: a full season-by-
 * season seed for a long-running series with image mirroring on.
 */
class ImportFromTmdb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts before the job is marked as permanently failed.
     */
    public int $tries = 2;

    /**
     * Seconds between retries — give TMDB a chance to cool down between
     * 429-induced attempts.
     */
    public int $backoff = 60;

    /**
     * Hard cap on a single import attempt. Image downloads dominate the
     * runtime; 10 minutes is generous but bounded.
     */
    public int $timeout = 600;

    /**
     * @param  int                     $tmdbId   TMDB id to import.
     * @param  string                  $type     'movie' or 'tv'.
     * @param  array<string, mixed>    $options  Forwarded to MovieImporter::import().
     * @param  int|null                $userId   Optional user id to credit / notify.
     */
    public function __construct(
        public readonly int $tmdbId,
        public readonly string $type = 'movie',
        public readonly array $options = [],
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(MovieImporter $importer): void
    {
        try {
            $movie = $importer->import($this->tmdbId, $this->type, $this->options);
            Log::info('TMDB import job completed', [
                'tmdb_id' => $this->tmdbId,
                'type' => $this->type,
                'movie_id' => $movie->id,
                'user_id' => $this->userId,
            ]);
        } catch (\Throwable $e) {
            // Re-throw so the queue worker applies retry/backoff. The
            // user-facing notification still fires from failed() if all
            // retries are exhausted.
            Log::warning('TMDB import job attempt failed', [
                'tmdb_id' => $this->tmdbId,
                'type' => $this->type,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Final-failure hook (after all retries exhausted).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ImportFromTmdb job permanently failed', [
            'tmdb_id' => $this->tmdbId,
            'type' => $this->type,
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
