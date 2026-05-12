<?php

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Ai\Tasks\QuoteExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queueable wrapper around QuoteExtractor.
 * Lives on the 'ai-batch' queue together with other slow AI tasks.
 */
class ExtractMovieQuotes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 60;

    public function __construct(
        public int $movieId,
        public string $language = 'id',
        public int $count = 5,
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(QuoteExtractor $extractor): void
    {
        $movie = Movie::find($this->movieId);
        if (! $movie) {
            Log::warning('ExtractMovieQuotes: movie not found', ['movie_id' => $this->movieId]);

            return;
        }

        $quotes = $extractor->extract($movie, $this->language, $this->count);

        Log::info('ExtractMovieQuotes: completed', [
            'movie_id' => $movie->id,
            'language' => $this->language,
            'extracted' => $quotes->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ExtractMovieQuotes: failed', [
            'movie_id' => $this->movieId,
            'language' => $this->language,
            'error' => $e->getMessage(),
        ]);
    }
}
