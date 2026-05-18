<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * flik:admin:test-notif
 * --------------------------------------------------------------------------
 * Fires a synthetic admin bell notification for QA. Useful for verifying
 * the AdminNotifier wiring + bell UI without needing a real upstream event
 * (e.g. don't have to make a fake payment to test the finance audience).
 *
 * Examples:
 *
 *   php artisan flik:admin:test-notif comment.new
 *   php artisan flik:admin:test-notif payment.failed --severity=warning --audience=finance
 *   php artisan flik:admin:test-notif security.honeypot_hit --severity=critical \
 *       --audience=super_admin_only
 *
 * Audience accepts:
 *   - a single role name ("admin", "moderator", "finance", "content_editor", …),
 *   - 'all_admins' to broadcast to every admin,
 *   - 'super_admin_only' to restrict to super-admins,
 *   - a comma-separated list ("admin,super_admin") to target multiple roles.
 */
class TestAdminNotif extends Command
{
    /** @var string */
    protected $signature = 'flik:admin:test-notif
                            {category : Notification category, e.g. "comment.new"}
                            {--audience=all_admins : Role name, "all_admins", "super_admin_only", or comma-separated list}
                            {--severity=info : "info" | "warning" | "critical"}
                            {--title= : Optional override title}
                            {--message= : Optional override message body}
                            {--action-url= : Optional URL the bell click-through should open}';

    /** @var string */
    protected $description = 'Fire a synthetic admin bell notification for QA / UI verification.';

    public function handle(): int
    {
        $class = 'App\\Services\\Notifications\\AdminNotifier';

        if (!app()->bound($class) && !class_exists($class)) {
            $this->error('AdminNotifier service is not yet available (peer NOTIF #1).');
            $this->line('  Expected class: ' . $class);
            return self::FAILURE;
        }

        $category = (string) $this->argument('category');
        $severity = $this->normaliseSeverity((string) $this->option('severity'));
        $audience = $this->parseAudience((string) $this->option('audience'));

        $title = (string) ($this->option('title')
            ?: "[TEST] Synthetic notification for {$category}");
        $message = (string) ($this->option('message')
            ?: "This is a test notification produced by flik:admin:test-notif. Timestamp: " . now()->toIso8601String());

        $actionUrl = $this->option('action-url') ?: null;

        $this->info("Dispatching test notification:");
        $this->line("  category : {$category}");
        $this->line('  severity : ' . $severity);
        $this->line('  audience : ' . (is_array($audience) ? implode(',', $audience) : (string) $audience));
        if ($actionUrl) {
            $this->line("  actionUrl: {$actionUrl}");
        }

        try {
            app($class)->notify(
                category: $category,
                title: $title,
                message: $message,
                meta: [
                    'source' => 'flik:admin:test-notif',
                    'fired_at' => now()->toIso8601String(),
                ],
                severity: $severity,
                audience: $audience,
                actionUrl: $actionUrl,
            );
        } catch (Throwable $e) {
            $this->error('AdminNotifier->notify() threw: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('OK — notification dispatched.');
        return self::SUCCESS;
    }

    /** Normalise severity to the AdminNotifier contract values. */
    private function normaliseSeverity(string $raw): string
    {
        return match (strtolower($raw)) {
            'critical', 'crit' => 'critical',
            'warning', 'warn' => 'warning',
            default => 'info',
        };
    }

    /**
     * Accept a single role, the magic strings, or a comma list. Returns a
     * string for single-token inputs (passes through cleanly) or an array
     * for multi-token inputs.
     */
    private function parseAudience(string $raw): string|array
    {
        $trim = trim($raw);
        if ($trim === '') {
            return 'all_admins';
        }

        if (str_contains($trim, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $trim))));
        }

        return $trim;
    }
}
