<?php

namespace App\Console\Commands;

use App\Jobs\ExtractMovieQuotes;
use App\Models\Movie;
use App\Models\MovieQuote;
use App\Models\MovieSubtitle;
use App\Services\Ai\Tasks\QuoteExtractor;
use Illuminate\Console\Command;

/**
 * Bulk-extract memorable quotes for movies.
 *
 * Examples:
 *   php artisan ai:extract-quotes                                   # all movies that have subtitles, id, sync
 *   php artisan ai:extract-quotes --movie=42                        # single movie by id
 *   php artisan ai:extract-quotes --slug=inception                  # single movie by slug
 *   php artisan ai:extract-quotes --language=en --count=8 --queue   # dispatch jobs to queue
 *   php artisan ai:extract-quotes --force                           # overwrite even if quotes already exist
 */
class AiExtractQuotes extends Command
{
    protected $signature = 'ai:extract-quotes
        {--movie= : Specific movie ID}
        {--slug= : Specific movie slug}
        {--language=id : BCP-47 language code}
        {--count=5 : Number of quotes per movie}
        {--queue : Dispatch as background jobs instead of running synchronously}
        {--force : Re-extract even for movies that already have quotes in this language}
        {--limit=0 : Cap on number of movies (0 = no limit)}';

    protected $description = 'Bulk-extract memorable quotes from movies via AI (uses subtitles when available).';

    public function handle(QuoteExtractor $extractor): int
    {
        $language = (string) $this->option('language') ?: 'id';
        $count = max(1, (int) $this->option('count'));
        $useQueue = (bool) $this->option('queue');
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        $movies = $this->resolveMovies($language, $force, $limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies match the criteria.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Extracting %d quote(s) per movie in [%s] for %d movie(s) (%s mode).',
            $count,
            $language,
            $movies->count(),
            $useQueue ? 'queued' : 'sync'
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $okCount = 0;
        $failCount = 0;

        foreach ($movies as $movie) {
            try {
                if ($useQueue) {
                    ExtractMovieQuotes::dispatch($movie->id, $language, $count);
                    $okCount++;
                } else {
                    $quotes = $extractor->extract($movie, $language, $count);
                    $okCount++;
                    $this->line('');
                    $this->line(sprintf(
                        '  <info>%s</info> → %d quote(s)',
                        $movie->title,
                        $quotes->count()
                    ));
                }
            } catch (\Throwable $e) {
                $failCount++;
                $this->line('');
                $this->error(sprintf('  %s → %s', $movie->title, $e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. %s: %d, failed: %d.',
            $useQueue ? 'Dispatched' : 'Processed',
            $okCount,
            $failCount,
        ));

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve target movies according to the CLI options.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Movie>
     */
    protected function resolveMovies(string $language, bool $force, int $limit)
    {
        $movieOpt = $this->option('movie');
        $slugOpt = $this->option('slug');

        $query = Movie::query();

        if ($movieOpt) {
            $query->where('id', (int) $movieOpt);
        } elseif ($slugOpt) {
            $query->where('slug', $slugOpt);
        } else {
            // Default selection: movies that have a ready subtitle in the requested language.
            $query->whereIn('id', MovieSubtitle::query()
                ->where('language_code', $language)
                ->where('status', 'ready')
                ->where('is_active', true)
                ->select('movie_id')
            );
        }

        if (! $force) {
            $query->whereNotIn('id', MovieQuote::query()
                ->where('language_code', $language)
                ->select('movie_id')
            );
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->orderBy('id')->get();
    }
}
