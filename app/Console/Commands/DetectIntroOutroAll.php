<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DetectIntroOutro;
use App\Models\Movie;
use App\Services\Ai\Tasks\IntroOutroDetector;
use App\Services\Ai\Tasks\RecapDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Bulk-run the auto-skip detector across the catalog.
 *
 * Examples:
 *   php artisan flik:detect:intro-outro --movie=42                 # one movie, sync
 *   php artisan flik:detect:intro-outro --slug=inception           # one movie by slug, sync
 *   php artisan flik:detect:intro-outro                            # missing markers only, sync
 *   php artisan flik:detect:intro-outro --all                      # every movie, sync
 *   php artisan flik:detect:intro-outro --all --queue              # every movie, queued
 *   php artisan flik:detect:intro-outro --all --limit=50 --queue   # cap + queue
 */
class DetectIntroOutroAll extends Command
{
    protected $signature = 'flik:detect:intro-outro
        {--movie= : Specific movie ID}
        {--slug= : Specific movie slug}
        {--all : Process every movie (otherwise only those missing intro_end_seconds)}
        {--queue : Dispatch jobs to the ai-batch queue instead of running synchronously}
        {--limit=0 : Cap on number of movies (0 = no limit)}';

    protected $description = 'Detect intro / outro / recap markers for movies (subtitle-aware, AI-refined when configured).';

    public function handle(IntroOutroDetector $detector, RecapDetector $recap): int
    {
        $async = (bool) $this->option('queue');
        $limit = (int) $this->option('limit');

        $movies = $this->resolveMovies($limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies match the criteria.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Detecting intro/outro/recap for %d movie(s) [%s]...',
            $movies->count(),
            $async ? 'queued' : 'sync',
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok = 0;
        $fail = 0;

        foreach ($movies as $movie) {
            try {
                if ($async) {
                    DetectIntroOutro::dispatch($movie->id);
                    $ok++;
                } else {
                    $result = $detector->detect($movie);
                    $recap->detect($movie->fresh() ?? $movie);
                    $ok++;

                    $this->newLine();
                    $this->line(sprintf(
                        '  <info>%s</info> intro_end=%s outro_start=%s',
                        $movie->title,
                        $this->fmt($result['intro_end'] ?? null),
                        $this->fmt($result['outro_start'] ?? null),
                    ));
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->newLine();
                $this->error(sprintf('  #%d %s -> %s', $movie->id, $movie->title, $e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. %s=%d failed=%d total=%d',
            $async ? 'dispatched' : 'processed',
            $ok,
            $fail,
            $movies->count(),
        ));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Movie>
     */
    protected function resolveMovies(int $limit): Collection
    {
        if ($id = $this->option('movie')) {
            $movie = Movie::find((int) $id);

            return $movie !== null ? collect([$movie]) : collect();
        }

        if ($slug = $this->option('slug')) {
            $movie = Movie::where('slug', $slug)->first();

            return $movie !== null ? collect([$movie]) : collect();
        }

        $query = Movie::query()->orderByDesc('popularity');

        if (! $this->option('all')) {
            // Default: only movies missing intro_end_seconds (treated as
            // the canonical "have we run detection yet" marker).
            $query->whereNull('intro_end_seconds');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    protected function fmt(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return number_format($seconds, 2, '.', '') . 's';
    }
}
