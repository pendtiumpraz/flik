<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMovieShortSummary;
use App\Models\Movie;
use App\Services\Ai\Tasks\MovieSummaryGenerator;
use Illuminate\Console\Command;

/**
 * Bulk-generate AI short summaries for the movie catalog.
 *
 * Examples:
 *   php artisan ai:generate-short-summary                       # all missing
 *   php artisan ai:generate-short-summary --force               # regenerate all
 *   php artisan ai:generate-short-summary --movie=inception     # one movie by slug or id
 *   php artisan ai:generate-short-summary --queue                # dispatch to queue instead of sync
 *   php artisan ai:generate-short-summary --limit=50            # cap batch size
 */
class AiGenerateShortSummary extends Command
{
    protected $signature = 'ai:generate-short-summary
                            {--movie= : Generate for a single movie (id or slug)}
                            {--force : Regenerate even if a summary already exists}
                            {--queue : Dispatch jobs to ai-realtime queue instead of running sync}
                            {--limit=0 : Max number of movies to process (0 = no limit)}
                            {--sleep=1 : Seconds to sleep between sync calls (rate-limit politeness)}';

    protected $description = 'Generate AI-written 3-sentence spoiler-free preview summaries for movies';

    public function handle(MovieSummaryGenerator $generator): int
    {
        $movies = $this->resolveMovies();

        if ($movies->isEmpty()) {
            $this->info('No movies match the criteria. Nothing to do.');

            return self::SUCCESS;
        }

        $total = $movies->count();
        $useQueue = (bool) $this->option('queue');
        $force = (bool) $this->option('force');
        $sleep = max(0, (int) $this->option('sleep'));

        $this->info(sprintf(
            '%s short-summary for %d movie(s)%s...',
            $useQueue ? 'Dispatching' : 'Generating',
            $total,
            $force ? ' [force=on]' : ''
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($movies as $movie) {
            if (!$force
                && filled($movie->ai_short_summary)
                && filled($movie->ai_short_summary_generated_at)
            ) {
                $skipped++;
                $bar->advance();

                continue;
            }

            try {
                if ($useQueue) {
                    GenerateMovieShortSummary::dispatch($movie, $force);
                } else {
                    $generator->generate($movie);

                    if ($sleep > 0) {
                        sleep($sleep);
                    }
                }

                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error(sprintf(
                    'Failed for #%d (%s): %s',
                    $movie->id,
                    $movie->title,
                    $e->getMessage()
                ));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Processed', 'Skipped', 'Failed', 'Mode'],
            [[$ok, $skipped, $failed, $useQueue ? 'queue' : 'sync']],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve target movies based on CLI options.
     *
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(): \Illuminate\Support\Collection
    {
        $single = $this->option('movie');

        if ($single !== null && $single !== '') {
            $query = Movie::query()->with('genres');

            if (is_numeric($single)) {
                $query->where('id', (int) $single);
            } else {
                $query->where('slug', $single);
            }

            $movie = $query->first();

            if (!$movie) {
                $this->error("Movie not found: {$single}");

                return collect();
            }

            return collect([$movie]);
        }

        $query = Movie::query()->with('genres');

        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('ai_short_summary')
                  ->orWhereNull('ai_short_summary_generated_at')
                  ->orWhere('ai_short_summary', '');
            });
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->orderByDesc('popularity')->get();
    }
}
