<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\Movie;
use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only helper: spin up 2 seasons × 6 episodes against an existing
 * movie so the front-end episode picker has something to render.
 *
 * Usage:
 *   php artisan flik:dev:seed-test-series --movie=42
 *
 * The command bails out hard in production (APP_ENV=production) so a
 * scheduler accident can't pollute the live catalog.
 */
class SeedTestSeries extends Command
{
    protected $signature = 'flik:dev:seed-test-series
        {--movie= : Movie ID to attach test seasons + episodes to (required)}
        {--seasons=2 : Number of seasons to create}
        {--episodes=6 : Episodes per season}
        {--force : Allow re-seeding even if the movie already has seasons}';

    protected $description = 'Dev helper — seeds 2 seasons × 6 episodes onto a movie for series UI testing. Refuses to run in production.';

    public function handle(): int
    {
        if (App::environment('production')) {
            $this->error('flik:dev:seed-test-series is disabled in production.');
            return self::FAILURE;
        }

        $movieId = (int) $this->option('movie');
        if ($movieId <= 0) {
            $this->error('Pass --movie=<id> with an existing movie row.');
            return self::FAILURE;
        }

        $movie = Movie::find($movieId);
        if (! $movie) {
            $this->error("Movie #{$movieId} not found.");
            return self::FAILURE;
        }

        $seasonCount  = max(1, (int) $this->option('seasons'));
        $episodeCount = max(1, (int) $this->option('episodes'));
        $force        = (bool) $this->option('force');

        if (! $force && $movie->seasons()->exists()) {
            $this->warn("Movie #{$movieId} already has seasons. Re-run with --force to add more.");
            return self::FAILURE;
        }

        $this->info("Seeding {$seasonCount} season(s) × {$episodeCount} episode(s) onto: {$movie->title}");

        DB::transaction(function () use ($movie, $seasonCount, $episodeCount) {
            // Flip to series mode up-front so the rest of the app sees
            // the change inside the same transaction.
            $movie->forceFill(['content_type' => 'series'])->save();

            // Start numbering past the existing max so reruns with --force
            // don't crash on the (movie_id, season_number) unique index.
            $existingMaxSeason = (int) $movie->seasons()->max('season_number');

            $createdEpisodes = 0;

            for ($s = 1; $s <= $seasonCount; $s++) {
                $seasonNumber = $existingMaxSeason + $s;

                /** @var Season $season */
                $season = $movie->seasons()->create([
                    'season_number' => $seasonNumber,
                    'title'         => "Test Season {$seasonNumber}",
                    'overview'      => "Auto-seeded season for UI testing — Season {$seasonNumber}.",
                    'air_date'      => now()->subMonths(6 - $s)->toDateString(),
                ]);

                for ($e = 1; $e <= $episodeCount; $e++) {
                    Episode::create([
                        'season_id'        => $season->id,
                        'movie_id'         => $movie->id,
                        'episode_number'   => $e,
                        'title'            => "Test Episode {$e} (S{$seasonNumber})",
                        'overview'         => "Placeholder overview for Season {$seasonNumber}, Episode {$e}.",
                        'runtime_minutes'  => rand(22, 48),
                        'air_date'         => now()->subMonths(6 - $s)->addWeeks($e - 1)->toDateString(),
                    ]);
                    $createdEpisodes++;
                }

                $season->forceFill(['episode_count' => $episodeCount])->save();
            }

            $movie->forceFill([
                'total_seasons'  => $movie->seasons()->count(),
                'total_episodes' => $movie->episodes()->count(),
            ])->save();

            $this->info("Done. Created {$seasonCount} season(s) + {$createdEpisodes} episode(s).");
        });

        $this->line("Visit: " . url('/movie/' . $movie->slug));
        return self::SUCCESS;
    }
}
