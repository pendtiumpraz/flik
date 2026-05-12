<?php

namespace App\Console\Commands;

use App\Models\MovieSchedule;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SendScheduleReminders
 *
 * Sweeps movie_schedules for entries scheduled to fire within the next hour
 * that haven't been reminded yet. Drops an in-app Notification and stamps
 * reminder_sent_at so we don't double-send.
 *
 * Designed to be safe to run every 5 minutes from the kernel scheduler —
 * the unique-per-row reminder_sent_at gate makes duplicate work a no-op.
 *
 * Usage:
 *   php artisan flik:schedule:remind
 *   php artisan flik:schedule:remind --dry      # log + count only, do not write
 */
class SendScheduleReminders extends Command
{
    protected $signature = 'flik:schedule:remind
                            {--dry : Build the reminder list but do not write notifications or stamp reminder_sent_at.}';

    protected $description = 'Notify users of FLiK schedules firing within the next hour (Save for Friday Night).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $due = MovieSchedule::with(['user', 'movie'])
            ->whereNull('reminder_sent_at')
            ->whereNull('watched_at')
            ->where('scheduled_for', '>', now()->subMinutes(5))
            ->where('scheduled_for', '<=', now()->addHour())
            ->orderBy('scheduled_for')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No schedules due for reminding.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d schedule reminder(s) due%s.',
            $due->count(),
            $dry ? ' (DRY RUN)' : '',
        ));

        $sent    = 0;
        $skipped = 0;

        foreach ($due as $schedule) {
            $user  = $schedule->user;
            $movie = $schedule->movie;

            if (!$user || !$movie) {
                $skipped++;
                $this->warn("  ! Skipping schedule #{$schedule->id} (user or movie missing).");
                continue;
            }

            $when = $schedule->scheduled_for->translatedFormat('H:i');
            $diff = $schedule->scheduled_for->diffForHumans(now(), ['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]);

            $title   = "🎬 Jadwal Nonton {$diff} Lagi";
            $message = "\"{$movie->title}\" siap ditonton jam {$when}. Siapin popcorn, ya!";

            $this->line("  → user={$user->id} | movie=\"{$movie->title}\" | at={$when}");

            if ($dry) {
                continue;
            }

            try {
                Notification::create([
                    'user_id'    => $user->id,
                    'type'       => 'schedule_reminder',
                    'title'      => $title,
                    'message'    => $message,
                    'action_url' => $movie->slug ? '/movie/' . $movie->slug : '/my-schedule',
                ]);

                $schedule->update(['reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('flik:schedule:remind failed for schedule', [
                    'schedule_id' => $schedule->id,
                    'user_id'     => $user->id,
                    'error'       => $e->getMessage(),
                ]);
                $this->error("  ! Failed schedule #{$schedule->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done. sent=%d, skipped=%d%s.',
            $sent,
            $skipped,
            $dry ? ' (dry-run, no writes)' : '',
        ));

        return self::SUCCESS;
    }
}
