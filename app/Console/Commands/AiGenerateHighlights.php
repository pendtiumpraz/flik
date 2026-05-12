<?php

namespace App\Console\Commands;

use App\Jobs\GenerateHighlightReel;
use App\Models\Movie;
use App\Models\MovieHighlightReel;
use App\Services\Ai\Tasks\HighlightReelGenerator;
use Illuminate\Console\Command;

/**
 * Bulk-generate movie highlight reels (auto 3-min recap).
 *
 * Examples:
 *   php artisan flik:ai:highlights --all                       # every movie with a video, skip ones already done
 *   php artisan flik:ai:highlights --movie=42                  # single movie, sync
 *   php artisan flik:ai:highlights --all --queue               # dispatch to `transcoding` queue
 *   php artisan flik:ai:highlights --movie=42 --duration=240   # 4-minute reel
 *   php artisan flik:ai:highlights --all --force --limit=10    # re-render up to 10 movies even if reels exist
 */
class AiGenerateHighlights extends Command
{
    protected $signature = 'flik:ai:highlights
        {--all : Process every movie that has a video file}
        {--movie= : Process a single movie by ID}
        {--duration=180 : Target reel duration in seconds (default 180 = 3 min)}
        {--queue : Dispatch to the transcoding queue instead of running inline}
        {--force : Re-render even for movies that already have a ready reel}
        {--limit=0 : Maximum number of movies to process (0 = no limit)}';

    protected $description = 'Generate the auto 3-minute highlight reel for one movie or in bulk.';

    public function handle(HighlightReelGenerator $generator): int
    {
        $all      = (bool) $this->option('all');
        $movieOpt = $this->option('movie');
        $duration = max(60, (int) $this->option('duration'));
        $queue    = (bool) $this->option('queue');
        $force    = (bool) $this->option('force');
        $limit    = (int) $this->option('limit');

        if (!$all && ($movieOpt === null || $movieOpt === '')) {
            $this->error('Specify --all or --movie=<id>.');
            return self::INVALID;
        }

        $movies = $this->resolveMovies($movieOpt, $force, $limit);

        if ($movies->isEmpty()) {
            $this->info('No movies to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Generating highlight reels (%ds target) for %d movie(s) — %s.',
            $duration,
            $movies->count(),
            $queue ? 'queued on transcoding' : 'synchronous',
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($movies as $movie) {
            try {
                if ($queue) {
                    GenerateHighlightReel::dispatch($movie->id, $duration);
                    $ok++;
                } else {
                    $reel = $generator->generate($movie, $duration);
                    if ($reel->status === 'ready') {
                        $ok++;
                    } else {
                        $skipped++;
                        $this->newLine();
                        $this->warn(sprintf(
                            '  Movie #%d (%s) skipped: %s',
                            $movie->id,
                            $movie->title,
                            $reel->error_message ?? 'unknown reason'
                        ));
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
    protected function resolveMovies(mixed $movieOpt, bool $force, int $limit)
    {
        $query = Movie::query()
            ->where(function ($q) {
                $q->whereNotNull('video_path')->where('video_path', '!=', '');
            });

        if ($movieOpt !== null && $movieOpt !== '') {
            $query->where('id', (int) $movieOpt);
        }

        if (!$force) {
            $existing = MovieHighlightReel::query()
                ->where('status', 'ready')
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
