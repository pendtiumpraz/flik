<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeCinematography;
use App\Models\Movie;
use App\Models\MovieCinematography;
use App\Services\Ai\Tasks\CinematographyAnalyzer;
use Illuminate\Console\Command;

/**
 * Bulk-run the cinematography / colour analyser.
 *
 * Examples:
 *   php artisan flik:ai:cinematography --movie=42
 *   php artisan flik:ai:cinematography --movie=inception        # slug also accepted
 *   php artisan flik:ai:cinematography --all                    # process every movie with video
 *   php artisan flik:ai:cinematography --all --queue            # dispatch to ai-batch queue
 *   php artisan flik:ai:cinematography --all --samples=8
 */
class AiCinematography extends Command
{
    protected $signature = 'flik:ai:cinematography
        {--all : Process every movie that has a video_path}
        {--movie= : Process a single movie by ID or slug}
        {--samples=6 : Number of keyframes to sample per movie (1-12)}
        {--queue : Dispatch jobs to the ai-batch queue instead of running synchronously}
        {--limit=0 : Cap the number of movies (0 = no cap)}';

    protected $description = 'Run the cinematography / colour analyser over the catalog (FFmpeg + Gemini vision).';

    public function handle(CinematographyAnalyzer $analyzer): int
    {
        $samples = max(1, min(12, (int) $this->option('samples')));
        $async   = (bool) $this->option('queue');
        $limit   = (int) $this->option('limit');

        $movies = $this->resolveMovies($limit);

        if ($movies->isEmpty()) {
            $this->warn('No movies matched. Pass --movie=ID or --all (movies need a video_path).');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Analysing cinematography for %d movie(s) [samples=%d, mode=%s]...',
            $movies->count(),
            $samples,
            $async ? 'queue:ai-batch' : 'sync',
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok   = 0;
        $fail = 0;

        foreach ($movies as $movie) {
            try {
                if ($async) {
                    AnalyzeCinematography::dispatch($movie->id, $samples);
                    $ok++;
                } else {
                    $record = $analyzer->analyze($movie, $samples);
                    if ($record->hasAnalysis()) {
                        $ok++;
                    } else {
                        $fail++;
                        $this->newLine();
                        $this->warn(sprintf('  ! placeholder only for #%d %s', $movie->id, $movie->title));
                    }
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->newLine();
                $this->error(sprintf('  x #%d %s: %s', $movie->id, $movie->title, $e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Done. ok=%d fail=%d total=%d', $ok, $fail, $movies->count()));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve which movies to process based on CLI flags.
     *
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(int $limit): \Illuminate\Support\Collection
    {
        // Single movie by id or slug
        if ($ref = $this->option('movie')) {
            $movie = Movie::where('id', $ref)
                ->orWhere('slug', $ref)
                ->first();
            return $movie ? collect([$movie]) : collect();
        }

        if (!$this->option('all')) {
            $this->warn('Pass either --movie=ID or --all.');
            return collect();
        }

        $query = Movie::query()
            ->whereNotNull('video_path')
            ->where('video_path', '!=', '')
            ->orderByDesc('popularity');

        // Skip movies already analysed (any non-null generated_at row).
        $existingIds = MovieCinematography::query()
            ->whereNotNull('generated_at')
            ->pluck('movie_id')
            ->all();
        if (!empty($existingIds)) {
            $query->whereNotIn('id', $existingIds);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
