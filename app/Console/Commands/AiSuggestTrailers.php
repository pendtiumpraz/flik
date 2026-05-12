<?php

namespace App\Console\Commands;

use App\Jobs\SuggestTrailerCuts;
use App\Models\Movie;
use App\Models\MovieTrailerSuggestion;
use App\Services\Ai\Tasks\TrailerSuggester;
use Illuminate\Console\Command;

/**
 * Bulk-suggest trailer cuts.
 *
 * Examples:
 *   php artisan ai:suggest-trailers                      # all movies with video, skip ones already suggested
 *   php artisan ai:suggest-trailers --movie=42           # single movie, sync
 *   php artisan ai:suggest-trailers --queue              # dispatch each to ai-batch queue
 *   php artisan ai:suggest-trailers --force --count=5    # re-run even if already done, top-5
 */
class AiSuggestTrailers extends Command
{
    protected $signature = 'ai:suggest-trailers
        {--movie= : Process a single movie ID}
        {--count=3 : Number of suggestions to keep per movie}
        {--queue : Dispatch to the ai-batch queue instead of running inline}
        {--force : Re-process movies that already have suggestions}
        {--limit=0 : Maximum number of movies to process (0 = no limit)}';

    protected $description = 'Generate trailer-cut suggestions (top-N best 30s windows) for movies.';

    public function handle(TrailerSuggester $suggester): int
    {
        $count = max(1, (int) $this->option('count'));
        $force = (bool) $this->option('force');
        $queue = (bool) $this->option('queue');
        $limit = (int) $this->option('limit');

        $movies = $this->resolveMovies($force, $limit);

        if ($movies->isEmpty()) {
            $this->info('No movies to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d movie(s), top-%d suggestions each (%s).',
            $movies->count(),
            $count,
            $queue ? 'queued on ai-batch' : 'synchronous',
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($movies as $movie) {
            try {
                if ($queue) {
                    SuggestTrailerCuts::dispatch($movie->id, $count);
                    $ok++;
                } else {
                    $result = $suggester->suggest($movie, $count);
                    if ($result->isEmpty()) {
                        $skipped++;
                    } else {
                        $ok++;
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error(sprintf('Movie #%d (%s): %s', $movie->id, $movie->title, $e->getMessage()));
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. %s: %d   Skipped: %d   Errors: %d',
            $queue ? 'Dispatched' : 'Processed',
            $ok,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(bool $force, int $limit)
    {
        $movieId = $this->option('movie');

        $query = Movie::query()
            ->where(function ($q) {
                $q->whereNotNull('video_path')->where('video_path', '!=', '');
            });

        if ($movieId !== null && $movieId !== '') {
            $query->where('id', (int) $movieId);
        }

        if (!$force) {
            $existing = MovieTrailerSuggestion::query()
                ->select('movie_id')
                ->distinct()
                ->pluck('movie_id')
                ->all();
            if (!empty($existing)) {
                $query->whereNotIn('id', $existing);
            }
        }

        $query->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
