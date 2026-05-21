<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\SoundtrackAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job: run SoundtrackAnalyzer for one movie and persist the result
 * onto `movies.soundtrack_analysis` (+ stamps `soundtrack_analyzed_at`).
 *
 * Triggered from the admin movie row (POST /admin/movies/{movie}/soundtrack).
 * Routed to `ai-batch` because the analyzer makes a single LLM call but is
 * not user-blocking — the public detail page renders gracefully when the
 * column is still NULL.
 */
class AnalyzeSoundtrack implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(public int $movieId)
    {
        $this->onQueue('ai-batch');
    }

    public function handle(SoundtrackAnalyzer $analyzer): void
    {
        /** @var Movie|null $movie */
        $movie = Movie::find($this->movieId);

        if ($movie === null) {
            Log::info('AnalyzeSoundtrack: movie vanished', ['movie_id' => $this->movieId]);
            return;
        }

        try {
            $result = $analyzer->analyze($movie);
        } catch (\Throwable $e) {
            Log::warning('AnalyzeSoundtrack: analyzer threw', [
                'movie_id' => $this->movieId,
                'error'    => $e->getMessage(),
            ]);
            return;
        }

        // Empty shape (composer + style both blank) → analyzer hit a fallback
        // path; persist nothing so the public component stays hidden.
        $hasContent = !empty($result['composer'])
            || !empty($result['style'])
            || !empty($result['mood'])
            || !empty($result['recommendation']);

        if (! $hasContent) {
            Log::info('AnalyzeSoundtrack: empty result, skipping persist', ['movie_id' => $this->movieId]);
            return;
        }

        // Skip model events — this is a system write.
        Movie::query()
            ->whereKey($movie->id)
            ->update([
                'soundtrack_analysis'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                'soundtrack_analyzed_at'  => now(),
            ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('AnalyzeSoundtrack job permanently failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }
}
