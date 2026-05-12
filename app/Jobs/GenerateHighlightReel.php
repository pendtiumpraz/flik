<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\HighlightReelGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Render the 3-minute highlight reel for a single movie.
 *
 * Queue: `transcoding` (heavy FFmpeg work — keep off the default queue).
 * Tries: 1 (re-runs are expensive; failures should be triaged manually).
 * Timeout: 1 hour (worst case for large source films + slow disks).
 */
class GenerateHighlightReel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public $tries = 1;

    /** @var int Seconds before the worker kills the job. */
    public int $timeout = 3600;

    public function __construct(
        public int $movieId,
        public int $targetDurationSeconds = 180,
    ) {
        $this->onQueue('transcoding');
    }

    public function handle(HighlightReelGenerator $generator): void
    {
        $movie = Movie::find($this->movieId);
        if (!$movie) {
            Log::warning('GenerateHighlightReel: movie not found', ['movie_id' => $this->movieId]);
            return;
        }

        $reel = $generator->generate($movie, $this->targetDurationSeconds);

        Log::info('GenerateHighlightReel: completed', [
            'movie_id' => $movie->id,
            'status'   => $reel->status,
            'scenes'   => $reel->scene_count,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateHighlightReel: failed', [
            'movie_id' => $this->movieId,
            'error'    => $e->getMessage(),
        ]);
    }
}
