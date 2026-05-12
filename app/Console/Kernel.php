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
