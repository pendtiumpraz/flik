<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\ThumbnailPicker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pick the best thumbnail frame for a movie using vision AI.
 *
 * Runs on the `ai-realtime` queue. Failures are logged and swallowed —
 * the job is intentionally best-effort: if Gemini is unconfigured, the
 * video is missing, or FFmpeg is unavailable, the picker returns null
 * and the job ends quietly.
 */
class PickMovieThumbnail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of attempts before the job is marked failed.
     */
    public int $tries = 2;

    /**
     * Maximum runtime, in seconds. Keyframe extraction on a long film
     * plus 10 Gemini calls can take a while.
     */
    public int $timeout = 900;

    public function __construct(
        public int $movieId,
        public int $frameCount = 10,
    ) {
        $this->onQueue('ai-realtime');
    }

    public function handle(ThumbnailPicker $picker): void
    {
        $movie = Movie::find($this->movieId);
        if (!$movie) {
            Log::warning('PickMovieThumbnail: movie not found', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        try {
            $bestPath = $picker->pickBest($movie, $this->frameCount);

            if ($bestPath === null) {
                Log::info('PickMovieThumbnail: no thumbnail picked (skipped)', [
                    'movie_id' => $movie->id,
                ]);
                return;
            }

            Log::info('PickMovieThumbnail: best thumbnail ready', [
                'movie_id' => $movie->id,
                'path' => $bestPath,
            ]);
        } catch (\Throwable $e) {
            Log::error('PickMovieThumbnail: unexpected failure', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PickMovieThumbnail: job failed permanently', [
            'movie_id' => $this->movieId,
            'error' => $e->getMessage(),
        ]);
    }
}
