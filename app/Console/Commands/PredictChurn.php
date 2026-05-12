<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Tasks\ChurnPredictor;
use Illuminate\Console\Command;

/**
 * Run the ChurnPredictor against either one user or every paid-tier user.
 *
 * Manual:
 *   php artisan flik:churn:predict                # all (sub-having) users
 *   php artisan flik:churn:predict --user=42      # single user by id
 *   php artisan flik:churn:predict --user=u@x.id  # single user by email
 *
 * Schedule (in app/Console/Kernel.php → schedule()):
 *   $schedule->command('flik:churn:predict')->dailyAt('03:00');
 */
class PredictChurn extends Command
{
    /** @var string */
    protected $signature = 'flik:churn:predict
        {--user= : Optional user id or email — limit prediction to this user}';

    /** @var string */
    protected $description = 'Score churn risk for paid users (or one user with --user)';

    public function handle(AiClient $ai): int
    {
        // The ChurnPredictor is intentionally constructed without an
        // AiClient by the container (constructor arg defaults to null,
        // which puts it in heuristic-only mode). Re-bind here so the
        // command actually uses AI for high-risk suggestions.
        $predictor = new ChurnPredictor($ai);

        $userKey = $this->option('user');

        if ($userKey !== null && $userKey !== '') {
            return $this->predictSingle($predictor, (string) $userKey);
        }

        return $this->predictAll($predictor);
    }

    /**
     * Score a single user — accepts numeric id or email.
     */
    protected function predictSingle(ChurnPredictor $predictor, string $key): int
    {
        $user = ctype_digit($key)
            ? User::find((int) $key)
            : User::where('email', $key)->first();

        if (!$user) {
            $this->error("User not found: {$key}");
            return self::FAILURE;
        }

        $this->line("Scoring user #{$user->id} ({$user->email})...");

        try {
            $prediction = $predictor->predictForUser($user);
        } catch (\Throwable $e) {
            $this->error("Prediction failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info(sprintf(
            "Done — score=%.3f level=%s",
            $prediction->risk_score,
            $prediction->risk_level,
        ));

        if ($prediction->suggested_action) {
            $this->line('Suggested action: ' . $prediction->suggested_action);
        }

        return self::SUCCESS;
    }

    /**
     * Score every user that has at least one subscription record.
     */
    protected function predictAll(ChurnPredictor $predictor): int
    {
        $this->info('Predicting churn risk for all users with a subscription history...');

        $started = microtime(true);
        $count   = $predictor->predictAll();
        $elapsed = round(microtime(true) - $started, 2);

        $this->info("Predicted {$count} user(s) in {$elapsed}s.");

        return self::SUCCESS;
    }
}
