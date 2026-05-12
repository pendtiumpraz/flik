<?php

namespace App\Console\Commands;

use App\Jobs\PickMovieThumbnail;
use App\Models\Movie;
use App\Services\Ai\Tasks\ThumbnailPicker;
use Illuminate\Console\Command;

/**
 * Bulk-run the vision-AI thumbnail picker over the catalog.
 *
 * Examples:
 *   php artisan ai:pick-thumbnails
 *   php artisan ai:pick-thumbnails --movie=42
 *   php artisan ai:pick-thumbnails --frames=15 --limit=20
 *   php artisan ai:pick-thumbnails --sync          # run inline, do not queue
 */
class AiPickThumbnails extends Command
{
    protected $signature = 'ai:pick-thumbnails
        {--movie= : Only run for the movie with this ID or slug}
        {--frames=10 : Number of keyframes to extract & score per movie}
        {--limit= : Maximum number of movies to process}
        {--sync : Run the picker inline (skip the queue)}';

    protected $description = 'Use Gemini vision AI to pick the best thumbnail frame for movies with a video.';

    public function handle(ThumbnailPicker $picker): int
    {
        $frames = max(1, (int) $this->option('frames'));
        $sync = (bool) $this->option('sync');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $movieRef = $this->option('movie');

        $query = Movie::query()
            ->whereNotNull('video_path')
            ->where('video_path', '!=', '');

        if ($movieRef !== null) {
            $query->where(function ($q) use ($movieRef) {
                $q->where('id', $movieRef)->orWhere('slug', $movieRef);
            });
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $movies = $query->orderBy('id')->get();

        if ($movies->isEmpty()) {
            $this->warn('No movies match the given criteria (need a video_path).');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d movie(s) — frames=%d, mode=%s',
            $movies->count(),
            $frames,
            $sync ? 'sync' : 'queue:ai-realtime',
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $picked = 0;
        $skipped = 0;

        foreach ($movies as $movie) {
            if ($sync) {
                try {
                    $best = $picker->pickBest($movie, $frames);
                    if ($best !== null) {
                        $picked++;
                        $this->newLine();
                        $this->line(sprintf('  [%d] %s → %s', $movie->id, $movie->title, $best));
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->newLine();
                    $this->error(sprintf('  [%d] %s failed: %s', $movie->id, $movie->title, $e->getMessage()));
                }
            } else {
                PickMovieThumbnail::dispatch($movie->id, $frames);
                $picked++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info(sprintf('Done. Picked: %d. Skipped: %d.', $picked, $skipped));
        } else {
            $this->info(sprintf('Done. Dispatched %d job(s) to ai-realtime queue.', $picked));
        }

        return self::SUCCESS;
    }
}
