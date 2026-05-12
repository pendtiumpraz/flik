<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMovieTrivia;
use App\Models\Movie;
use App\Models\MovieTrivia;
use App\Services\Ai\Tasks\TriviaGenerator;
use Illuminate\Console\Command;

class AiGenerateTrivia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage examples:
     *   php artisan ai:generate-trivia                  # all movies missing trivia (sync)
     *   php artisan ai:generate-trivia --movie=42       # one movie
     *   php artisan ai:generate-trivia --slug=inception
     *   php artisan ai:generate-trivia --all --queue    # dispatch jobs for ALL movies
     *   php artisan ai:generate-trivia --count=10 --limit=50
     */
    protected $signature = 'ai:generate-trivia
        {--movie= : Specific movie ID}
        {--slug= : Specific movie slug}
        {--all : Process every movie in the catalog (otherwise only those without trivia)}
        {--queue : Dispatch jobs to ai-batch queue instead of running synchronously}
        {--count=8 : Number of trivia facts to generate per movie}
        {--limit=0 : Max number of movies to process (0 = no limit)}';

    protected $description = 'Bulk-generate movie trivia using Wikipedia + AI';

    public function handle(TriviaGenerator $generator): int
    {
        $count = max(1, min(20, (int) $this->option('count')));
        $limit = (int) $this->option('limit');
        $async = (bool) $this->option('queue');

        $movies = $this->resolveMovies($limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies matched the given criteria.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Generating %d trivia facts for %d movie(s) %s...',
            $count,
            $movies->count(),
            $async ? '[queued]' : '[sync]'
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok = 0;
        $fail = 0;

        foreach ($movies as $movie) {
            try {
                if ($async) {
                    GenerateMovieTrivia::dispatch($movie->id, $count);
                    $ok++;
                } else {
                    $result = $generator->generate($movie, $count);
                    if ($result->isNotEmpty()) {
                        $ok++;
                    } else {
                        $fail++;
                        $this->warn("\n  ! No trivia generated for #{$movie->id} {$movie->title}");
                    }
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->error("\n  ✗ #{$movie->id} {$movie->title}: " . $e->getMessage());
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
            // Only movies that don't have any trivia yet
            $existingIds = MovieTrivia::query()->distinct()->pluck('movie_id')->all();
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
