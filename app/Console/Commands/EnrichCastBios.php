<?php

namespace App\Console\Commands;

use App\Models\Cast;
use App\Services\Ai\Tasks\CastBiographyEnricher;
use Illuminate\Console\Command;

/**
 * X-Ray (O14) — Bulk-enrich cast members with AI-generated biographies.
 *
 * Usage:
 *   php artisan flik:cast:enrich-bios                    # all casts missing bio
 *   php artisan flik:cast:enrich-bios --cast=42          # one cast by id
 *   php artisan flik:cast:enrich-bios --all              # iterate every cast (skips already-enriched)
 *   php artisan flik:cast:enrich-bios --force            # re-enrich EVERY cast (clears bio_generated_at first)
 *   php artisan flik:cast:enrich-bios --limit=20         # cap processed rows
 */
class EnrichCastBios extends Command
{
    protected $signature = 'flik:cast:enrich-bios
        {--cast= : Specific cast ID}
        {--all : Process every cast (still respects bio_generated_at idempotency)}
        {--force : Re-enrich every cast (clears bio_generated_at first)}
        {--limit=50 : Max number of casts to process (0 = no limit)}';

    protected $description = 'Enrich cast members with AI-generated biographies (X-Ray feature)';

    public function handle(CastBiographyEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');
        $casts = $this->resolveCasts($limit);

        // --force clears the idempotency stamp on the resolved set so the
        // enricher actually re-runs against each row (otherwise it would
        // short-circuit at bio_generated_at !== null).
        if ($this->option('force') && $casts->isNotEmpty()) {
            $this->warn('--force in effect: clearing bio_generated_at on ' . $casts->count() . ' row(s).');
            $casts->each(function (Cast $c) {
                $c->forceFill(['bio_generated_at' => null])->save();
            });
        }

        if ($casts->isEmpty()) {
            $this->warn('No cast members matched the given criteria.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Enriching %d cast member(s)...', $casts->count()));

        $bar = $this->output->createProgressBar($casts->count());
        $bar->start();

        $ok = 0;
        $skipped = 0;
        $fail = 0;

        foreach ($casts as $cast) {
            try {
                $hadBio = $cast->bio_generated_at !== null;
                $result = $enricher->enrich($cast);

                if ($hadBio) {
                    $skipped++;
                } elseif ($result->bio_generated_at !== null) {
                    $ok++;
                } else {
                    $fail++;
                    $this->warn("\n  ! No bio generated for #{$cast->id} {$cast->name}");
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->error("\n  x #{$cast->id} {$cast->name}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. ok=%d skipped=%d fail=%d total=%d',
            $ok,
            $skipped,
            $fail,
            $casts->count()
        ));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve the set of casts to process based on CLI options.
     *
     * @return \Illuminate\Support\Collection<int, Cast>
     */
    protected function resolveCasts(int $limit): \Illuminate\Support\Collection
    {
        if ($id = $this->option('cast')) {
            $cast = Cast::find((int) $id);
            return $cast ? collect([$cast]) : collect();
        }

        $query = Cast::query()->orderBy('id');

        // --force and --all both walk every row; without either flag we
        // limit to casts that have never been enriched (bio_generated_at IS NULL).
        if (! $this->option('all') && ! $this->option('force')) {
            $query->whereNull('bio_generated_at');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
