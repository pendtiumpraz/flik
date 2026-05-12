<?php

namespace App\Console\Commands;

use App\Jobs\BatchRecomputeRecommendations;
use App\Models\User;
use App\Services\Ai\Recommendations\RecommendationEngine;
use Illuminate\Console\Command;

class RecomputeRecommendations extends Command
{
    protected $signature = 'flik:recommendations:recompute
                            {--user= : Recompute only this user ID (runs synchronously)}
                            {--count=20 : Number of recommendations per user}
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Recompute personalized recommendations for active users (nightly batch).';

    public function handle(RecommendationEngine $engine): int
    {
        $count = (int) $this->option('count');
        $userOpt = $this->option('user');

        // Single-user mode (always synchronous)
        if ($userOpt) {
            $user = User::find($userOpt);
            if (!$user) {
                $this->error("User #{$userOpt} not found.");
                return self::FAILURE;
            }

            $this->info("Recomputing recommendations for {$user->name} (#{$user->id})...");
            $movies = $engine->computeFor($user, $count);
            $this->info("Done. {$movies->count()} recommendations stored.");
            foreach ($movies->take(5) as $i => $m) {
                $this->line(sprintf('  %d. %s', $i + 1, $m->title));
            }
            return self::SUCCESS;
        }

        // Batch mode
        if ($this->option('sync')) {
            $this->info('Running batch synchronously (this may take a while)...');
            (new BatchRecomputeRecommendations($count))->handle($engine);
            $this->info('Batch complete. Check logs for details.');
            return self::SUCCESS;
        }

        BatchRecomputeRecommendations::dispatch($count);
        $this->info('Batch recommendation job dispatched to queue [ai-batch].');
        $this->line('Tip: ensure a worker is running, e.g. `php artisan queue:work --queue=ai-batch`.');

        return self::SUCCESS;
    }
}
