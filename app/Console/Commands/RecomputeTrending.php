<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Trending\TrendingAggregator;
use Illuminate\Console\Command;
use Throwable;

/**
 * flik:trending:recompute — wraps App\Services\Trending\TrendingAggregator.
 *
 * Invoked by the scheduler (App\Console\Kernel) on staggered intervals
 * per window. Each window is computed independently; if one fails the
 * others still run.
 *
 *   php artisan flik:trending:recompute            # all four windows
 *   php artisan flik:trending:recompute --window=1h
 *   php artisan flik:trending:recompute --window=24h
 */
class RecomputeTrending extends Command
{
    protected $signature = 'flik:trending:recompute
                            {--window=all : 1h | 24h | 7d | 30d | all}';

    protected $description = 'Recompute trending_movies cache for one or all windows.';

    public function handle(TrendingAggregator $aggregator): int
    {
        $arg = strtolower((string) $this->option('window'));

        $windows = $arg === 'all'
            ? array_keys(TrendingAggregator::WINDOWS)
            : [$arg];

        $unknown = array_diff($windows, array_keys(TrendingAggregator::WINDOWS));
        if ($unknown !== []) {
            $this->error('Unknown window(s): '.implode(', ', $unknown));
            $this->line('Valid: '.implode(', ', array_keys(TrendingAggregator::WINDOWS)).', all');

            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;

        foreach ($windows as $window) {
            $this->info("Recomputing trending for window [{$window}]...");
            $start = microtime(true);

            try {
                $aggregator->compute($window);
                $ms = (int) round((microtime(true) - $start) * 1000);
                $this->line("  OK ({$ms} ms)");
            } catch (Throwable $e) {
                // One window failing must not abort the rest — the
                // scheduler invokes a single `--window=all` run per
                // cycle, and a one-off blip on the 7d window
                // shouldn't take the 1h/24h shelves offline.
                $this->error('  failed: '.$e->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
