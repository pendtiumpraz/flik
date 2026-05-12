<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\Tasks\DailyAdminReportGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Generate yesterday's admin report and email it to every super_admin user.
 *
 * Usage:
 *   php artisan flik:report:daily              # report for yesterday, send to all super admins
 *   php artisan flik:report:daily --date=2026-05-11   # report for the day before given date
 *   php artisan flik:report:daily --dry        # build report, print to console, DO NOT send
 *   php artisan flik:report:daily --to=foo@bar.com   # override recipients (comma-separated)
 */
class SendDailyAdminReport extends Command
{
    protected $signature = 'flik:report:daily
                            {--date= : Reference date (YYYY-MM-DD). Report covers the day BEFORE this date. Defaults to today.}
                            {--dry : Build the report but do not send emails (prints summary to console).}
                            {--to= : Override recipient list (comma-separated emails). If omitted, all super_admin users are notified.}';

    protected $description = 'Generate yesterday\'s admin report (with AI narrative) and email it to super admins.';

    public function __construct(
        protected DailyAdminReportGenerator $generator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reference = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $this->info("Generating FLiK daily admin report for the day before {$reference->toDateString()}…");

        $report = $this->generator->generate($reference);

        $this->line("  Date covered : {$report['human_date']}");
        $this->line("  New users    : {$report['stats']['new_users']}");
        $this->line("  New subs     : {$report['stats']['new_subscriptions']['total']}");
        $this->line("  Revenue      : {$report['stats']['total_revenue_fmt']}");
        $this->line("  Watch hours  : {$report['stats']['total_watch_hours']}");
        $this->line("  DAU / MAU    : {$report['stats']['dau_active']} / {$report['stats']['mau_active']}");
        $this->line("  Top movie    : " . ($report['stats']['top_movies'][0]['title'] ?? '-'));

        if ($report['narrative_error']) {
            $this->warn("  AI narrative failed: {$report['narrative_error']}");
        }

        if ($this->option('dry')) {
            $this->info('Dry-run mode - no emails sent.');
            $this->line('');
            $this->line('--- NARRATIVE ---');
            $this->line($report['narrative']);
            return self::SUCCESS;
        }

        $recipients = $this->resolveRecipients();

        if (empty($recipients)) {
            $this->warn('No recipients found (no super_admin users and no --to override). Skipping send.');
            return self::SUCCESS;
        }

        $subject = "FLiK Daily Admin Report - {$report['human_date']}";
        $sent = 0;

        foreach ($recipients as $email) {
            try {
                Mail::send(
                    'emails.daily-admin-report',
                    ['report' => $report],
                    function (Message $m) use ($email, $subject) {
                        $m->to($email)->subject($subject);
                    }
                );
                $sent++;
                $this->line("  → Sent to {$email}");
            } catch (\Throwable $e) {
                $this->error("  ! Failed to send to {$email}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Report emailed to {$sent}/" . count($recipients) . ' recipient(s).');
        return self::SUCCESS;
    }

    /**
     * Resolve recipients from --to override or super_admin users.
     *
     * @return array<int,string>
     */
    protected function resolveRecipients(): array
    {
        if ($override = $this->option('to')) {
            return array_values(array_filter(array_map('trim', explode(',', (string) $override))));
        }

        return User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();
    }
}
