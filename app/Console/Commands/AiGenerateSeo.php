<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMovieSeo;
use App\Models\Movie;
use App\Services\Ai\Tasks\SeoMetaGenerator;
use Illuminate\Console\Command;

/**
 * Bulk-generate SEO meta for movies via AI.
 *
 *   php artisan flik:ai:seo-all                # queue jobs for movies missing seo_generated_at
 *   php artisan flik:ai:seo-all --force        # regenerate even if already populated
 *   php artisan flik:ai:seo-all --sync         # run inline (no queue worker required)
 *   php artisan flik:ai:seo-all --limit=20     # cap the number processed this run
 *   php artisan flik:ai:seo-all --movie=42     # single movie by id or slug
 */
class AiGenerateSeo extends Command
{
    protected $signature = 'flik:ai:seo-all
        {--force : Regenerate meta even if seo_generated_at is already set}
        {--sync : Process synchronously instead of dispatching to the queue}
        {--limit= : Maximum number of movies to process}
        {--movie= : Process a single movie by id or slug}
        {--queue=ai-batch : Queue connection name to dispatch to}';

    protected $description = 'Bulk-generate AI SEO meta (title/description/keywords) for movies on FLiK';

    public function handle(SeoMetaGenerator $generator): int
    {
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $movieRef = $this->option('movie');
        $queue = (string) ($this->option('queue') ?: 'ai-batch');

        $query = Movie::query();

        if ($movieRef !== null) {
            $query->where(function ($q) use ($movieRef) {
                $q->where('id', $movieRef)->orWhere('slug', $movieRef);
            });
        } elseif (!$force) {
            $query->whereNull('seo_generated_at');
        }

        $query->orderByDesc('popularity');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No movies match the criteria. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d movie(s) %s (force=%s)',
            $total,
            $sync ? 'inline' : "via queue [{$queue}]",
            $force ? 'yes' : 'no'
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $fail = 0;

        $query->lazyById(50)->each(function (Movie $movie) use ($generator, $force, $sync, $queue, $bar, &$ok, &$fail) {
            try {
                if ($sync) {
                    if (!$force && $movie->seo_generated_at !== null) {
                        $bar->advance();
                        return;
                    }
                    $movie->loadMissing(['genres', 'castMembers']);
                    $generator->generate($movie);
                } else {
                    GenerateMovieSeo::dispatch($movie, $force)->onQueue($queue);
                }
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->newLine();
                $this->error(sprintf('[%s] %s — %s', $movie->id, $movie->title, $e->getMessage()));
            } finally {
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($sync) {
            $this->info("Done. Generated: {$ok}, Failed: {$fail}.");
        } else {
            $this->info("Done. Dispatched: {$ok} job(s) to queue [{$queue}], Failed: {$fail}.");
            $this->line('Run a queue worker if not already running:');
            $this->line("  php artisan queue:work --queue={$queue}");
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
