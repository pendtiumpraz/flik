<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\SeoMetaGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job: generate AI SEO meta for one movie.
 *
 * Dispatch:
 *   GenerateMovieSeo::dispatch($movie);
 *   GenerateMovieSeo::dispatch($movie)->onQueue('ai-batch');
 *
 * Default queue: 'ai-batch' (lower priority than user-facing 'default' queue).
 */
class GenerateMovieSeo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of attempts before failing permanently.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between attempts.
     *
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * Job timeout (seconds). AI calls can be slow.
     */
    public int $timeout = 120;

    public function __construct(
        public Movie $movie,
        public bool $force = false,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(SeoMetaGenerator $generator): void
    {
        // Refresh in case the row was updated between dispatch and run
        $this->movie->refresh();

        if (!$this->force && $this->movie->seo_generated_at !== null) {
            Log::info('GenerateMovieSeo: skipped (already generated)', [
                'movie_id' => $this->movie->id,
                'seo_generated_at' => $this->movie->seo_generated_at,
            ]);
            return;
        }

        $generator->generate($this->movie);

        Log::info('GenerateMovieSeo: success', [
            'movie_id' => $this->movie->id,
            'title' => $this->movie->title,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateMovieSeo: failed permanently', [
            'movie_id' => $this->movie->id ?? null,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Tags for Horizon / queue monitoring UIs.
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['ai', 'seo', 'movie:' . ($this->movie->id ?? 'unknown')];
    }
}
