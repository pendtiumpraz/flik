<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeMovie as TranscodeMovieJob;
use App\Models\Movie;
use Illuminate\Console\Command;

/**
 * Kick off the transcoding pipeline for a single movie.
 *
 * Usage:
 *   php artisan flik:transcode 42                  # by id
 *   php artisan flik:transcode some-movie-slug     # by slug
 *   php artisan flik:transcode 42 --sync           # run inline (debug)
 *
 * The {movie} arg accepts either a numeric id or a slug — handy for ops
 * who only have the URL handy and don't want to look up the id.
 */
class TranscodeMovie extends Command
{
    protected $signature = 'flik:transcode
        {movie : Movie id or slug}
        {--sync : Run the job synchronously instead of dispatching to the queue}';

    protected $description = 'Dispatch (or run) the full transcoding pipeline for a movie.';

    public function handle(): int
    {
        $ref = (string) $this->argument('movie');

        $movie = ctype_digit($ref)
            ? Movie::find((int) $ref)
            : Movie::where('slug', $ref)->first();

        if (!$movie) {
            $this->error("Movie not found: {$ref}");
            return self::FAILURE;
        }

        if (empty($movie->master_file_path)) {
            $this->error("Movie #{$movie->id} ({$movie->slug}) has no master_file_path set.");
            $this->line('Upload a master file first, or set master_file_path/master_file_disk on the row.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Dispatching transcode for movie #%d (%s) — master: %s on disk [%s]',
            $movie->id,
            $movie->slug,
            $movie->master_file_path,
            $movie->master_file_disk ?: 'public',
        ));

        if ($this->option('sync')) {
            $this->warn('Running synchronously — this can take hours for feature-length films.');
            TranscodeMovieJob::dispatchSync($movie->id);
        } else {
            TranscodeMovieJob::dispatch($movie->id);
            $this->line('Make sure a queue worker is running:  php artisan queue:work --queue=transcoding');
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
