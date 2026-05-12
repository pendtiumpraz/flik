<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMovieSynopsis;
use App\Models\Movie;
use App\Services\Ai\Tasks\SynopsisGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk-generate AI synopses for the entire catalog.
 *
 * Usage:
 *   php artisan flik:ai:synopsis-all                  # queue jobs for movies missing a synopsis
 *   php artisan flik:ai:synopsis-all --force          # regenerate even if synopsis exists
 *   php artisan flik:ai:synopsis-all --sync           # run inline (no queue) — useful in dev
 *   php artisan flik:ai:synopsis-all --limit=50       # cap how many movies to process
 *   php artisan flik:ai:synopsis-all --words=200      # override word budget
 *   php artisan flik:ai:synopsis-all --movie=42       # process a single movie by id
 */
class AiGenerateAllSynopsis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flik:ai:synopsis-all
        {--force : Regenerate even if ai_synopsis already exists}
        {--sync : Run synchronously instead of dispatching to queue}
        {--limit=0 : Maximum movies to process (0 = no limit)}
        {--words=150 : Target word count for each synopsis}
        {--movie= : Restrict to a single movie id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate AI-polished Indonesian synopses for movies (bulk).';

    /**
     * Execute the console command.
     */
    public function handle(SynopsisGenerator $generator): int
    {
        if (!Schema::hasColumn('movies', 'ai_synopsis')) {
            $this->error('Column movies.ai_synopsis does not exist. Run: php artisan migrate');
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $limit = (int) $this->option('limit');
        $words = max(40, min((int) $this->option('words'), 400));
        $movieId = $this->option('movie');

        $query = Movie::query()->orderBy('id');

        if ($movieId !== null && $movieId !== '') {
            $query->where('id', (int) $movieId);
        } elseif (!$force) {
            $query->where(function ($q) {
                $q->whereNull('ai_synopsis')->orWhere('ai_synopsis', '');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No movies need a synopsis. Use --force to regenerate, or --movie=ID to target one.');
            return self::SUCCESS;
        }

        $mode = $sync ? 'inline (sync)' : "queue 'ai-realtime'";
        $this->info("Processing {$total} movie(s) — mode: {$mode}, words: {$words}, force: " . ($force ? 'yes' : 'no'));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0;
        $failed = 0;
        $skipped = 0;

        $query->chunkById(50, function ($movies) use ($generator, $sync, $force, $words, &$ok, &$failed, &$skipped, $bar) {
            foreach ($movies as $movie) {
                try {
                    if ($sync) {
                        if (!$force && !empty($movie->ai_synopsis) && !empty($movie->ai_synopsis_generated_at)) {
                            $skipped++;
                        } else {
                            $movie->loadMissing('genres');
                            $generator->generate($movie, $words);
                            $ok++;
                        }
                    } else {
                        GenerateMovieSynopsis::dispatch($movie->id, $words, $force);
                        $ok++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Movie #{$movie->id} ({$movie->title}): " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $verb = $sync ? 'generated' : 'queued';
        $this->info("Done. {$ok} {$verb}, {$skipped} skipped, {$failed} failed.");

        if (!$sync) {
            $this->line("Run a queue worker to process: <info>php artisan queue:work --queue=ai-realtime</info>");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
