<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Daily AI batch (Jakarta time — set APP_TIMEZONE=Asia/Jakarta)
        $schedule->command('flik:recommendations:recompute')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        $schedule->command('flik:churn:predict')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
        $schedule->command('flik:report:daily')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

        // Weekly batch (Monday 6 AM)
        $schedule->command('flik:ai:weekly-digest')->weeklyOn(1, '06:00')->withoutOverlapping()->onOneServer();

        // Geo DB update (weekly Sunday 4 AM)
        $schedule->command('flik:geo:update')->weeklyOn(0, '04:00')->withoutOverlapping()->onOneServer();

        // AI catalog enrichment (weekly Saturday — only fills missing by default)
        $schedule->command('flik:ai:tag-all')->weeklyOn(6, '01:00')->withoutOverlapping()->onOneServer();
        $schedule->command('flik:cast:enrich-bios --limit=20')->weeklyOn(6, '02:00')->withoutOverlapping()->onOneServer();

        // Detect intro/outro markers for movies missing them (weekly Saturday)
        $schedule->command('flik:detect:intro-outro --queue --limit=50')->weeklyOn(6, '03:00')->withoutOverlapping()->onOneServer();

        // Security daily digest — 08:00 Asia/Jakarta. Sends to super_admins
        // even when SECURITY_ALERTS_ENABLED is false (digest is the
        // always-on summary; the realtime alerter is opt-in/severity-gated).
        $schedule->command('flik:security:daily-digest')
            ->dailyAt('08:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->onOneServer();

        // GDPR — sweep stale data exports nightly. The signed download URL
        // expires after 24h, so anything still on disk after 7d represents
        // un-collected PII and is itself a minimisation violation.
        $schedule->command('flik:privacy:cleanup-exports --days=7')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Encrypted DB + media backup (daily 01:00 Jakarta) — pipeline:
        // dump → archive → encrypt → upload → prune. See
        // docs/security/backup-restore.md for setup, restore, and DR targets.
        $schedule->command('flik:backup')
            ->dailyAt('01:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->onOneServer();

        // ━━━ Trending engine (flik:trending:recompute) ━━━
        // Per-window cadence: shorter windows recompute more often so
        // "what's hot right now" updates in near-real-time, longer
        // windows churn less to keep DB pressure down. Each runs
        // independently (separate processes won't overlap thanks to
        // withoutOverlapping()), and onOneServer() prevents two boxes
        // from racing on the same window in HA deployments.
        $schedule->command('flik:trending:recompute --window=1h')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('flik:trending:recompute --window=24h')
            ->hourlyAt(5)
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('flik:trending:recompute --window=7d')
            ->everySixHours()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('flik:trending:recompute --window=30d')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer();

        // ━━━ Editorial Blog — scheduled-post publisher ━━━
        // Every 5 min: flip any blog_posts row with status='scheduled' and
        // scheduled_for <= now() to 'published'. Cheap (indexed query +
        // small chunk update) so the high cadence is fine.
        $schedule->command('flik:blog:publish-scheduled')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // ━━━ Gamification ━━━
        // Monthly streak-freeze credit grant (1st of month, 04:00 Jakarta).
        // Awards 1 freeze credit per active subscriber so loyal subs can
        // survive a busy day without losing their streak. See
        // App\Console\Commands\GrantStreakFreezeCredits.
        $schedule->command('flik:streak:grant-freeze-credits')
            ->monthlyOn(1, '04:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
