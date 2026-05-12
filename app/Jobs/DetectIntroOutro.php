<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\IntroOutroDetector;
use App\Services\Ai\Tasks\RecapDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue wrapper around {@see IntroOutroDetector} (and {@see RecapDetector}
 * when the title looks like an episode).
 *
 * Lives on the 'ai-batch' queue alongside other slow AI tasks so it can be
 * rate-limited and back-pressured separately from interactive jobs.
 */
class DetectIntroOutro implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Conservative retry policy — intro/outro detection is best-effort and
     * should never thunder against a flapping AI provider.
     */
    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(
        public int $movieId,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(IntroOutroDetector $detector, RecapDetector $recap): void
    {
        $movie = Movie::find($this->movieId);
        if ($movie === null) {
            Log::warning('DetectIntroOutro: movie not found', ['movie_id' => $this->movieId]);

            return;
        }

        $result = $detector->detect($movie);

        // Recap detection is opportunistic — only relevant for episodes,
        // RecapDetector itself bails out for non-episode titles.
        $recapEnd = $recap->detect($movie->fresh() ?? $movie);

        Log::info('DetectIntroOutro: completed', [
            'movie_id' => $movie->id,
            'intro_start' => $result['intro_start'] ?? null,
            'intro_end' => $result['intro_end'] ?? null,
            'outro_start' => $result['outro_start'] ?? null,
            'recap_end' => $recapEnd,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DetectIntroOutro: failed', [
            'movie_id' => $this->movieId,
            'error' => $e->getMessage(),
        ]);
    }
}
