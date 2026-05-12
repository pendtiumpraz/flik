<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\MovieQuizQuestion;
use App\Services\Ai\Tasks\QuizQuestionGenerator;
use Illuminate\Console\Command;

/**
 * Bulk-generate quiz questions for movies using AI.
 *
 * Usage:
 *   php artisan flik:ai:quiz                  # all movies missing quiz
 *   php artisan flik:ai:quiz --all            # rebuild for every movie
 *   php artisan flik:ai:quiz --movie=42       # single movie by id
 *   php artisan flik:ai:quiz --slug=inception # single movie by slug
 *   php artisan flik:ai:quiz --count=10 --limit=100
 */
class AiGenerateQuiz extends Command
{
    protected $signature = 'flik:ai:quiz
        {--movie= : Specific movie ID}
        {--slug= : Specific movie slug}
        {--all : Process every movie in the catalog (otherwise only those without quiz questions)}
        {--count=10 : Number of quiz questions to generate per movie}
        {--limit=0 : Max number of movies to process (0 = no limit)}';

    protected $description = 'Bulk-generate AI movie trivia quiz questions';

    public function handle(QuizQuestionGenerator $generator): int
    {
        $count = max(3, min(20, (int) $this->option('count')));
        $limit = (int) $this->option('limit');

        $movies = $this->resolveMovies($limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies matched the given criteria.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Generating %d quiz questions for %d movie(s)...',
            $count,
            $movies->count()
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok   = 0;
        $fail = 0;

        foreach ($movies as $movie) {
            try {
                $result = $generator->generate($movie, $count);
                if ($result->isNotEmpty()) {
                    $ok++;
                } else {
                    $fail++;
                    $this->warn("\n  ! No quiz generated for #{$movie->id} {$movie->title}");
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->error("\n  x #{$movie->id} {$movie->title}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Done. ok=%d fail=%d total=%d', $ok, $fail, $movies->count()));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(int $limit): \Illuminate\Support\Collection
    {
        if ($id = $this->option('movie')) {
            $movie = Movie::find((int) $id);
            return $movie ? collect([$movie]) : collect();
        }

        if ($slug = $this->option('slug')) {
            $movie = Movie::where('slug', $slug)->first();
            return $movie ? collect([$movie]) : collect();
        }

        $query = Movie::query()->orderByDesc('popularity');

        if (!$this->option('all')) {
            // Only movies that don't have quiz questions yet.
            $existingIds = MovieQuizQuestion::query()->distinct()->pluck('movie_id')->all();
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
