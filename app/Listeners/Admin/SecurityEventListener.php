<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Events\SecurityEventLogged;
use App\Services\Security\SecurityAlertService;
use App\Support\SecurityEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SecurityEventListener
 * --------------------------------------------------------------------------
 * Bridges the existing SecurityEventLogged event (already dispatched by
 * AuditLogger::security) onto the admin bell. Sits alongside the existing
 * PushSecurityAlerts listener — that one pushes Slack/Discord webhooks,
 * this one populates the in-app admin notification feed so on-call
 * admins see security events without leaving the admin panel.
 *
 * Severity / audience mapping mirrors the project's SecurityEvents::severity
 * taxonomy (and falls back to SecurityAlertService::severity when peer SEC
 * #25 ships a different mapping). The taxonomy:
 *
 *   critical → super_admin_only
 *     PRIVILEGE_ESCALATION_ATTEMPT, SUSPICIOUS_GEO_VELOCITY, DRM_KEY_DENIED
 *   warning  → [admin, super_admin]
 *     LOGIN_LOCKED_OUT, WAF_IP_BANNED, SSRF_BLOCKED
 *   info     → admin
 *     HONEYPOT_HIT, RATE_LIMIT_HIT, NEW_COUNTRY_LOGIN
 *
 * Low-severity events are throttled — same (event,user/IP) tuple firing
 * ≥5 times in 5 minutes drops further notifications until the window
 * resets. Critical events ALWAYS emit (security never gets throttled).
 */
class SecurityEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 2;

    public string $queue = 'default';

    /** Throttle: max emits per same (event,actor) tuple per window. */
    private const THROTTLE_LIMIT = 5;

    /** Throttle window in seconds (5 minutes). */
    private const THROTTLE_WINDOW_SECONDS = 300;

    public function handle(SecurityEventLogged $event): void
    {
        try {
            $eventName = $event->event;
            $context = $event->context;

            $severity = $this->severityFor($eventName);
            $audience = $this->audienceFor($eventName, $severity);

            // Throttle low + info events to avoid bell spam during noisy
            // bursts (rate-limit retries, honeypot scans). Critical and
            // high-severity events bypass the throttle.
            if ($severity === 'info' && $this->isThrottled($eventName, $context)) {
                return;
            }

            $userId = $context['user_id'] ?? null;
            $ip = $context['ip'] ?? '—';

            $title = $this->titleFor($eventName, $userId, $ip);
            $message = $this->messageFor($eventName, $context);

            $this->notify(
                category: 'security.' . $this->shortName($eventName),
                title: $title,
                message: $message,
                meta: [
                    'event' => $eventName,
                    'user_id' => $userId,
                    'ip' => $ip,
                    'audit_log_id' => $context['audit_log_id'] ?? $event->auditLog->id ?? null,
                    'meta' => $context['meta'] ?? null,
                ],
                severity: $severity,
                audience: $audience,
                actionUrl: $this->safeRoute('admin.audit-logs.index'),
            );
        } catch (Throwable $e) {
            $this->swallow($e, ['event' => $event->event ?? null]);
        }
    }

    /**
     * Map an event name → notification severity. Prefer the canonical
     * SecurityEvents::severity() taxonomy (high → warning, medium → info)
     * because that's the contract maintained alongside the audit logs UI.
     * SecurityAlertService::severity() exists too but uses a different
     * naming scheme (peer SEC #25); we only consult it as a fallback.
     *
     * Output is one of: 'info' | 'warning' | 'critical' per the
     * AdminNotifier contract.
     */
    private function severityFor(string $event): string
    {
        // Explicit per-event mapping for the events the task spec calls out.
        $criticalCanon = [
            SecurityEvents::PRIVILEGE_ESCALATION_ATTEMPT,
            SecurityEvents::SUSPICIOUS_GEO_VELOCITY,
            SecurityEvents::DRM_KEY_DENIED,
        ];
        if (in_array($event, $criticalCanon, true)) {
            return 'critical';
        }

        $warningCanon = [
            SecurityEvents::LOGIN_LOCKED_OUT,
            SecurityEvents::WAF_IP_BANNED,
            SecurityEvents::SSRF_BLOCKED,
        ];
        if (in_array($event, $warningCanon, true)) {
            return 'warning';
        }

        $infoCanon = [
            SecurityEvents::HONEYPOT_HIT,
            SecurityEvents::RATE_LIMIT_HIT,
            SecurityEvents::NEW_COUNTRY_LOGIN,
        ];
        if (in_array($event, $infoCanon, true)) {
            return 'info';
        }

        // Fall back to the taxonomy buckets and remap onto the
        // admin-notifier 3-tier scale (info|warning|critical).
        $bucket = SecurityEvents::severity($event);

        // Optionally consult SecurityAlertService for events we don't know
        // about — it has its own (more conservative) mapping. We swallow
        // any class/method failure to keep the listener robust.
        try {
            if (class_exists(SecurityAlertService::class)) {
                $svcBucket = app(SecurityAlertService::class)->severity($event);
                if ($svcBucket === 'critical') {
                    return 'critical';
                }
                if ($svcBucket === 'high' && $bucket !== 'critical') {
                    $bucket = 'high';
                }
            }
        } catch (Throwable) {
            // ignore — bucket from SecurityEvents is fine.
        }

        return match ($bucket) {
            'critical' => 'critical',
            'high' => 'warning',
            'medium', 'low' => 'info',
            default => 'info',
        };
    }

    /**
     * Pick the audience based on the event + severity per the task spec.
     * Returns a value compatible with the AdminNotifier contract
     * ('all_admins' | role name | array | 'super_admin_only').
     */
    private function audienceFor(string $event, string $severity): string|array
    {
        // Explicit mapping for the spec-called-out events.
        $criticalCanon = [
            SecurityEvents::PRIVILEGE_ESCALATION_ATTEMPT,
            SecurityEvents::SUSPICIOUS_GEO_VELOCITY,
            SecurityEvents::DRM_KEY_DENIED,
        ];
        if (in_array($event, $criticalCanon, true)) {
            return 'super_admin_only';
        }

        $warningCanon = [
            SecurityEvents::LOGIN_LOCKED_OUT,
            SecurityEvents::WAF_IP_BANNED,
            SecurityEvents::SSRF_BLOCKED,
        ];
        if (in_array($event, $warningCanon, true)) {
            return ['admin', 'super_admin'];
        }

        $infoCanon = [
            SecurityEvents::HONEYPOT_HIT,
            SecurityEvents::RATE_LIMIT_HIT,
            SecurityEvents::NEW_COUNTRY_LOGIN,
        ];
        if (in_array($event, $infoCanon, true)) {
            return 'admin';
        }

        // Otherwise scale audience with severity.
        return match ($severity) {
            'critical' => 'super_admin_only',
            'warning' => ['admin', 'super_admin'],
            default => 'admin',
        };
    }

    /**
     * Throttle: increment a per-(event,actor) counter. Returns true if the
     * counter has already exceeded THROTTLE_LIMIT inside the window.
     * Critical/warning events bypass this — only called for 'info'.
     */
    private function isThrottled(string $event, array $context): bool
    {
        $actor = $context['user_id'] ?? $context['ip'] ?? 'anon';
        $key = "admin_notif:sec_throttle:{$event}:{$actor}";

        try {
            // Atomic increment with auto-expire on first hit.
            $count = Cache::increment($key);

            if ($count === false) {
                Cache::put($key, 1, self::THROTTLE_WINDOW_SECONDS);
                return false;
            }

            // First call after store creates the key with no TTL on some
            // drivers — set the expiry on the first increment.
            if ($count === 1) {
                Cache::put($key, 1, self::THROTTLE_WINDOW_SECONDS);
            }

            return $count > self::THROTTLE_LIMIT;
        } catch (Throwable $e) {
            // Cache trouble must not silence security notifs — fail open.
            Log::warning('SecurityEventListener throttle cache failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** Compact title for the bell — e.g. "Security: login locked out (user #42)". */
    private function titleFor(string $event, ?int $userId, mixed $ip): string
    {
        $short = $this->shortName($event);
        if ($userId !== null) {
            return "Security: {$short} (user #{$userId})";
        }
        return "Security: {$short} ({$ip})";
    }

    /** Human-readable body. Pulls reason/extra from meta when present. */
    private function messageFor(string $event, array $context): string
    {
        $reason = $context['meta']['reason']
            ?? $context['meta']['message']
            ?? $context['meta']['error']
            ?? null;

        if (is_string($reason) && $reason !== '') {
            return mb_substr($reason, 0, 200);
        }

        return "Security event {$event} detected.";
    }

    /** "auth.login.locked_out" → "login locked out". */
    private function shortName(string $event): string
    {
        $parts = explode('.', $event);
        $tail = end($parts) ?: $event;
        return str_replace('_', ' ', $tail);
    }

    private function notify(
        string $category,
        string $title,
        string $message,
        array $meta,
        string $severity,
        string|array $audience,
        ?string $actionUrl,
    ): void {
        $class = 'App\\Services\\Notifications\\AdminNotifier';

        if (!app()->bound($class) && !class_exists($class)) {
            Log::warning('AdminNotifier binding missing — admin notif dropped', [
                'category' => $category,
                'title' => $title,
            ]);
            return;
        }

        app($class)->notify(
            category: $category,
            title: $title,
            message: $message,
            meta: $meta,
            severity: $severity,
            audience: $audience,
            actionUrl: $actionUrl,
        );
    }

    private function safeRoute(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            return null;
        }
    }

    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('SecurityEventListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
