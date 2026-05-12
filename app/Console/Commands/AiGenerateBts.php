<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBehindScenes;
use App\Models\Movie;
use App\Models\MovieBehindScenes;
use App\Services\Ai\Tasks\BehindScenesGenerator;
use Illuminate\Console\Command;

/**
 * Bulk-generate "Behind the Scenes" narrative articles using
 * Wikipedia + web search + AI.
 *
 * Usage examples:
 *   php artisan flik:ai:bts                  # all movies missing BTS (sync)
 *   php artisan flik:ai:bts --movie=42       # single movie by id
 *   php artisan flik:ai:bts --slug=inception
 *   php artisan flik:ai:bts --all --queue    # dispatch jobs for ALL movies
 *   php artisan flik:ai:bts --limit=50
 */
class AiGenerateBts extends Command
{
    protected $signature = 'flik:ai:bts
        {--movie= : Specific movie ID}
        {--slug= : Specific movie slug}
        {--all : Process every movie in the catalog (otherwise only those without BTS)}
        {--queue : Dispatch jobs to ai-batch queue instead of running synchronously}
        {--limit=0 : Max number of movies to process (0 = no limit)}';

    protected $description = 'Bulk-generate movie "Behind the Scenes" narrative using Wikipedia + AI';

    public function handle(BehindScenesGenerator $generator): int
    {
        $limit = (int) $this->option('limit');
        $async = (bool) $this->option('queue');

        $movies = $this->resolveMovies($limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies matched the given criteria.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Generating Behind-the-Scenes articles for %d movie(s) %s...',
            $movies->count(),
            $async ? '[queued]' : '[sync]'
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok   = 0;
        $fail = 0;

        foreach ($movies as $movie) {
            try {
                if ($async) {
                    GenerateBehindScenes::dispatch($movie->id);
                    $ok++;
                } else {
                    $result = $generator->generate($movie);
                    if ($result->isNotEmpty()) {
                        $ok++;
                    } else {
                        $fail++;
                        $this->warn("\n  ! No BTS generated for #{$movie->id} {$movie->title}");
                    }
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->error("\n  x #{$movie->id} {$movie->title}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. ok=%d fail=%d total=%d',
            $ok,
            $fail,
            $movies->count()
        ));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve the set of movies to process based on CLI options.
     *
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(int $limit): \Illuminate\Support\Collection
    {
        // Single movie by id
        if ($id = $this->option('movie')) {
            $movie = Movie::find((int) $id);
            return $movie ? collect([$movie]) : collect();
        }

        // Single movie by slug
        if ($slug = $this->option('slug')) {
            $movie = Movie::where('slug', $slug)->first();
            return $movie ? collect([$movie]) : collect();
        }

        // Bulk
        $query = Movie::query()->orderByDesc('popularity');

        if (!$this->option('all')) {
            // Only movies that don't have any BTS sections yet
            $existingIds = MovieBehindScenes::query()->distinct()->pluck('movie_id')->all();
            if (!empty($existingIds)) {
                $query->whereNotIn('id', $existingIds);
            }
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
