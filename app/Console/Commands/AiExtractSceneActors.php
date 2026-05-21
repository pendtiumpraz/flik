<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\MovieSceneActor;
use App\Services\Ai\Tasks\SceneActorExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate X-Ray hotspot data (movie_scene_actors) for movies that have a
 * cast but no hotspot rows yet. Solves audit-06 finding F-1 by giving the
 * weekly cron job a writer to call.
 *
 * Usage:
 *   php artisan flik:ai:scene-actors --all                # every eligible movie
 *   php artisan flik:ai:scene-actors --movie=42           # specific movie by id
 *   php artisan flik:ai:scene-actors --movie=inception    # specific movie by slug
 *   php artisan flik:ai:scene-actors --all --limit=100    # cap batch size
 *
 * Scheduled weekly in Console/Kernel.php so new uploads pick up hotspots
 * within 7 days without operator intervention.
 */
class AiExtractSceneActors extends Command
{
    protected $signature = 'flik:ai:scene-actors
        {--movie= : Specific movie ID or slug}
        {--all : Process every movie that has cast but no scene-actor rows}
        {--limit=0 : Max number of movies to process when --all is set (0 = no cap)}
        {--force : Overwrite existing scene-actor rows for the targeted movie(s)}';

    protected $description = 'Generate X-Ray scene-actor hotspots from cast pivot data (heuristic + optional AI refinement).';

    public function handle(SceneActorExtractor $extractor): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        $movies = $this->resolveMovies($limit, $force);

        if ($movies->isEmpty()) {
            $this->warn('No movies matched the given criteria. (Use --all to process eligible movies, or --movie=<id|slug>.)');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Generating X-Ray scene-actor data for %d movie(s)%s...',
            $movies->count(),
            $force ? ' [force overwrite]' : ''
        ));

        $bar = $this->output->createProgressBar($movies->count());
        $bar->start();

        $ok = 0;
        $skipped = 0;
        $totalRows = 0;
        $failures = [];

        foreach ($movies as $movie) {
            try {
                $rows = $extractor->extract($movie);
                if ($rows->isEmpty()) {
                    $skipped++;
                } else {
                    $ok++;
                    $totalRows += $rows->count();
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('#%d %s: %s', $movie->id, $movie->title, $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. populated=%d skipped=%d failed=%d total_rows_inserted=%d',
            $ok,
            $skipped,
            count($failures),
            $totalRows,
        ));

        if ($failures !== []) {
            $this->warn('Failures:');
            foreach (array_slice($failures, 0, 20) as $line) {
                $this->line('  - ' . $line);
            }
            if (count($failures) > 20) {
                $this->line(sprintf('  ... and %d more', count($failures) - 20));
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve which movies to process based on CLI flags.
     *
     * @return \Illuminate\Support\Collection<int, Movie>
     */
    protected function resolveMovies(int $limit, bool $force): \Illuminate\Support\Collection
    {
        // Single movie path — accept either numeric ID or slug, so admins
        // can paste the URL fragment directly.
        if ($key = $this->option('movie')) {
            $movie = ctype_digit((string) $key)
                ? Movie::find((int) $key)
                : Movie::where('slug', (string) $key)->first();

            if ($movie === null) {
                return collect();
            }

            // Per-movie path always processes (single-shot ergonomics);
            // the extractor itself handles the delete-then-insert.
            return collect([$movie]);
        }

        if (!$this->option('all')) {
            return collect();
        }

        // Bulk path. Default behaviour: only movies that (a) have at least
        // one cast pivot row AND (b) have no scene-actor row yet. --force
        // bypasses (b) so we regenerate everywhere.
        $query = Movie::query()
            ->whereHas('castMembers')
            ->orderByDesc('popularity');

        if (!$force) {
            $existingIds = MovieSceneActor::query()->distinct()->pluck('movie_id')->all();
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
