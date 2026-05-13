<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SecurityDailyDigest
 * --------------------------------------------------------------------------
 * Aggregates the last 24h of security-flavoured audit log rows into a
 * single digest message. Sent every day at 08:00 Asia/Jakarta (wired in
 * App\Console\Kernel::schedule).
 *
 * Behaviour vs the real-time alerter:
 *   - The realtime SecurityAlertService respects SECURITY_ALERTS_ENABLED
 *     and severity floors. The digest IGNORES those gates so super_admins
 *     always receive a daily security pulse — even on days when nothing
 *     hit the high/critical bar.
 *   - When Slack/Discord webhooks ARE configured we also POST the digest
 *     there so the on-call channel sees it.
 *   - If the audit_logs table has nothing in the last 24h we still send
 *     a "no events" heartbeat so silence is visible (a missing message
 *     means the cron is broken).
 *
 * Usage:
 *   php artisan flik:security:daily-digest
 *   php artisan flik:security:daily-digest --hours=24
 *   php artisan flik:security:daily-digest --dry          # print only
 *   php artisan flik:security:daily-digest --to=foo@bar.com
 */
class SecurityDailyDigest extends Command
{
    protected $signature = 'flik:security:daily-digest
                            {--hours=24 : Lookback window in hours.}
                            {--dry : Print the digest to console; do not send.}
                            {--to= : Override email recipients (comma-separated).}';

    protected $description = 'Aggregate the last 24h of security audit events and email super_admins (always) plus webhook channels (when configured).';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = Carbon::now()->subHours($hours);

        $rows = AuditLog::query()
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                // Heuristic: anything published via AuditLogger::security()
                // uses the `security.*` action namespace. We also pick up
                // the legacy `auth.*` namespace so login/lockout activity
                // shows up in the digest even if it predates the rename.
                $q->where('action', 'like', 'security.%')
                  ->orWhere('action', 'like', 'auth.%');
            })
            ->orderBy('created_at')
            ->get(['id', 'action', 'user_id', 'client_ip', 'created_at', 'meta']);

        $totalCount = $rows->count();
        $byAction = $rows->groupBy('action')
            ->map(fn ($grp) => $grp->count())
            ->sortDesc();
        $uniqueUsers = $rows->whereNotNull('user_id')->pluck('user_id')->unique()->count();
        $uniqueIps = $rows->whereNotNull('client_ip')->pluck('client_ip')->unique()->count();

        $digest = [
            'window_hours' => $hours,
            'window_from' => $since->toIso8601String(),
            'window_to' => Carbon::now()->toIso8601String(),
            'total' => $totalCount,
            'unique_users' => $uniqueUsers,
            'unique_ips' => $uniqueIps,
            'by_action' => $byAction->all(),
            'top_offending_ips' => $this->topOffendingIps($rows),
        ];

        $textReport = $this->renderText($digest);

        $this->line($textReport);

        if ($this->option('dry')) {
            $this->info('Dry-run mode — nothing sent.');
            return self::SUCCESS;
        }

        $sentEmails = $this->sendEmails($textReport, $digest);
        $this->info("Emailed digest to {$sentEmails} recipient(s).");

        // Webhook copy — only when configured. Best-effort, non-blocking.
        $this->sendWebhookCopy($textReport, $digest);

        return self::SUCCESS;
    }

    /**
     * Build a compact list of the noisiest source IPs in the window.
     *
     * @return array<int,array{ip:string,count:int}>
     */
    private function topOffendingIps(\Illuminate\Support\Collection $rows): array
    {
        return $rows->whereNotNull('client_ip')
            ->groupBy('client_ip')
            ->map(fn ($grp, $ip) => ['ip' => (string) $ip, 'count' => $grp->count()])
            ->sortByDesc('count')
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $digest
     */
    private function renderText(array $digest): string
    {
        $lines = [];
        $lines[] = '=== FLiK Security Daily Digest ===';
        $lines[] = "Window: last {$digest['window_hours']}h ({$digest['window_from']} → {$digest['window_to']})";
        $lines[] = "Total events: {$digest['total']}";
        $lines[] = "Unique users: {$digest['unique_users']}";
        $lines[] = "Unique IPs:   {$digest['unique_ips']}";
        $lines[] = '';

        if ($digest['total'] === 0) {
            $lines[] = 'No security events recorded. (Heartbeat — cron is alive.)';
            return implode("\n", $lines);
        }

        $lines[] = '— Events by action —';
        foreach ($digest['by_action'] as $action => $count) {
            $lines[] = sprintf('  %-44s %d', (string) $action, (int) $count);
        }

        if (! empty($digest['top_offending_ips'])) {
            $lines[] = '';
            $lines[] = '— Top source IPs —';
            foreach ($digest['top_offending_ips'] as $row) {
                $lines[] = sprintf('  %-20s %d hits', $row['ip'], $row['count']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $digest
     */
    private function sendEmails(string $textReport, array $digest): int
    {
        $recipients = $this->resolveRecipients();
        if (empty($recipients)) {
            $this->warn('No recipients (no super_admins and no --to override).');
            return 0;
        }

        $subject = sprintf(
            'FLiK Security Digest — %s (%d events)',
            Carbon::now()->toDateString(),
            (int) $digest['total'],
        );

        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::raw($textReport, function (Message $m) use ($email, $subject): void {
                    $m->to($email)->subject($subject);
                });
                $sent++;
            } catch (Throwable $e) {
                $this->error("  ! Failed to send to {$email}: {$e->getMessage()}");
            }
        }
        return $sent;
    }

    /**
     * Mirror the digest to the configured Slack/Discord webhook so the
     * on-call channel sees a fresh message every morning. Failures are
     * swallowed — the email is the source of truth.
     *
     * @param  array<string,mixed>  $digest
     */
    private function sendWebhookCopy(string $textReport, array $digest): void
    {
        $slack = (string) config('services.security_alerts.slack_webhook', '');
        $discord = (string) config('services.security_alerts.discord_webhook', '');

        if ($slack === '' && $discord === '') {
            return;
        }

        // Use a 5-min cache to dedupe in case the cron fires twice (e.g.
        // failover between two schedulers running --on-one-server).
        $dedupeKey = 'security_digest:' . Carbon::now()->format('Y-m-d-H');
        try {
            if (! Cache::add($dedupeKey, 1, 300)) {
                $this->line('Webhook copy skipped (already sent in this hour).');
                return;
            }
        } catch (Throwable) {
            // Cache outage — proceed regardless.
        }

        if ($slack !== '' && $this->urlLooksSafe($slack, ['hooks.slack.com'])) {
            try {
                Http::timeout(5)->withoutRedirecting()->asJson()->post($slack, [
                    'text' => "*FLiK Security Digest* — {$digest['total']} events in last {$digest['window_hours']}h",
                    'blocks' => [
                        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "```\n{$textReport}\n```"]],
                    ],
                ]);
            } catch (Throwable $e) {
                Log::warning('SecurityDailyDigest: Slack webhook failed', ['error' => $e->getMessage()]);
            }
        }

        if ($discord !== '' && $this->urlLooksSafe($discord, ['discord.com', 'discordapp.com'])) {
            try {
                // Discord content cap is 2000 chars; keep the full report
                // in a code block and trim if necessary.
                $body = mb_strlen($textReport) > 1800 ? mb_substr($textReport, 0, 1800) . "\n…" : $textReport;
                Http::timeout(5)->withoutRedirecting()->asJson()->post($discord, [
                    'username' => 'FLiK Security',
                    'content' => "**FLiK Security Digest** — {$digest['total']} events / {$digest['window_hours']}h\n```\n{$body}\n```",
                ]);
            } catch (Throwable $e) {
                Log::warning('SecurityDailyDigest: Discord webhook failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  array<int,string>  $allowedHosts
     */
    private function urlLooksSafe(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ($allowedHosts as $needle) {
            if ($host === $needle || str_ends_with($host, '.' . $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int,string>
     */
    private function resolveRecipients(): array
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
