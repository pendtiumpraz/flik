<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tmdb\TmdbClient;
use Illuminate\Console\Command;

/**
 * Smoke-test the TMDB integration.
 *
 *   php artisan flik:tmdb:health
 *
 * Reports:
 *   - Which credential (api_key vs bearer) the config layer can see.
 *   - Whether the client thinks itself "enabled" (at least one cred set).
 *   - A live ping against /movie/550 (Fight Club — guaranteed stable id)
 *     so config issues vs network issues vs auth issues can be told apart.
 *
 * Exits 0 on success, non-zero if anything looks broken — so it can be
 * chained into a deploy script's healthcheck step.
 */
class TmdbHealth extends Command
{
    protected $signature = 'flik:tmdb:health
        {--id=550 : TMDB id to probe (default 550 = Fight Club).}
        {--type=movie : "movie" or "tv" — selects the probe endpoint.}';

    protected $description = 'Verify TMDB credentials + connectivity for the import wizard.';

    public function handle(TmdbClient $tmdb): int
    {
        $hasKey = ! empty(config('services.tmdb.api_key')) || ! empty(config('services.tmdb.token'));
        $hasBearer = ! empty(config('services.tmdb.bearer'));

        $this->line('────────── TMDB Health Check ──────────');
        $this->line('  api_key (TMDB_KEY) configured ........ '.($hasKey ? '<info>yes</info>' : '<comment>no</comment>'));
        $this->line('  bearer  (TMDB_BEARER) configured ..... '.($hasBearer ? '<info>yes</info>' : '<comment>no</comment>'));
        $this->line('  client->enabled() .................... '.($tmdb->enabled() ? '<info>yes</info>' : '<comment>no</comment>'));

        if (! $tmdb->enabled()) {
            $this->newLine();
            $this->error('No TMDB credential found. Set TMDB_KEY (v3 api key) or TMDB_BEARER (v4 token) in .env, then run:');
            $this->line('  php artisan config:clear');
            return self::FAILURE;
        }

        $id = (int) $this->option('id');
        $type = $this->option('type') === 'tv' ? 'tv' : 'movie';

        $this->newLine();
        $this->line("Probing /{$type}/{$id} …");

        $payload = $type === 'tv' ? $tmdb->findTv($id) : $tmdb->findMovie($id);
        if (! is_array($payload)) {
            $this->error('Probe returned nothing — see laravel.log for details (network error, 401, or 404).');
            return self::FAILURE;
        }

        $title = (string) ($payload['title'] ?? $payload['name'] ?? '?');
        $this->info("OK — TMDB returned: {$title} (id {$id})");
        return self::SUCCESS;
    }
}
