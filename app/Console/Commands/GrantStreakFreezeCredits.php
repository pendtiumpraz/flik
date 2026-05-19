<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Gamification\StreakService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Monthly cron — grant 1 streak-freeze credit to every active subscriber.
 *
 * Schedule (Kernel.php):
 *   $schedule->command('flik:streak:grant-freeze-credits')
 *       ->monthlyOn(1, '04:00')
 *       ->timezone('Asia/Jakarta')
 *       ->withoutOverlapping()
 *       ->onOneServer();
 *
 * Idempotency: we deliberately do NOT track per-month grants in a separate
 * table — the cron is the source of truth. If it gets run twice in a month
 * (manual rerun, etc.) users get an extra credit, which is a feature in
 * disguise, not a bug.
 */
class GrantStreakFreezeCredits extends Command
{
    /** @var string */
    protected $signature = 'flik:streak:grant-freeze-credits
        {--dry-run : Print users who would receive credits without writing}';

    /** @var string */
    protected $description = 'Award 1 streak-freeze credit to every active subscriber (monthly cron)';

    public function handle(StreakService $streaks): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Iterate via chunkById so we don't pull every active subscriber
        // into memory at once on a million-row install.
        $granted = 0;
        $failed = 0;

        Subscription::query()
            ->active()
            ->with('user:id,name,email')
            ->chunkById(500, function ($subs) use ($streaks, $dryRun, &$granted, &$failed): void {
                foreach ($subs as $sub) {
                    if ($sub->user === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("DRY: would grant to {$sub->user->email}");
                        $granted++;
                        continue;
                    }

                    try {
                        $streaks->grantFreezeCredit($sub->user);
                        $granted++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("Failed for user {$sub->user_id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Streak freeze credits granted: {$granted}" . ($failed > 0 ? " (failures: {$failed})" : ''));

        return self::SUCCESS;
    }
}
