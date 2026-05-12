<?php

namespace App\Console\Commands;

use App\Jobs\TagMovie;
use App\Models\Movie;
use Illuminate\Console\Command;

/**
 * Dispatch a TagMovie job for every untagged film in the catalog.
 *
 * Usage:
 *   php artisan flik:ai:tag-all                 # only untagged
 *   php artisan flik:ai:tag-all --force         # re-tag everything
 *   php artisan flik:ai:tag-all --limit=50      # cap dispatch count
 *   php artisan flik:ai:tag-all --sync          # run inline (no queue) for debug
 */
class AiTagAllMovies extends Command
{
    protected $signature = 'flik:ai:tag-all
        {--force : Re-tag movies that already have ai_tagged_at set}
        {--limit= : Maximum number of movies to dispatch}
        {--sync : Run jobs synchronously instead of dispatching to the queue}';

    protected $description = 'Auto-tag movies with AI-derived mood/era/themes/audience/intensity.';

    public function handle(): int
    {
        $query = Movie::query()->orderBy('id');

        if (!$this->option('force')) {
            $query->whereNull('ai_tagged_at');
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to do — no untagged movies found. Use --force to re-tag everything.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Dispatching %d movie(s) for AI tagging%s...',
            $total,
            $this->option('sync') ? ' (sync mode)' : ' to queue [ai-realtime]',
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dispatched = 0;
        $failed = 0;

        $query->chunkById(100, function ($movies) use (&$dispatched, &$failed, $bar) {
            foreach ($movies as $movie) {
                try {
                    if ($this->option('sync')) {
                        TagMovie::dispatchSync($movie);
                    } else {
                        TagMovie::dispatch($movie);
                    }
                    $dispatched++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn(sprintf(
                        '  ! Failed to dispatch movie #%d (%s): %s',
                        $movie->id,
                        $movie->slug ?? '?',
                        $e->getMessage(),
                    ));
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Done. Dispatched: %d, Failed: %d.', $dispatched, $failed));

        if (!$this->option('sync')) {
            $this->line('Make sure a queue worker is running:  php artisan queue:work --queue=ai-realtime');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
