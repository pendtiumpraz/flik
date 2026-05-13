<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * SecurityAlertService
 * --------------------------------------------------------------------------
 * Real-time fan-out of critical security events to Slack and/or Discord
 * via incoming webhooks. Designed to no-op gracefully when:
 *
 *  - `services.security_alerts.enabled` is false, OR
 *  - the event severity is below `services.security_alerts.min_severity`, OR
 *  - neither slack_webhook nor discord_webhook is configured.
 *
 * Failure isolation: every webhook POST is wrapped in try/catch with a
 * tight timeout. We MUST NEVER allow a notification glitch to bubble up
 * into the request that produced the security event.
 *
 * Throttling: identical event/user pairs are deduped via a 5-minute Redis
 * cache key so a brute-force loop doesn't generate one Slack message per
 * attempt — the listener fires for every audited event, the throttle
 * collapses bursts down to one alert per 5 minutes.
 *
 * SSRF safety: outbound HTTP uses the project's SafeHttp helper if it
 * exists (peer SEC #10). Until that helper lands we fall back to a
 * locked-down `Http::timeout(...)->withoutRedirecting()` call so a
 * compromised/forged webhook URL can't 302 us into the metadata service.
 */
class SecurityAlertService
{
    /** Severity ranking — higher number = more severe. */
    private const SEVERITY_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    /** Throttle window for duplicate event/user pairs. */
    private const THROTTLE_TTL_SECONDS = 300;

    /** HTTP timeout for webhook delivery (Slack/Discord SLAs are sub-second). */
    private const HTTP_TIMEOUT_SECONDS = 4;

    /**
     * Map a SecurityEvents constant value to its severity bucket.
     *
     * The event name is matched against well-known prefixes/keywords. We
     * intentionally do NOT depend on the SecurityEvents class existing
     * yet — the peer task (#24) may land later. Until then we work with
     * raw string constants so this service is independently shippable.
     */
    public function severity(string $event): string
    {
        // Critical — confidentiality breach or active attack
        $critical = [
            'security.privilege.escalation_attempt', // PRIVILEGE_ESCALATION_ATTEMPT
            'security.account.deleted_by_admin',     // ACCOUNT_DELETED (admin-initiated)
            'security.geo.suspicious_velocity',      // SUSPICIOUS_GEO_VELOCITY
            'security.drm.key_denied_mass',          // DRM_KEY_DENIED (mass)
        ];
        if (in_array($event, $critical, true)) {
            return 'critical';
        }

        // High — strong signal of automated attack or compromise
        $high = [
            'security.auth.lockout_mass',            // LOGIN_LOCKED_OUT (mass)
            'security.ssrf.blocked',                 // SSRF_BLOCKED
            'security.upload.malware_signature',     // FILE_UPLOAD_REJECTED (malware)
            'security.csp.violation_batch',          // CSP_VIOLATION batch
        ];
        if (in_array($event, $high, true)) {
            return 'high';
        }

        // Medium — anomaly worth a human glance, not page-worthy
        $medium = [
            'security.auth.new_country_login',       // NEW_COUNTRY_LOGIN
            'security.ratelimit.sustained',          // RATE_LIMIT_HIT (sustained)
        ];
        if (in_array($event, $medium, true)) {
            return 'medium';
        }

        // Low — informational; aggregate in daily digest
        return 'low';
    }

    /**
     * Notify Slack/Discord of a security event.
     *
     * @param  string               $event   SecurityEvents::* constant value.
     * @param  array<string,mixed>  $context Structured context. Recognised keys:
     *                                       - user_id (int|null) actor that triggered
     *                                       - ip (string|null) client IP
     *                                       - audit_log_id (int|null) for deep link
     *                                       - meta (array) extra detail rendered as JSON snippet
     */
    public function notify(string $event, array $context = []): void
    {
        // 1. Master switch
        if (! (bool) config('services.security_alerts.enabled', false)) {
            return;
        }

        // 2. Severity gate
        $severity = $this->severity($event);
        $minSeverity = (string) config('services.security_alerts.min_severity', 'high');

        if (! $this->meetsSeverityFloor($severity, $minSeverity)) {
            return;
        }

        // 3. Throttle duplicates per (event, user). null user_id collapses to 'anon'
        $userKey = $context['user_id'] ?? 'anon';
        $throttleKey = "security_alert:{$event}:{$userKey}";

        // Cache::add returns false if the key already exists — atomic check-and-set.
        try {
            $isFirst = Cache::add($throttleKey, 1, self::THROTTLE_TTL_SECONDS);
            if (! $isFirst) {
                return;
            }
        } catch (Throwable $e) {
            // Cache failure must not silence alerts. Log and continue.
            Log::warning('SecurityAlertService: throttle cache unavailable', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        // 4. Build payloads
        $rendered = $this->renderMessage($event, $severity, $context);

        // 5. Fan out (each transport isolated)
        $slack = (string) config('services.security_alerts.slack_webhook', '');
        if ($slack !== '') {
            $this->dispatch($slack, $this->slackPayload($rendered, $severity), 'slack');
        }

        $discord = (string) config('services.security_alerts.discord_webhook', '');
        if ($discord !== '') {
            $this->dispatch($discord, $this->discordPayload($rendered, $severity), 'discord');
        }
    }

    /** True if the event severity is at or above the configured floor. */
    private function meetsSeverityFloor(string $eventSeverity, string $minSeverity): bool
    {
        $rank = self::SEVERITY_RANK[$eventSeverity] ?? 1;
        $floor = self::SEVERITY_RANK[$minSeverity] ?? 3;

        return $rank >= $floor;
    }

    /**
     * Build the human-facing message payload (transport-agnostic).
     *
     * @param  array<string,mixed> $context
     * @return array{title:string,body:string,fields:array<int,array{name:string,value:string}>,link:string|null,color:int}
     */
    private function renderMessage(string $event, string $severity, array $context): array
    {
        $badge = match ($severity) {
            'critical' => '[CRITICAL]',
            'high' => '[HIGH]',
            'medium' => '[MEDIUM]',
            default => '[LOW]',
        };

        $color = match ($severity) {
            'critical' => 0xB91C1C, // red-700
            'high' => 0xEA580C,     // orange-600
            'medium' => 0xCA8A04,   // yellow-600
            default => 0x6B7280,    // gray-500
        };

        $userId = $context['user_id'] ?? null;
        $userLabel = 'anonymous';
        if ($userId !== null) {
            try {
                $user = User::find($userId);
                if ($user) {
                    $userLabel = "{$user->name} <{$user->email}> (#{$user->id})";
                } else {
                    $userLabel = "user #{$userId} (deleted)";
                }
            } catch (Throwable) {
                $userLabel = "user #{$userId}";
            }
        }

        $ip = (string) ($context['ip'] ?? '—');
        $ts = now()->toIso8601String();

        $metaSummary = '';
        if (! empty($context['meta']) && is_array($context['meta'])) {
            $encoded = json_encode($context['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                // Cap to keep Slack/Discord happy; full payload lives in audit log.
                $metaSummary = mb_strlen($encoded) > 800
                    ? mb_substr($encoded, 0, 800) . '…'
                    : $encoded;
            }
        }

        $link = null;
        if (! empty($context['audit_log_id'])) {
            try {
                // Deep-link to the index page (admin can search by ID); we
                // don't assume a `show` route exists.
                $link = URL::to('/admin/audit-logs?id=' . (int) $context['audit_log_id']);
            } catch (Throwable) {
                $link = null;
            }
        }

        $fields = [
            ['name' => 'Event', 'value' => $event],
            ['name' => 'User', 'value' => $userLabel],
            ['name' => 'IP', 'value' => $ip],
            ['name' => 'Timestamp', 'value' => $ts],
        ];

        if ($metaSummary !== '') {
            $fields[] = ['name' => 'Meta', 'value' => "```{$metaSummary}```"];
        }

        $title = "{$badge} {$event}";
        $body = "Security event {$event} for {$userLabel} from {$ip} at {$ts}.";

        return [
            'title' => $title,
            'body' => $body,
            'fields' => $fields,
            'link' => $link,
            'color' => $color,
        ];
    }

    /**
     * Slack incoming-webhook payload (Block Kit).
     *
     * @param  array<string,mixed>  $rendered
     * @return array<string,mixed>
     */
    private function slackPayload(array $rendered, string $severity): array
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $rendered['title'], 'emoji' => false],
            ],
            [
                'type' => 'section',
                'fields' => array_map(
                    fn (array $f) => [
                        'type' => 'mrkdwn',
                        'text' => "*{$f['name']}*\n{$f['value']}",
                    ],
                    $rendered['fields']
                ),
            ],
        ];

        if ($rendered['link']) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open audit log'],
                    'url' => $rendered['link'],
                    'style' => $severity === 'critical' ? 'danger' : 'primary',
                ]],
            ];
        }

        return [
            'text' => $rendered['title'], // fallback for notifications
            'blocks' => $blocks,
        ];
    }

    /**
     * Discord webhook payload (Embed object).
     *
     * @param  array<string,mixed>  $rendered
     * @return array<string,mixed>
     */
    private function discordPayload(array $rendered, string $severity): array
    {
        $embed = [
            'title' => $rendered['title'],
            'description' => $rendered['body'],
            'color' => $rendered['color'],
            'timestamp' => now()->toIso8601String(),
            'fields' => array_map(
                fn (array $f) => [
                    'name' => $f['name'],
                    'value' => $f['value'],
                    'inline' => mb_strlen($f['value']) < 40,
                ],
                $rendered['fields']
            ),
        ];

        if ($rendered['link']) {
            $embed['url'] = $rendered['link'];
        }

        return [
            'username' => 'FLiK Security',
            'embeds' => [$embed],
        ];
    }

    /**
     * POST a payload to a webhook with SSRF-safe defaults.
     *
     * Once `App\Services\Http\SafeHttp` (peer SEC #10) lands we'll route
     * through it for IP-allowlisting. Until then we use Laravel's HTTP
     * client with `withoutRedirecting()` so a 302 to 169.254.169.254
     * (cloud metadata) cannot leak credentials into a forged webhook.
     */
    private function dispatch(string $url, array $payload, string $transport): void
    {
        // SSRF guard: webhook URL must be HTTPS and point at the
        // expected vendor host. Deny anything that smells internal.
        if (! $this->urlLooksSafe($url, $transport)) {
            Log::warning('SecurityAlertService: refusing unsafe webhook URL', [
                'transport' => $transport,
            ]);
            return;
        }

        try {
            // Prefer SafeHttp if the peer service exists; fall back to
            // a hardened Http call. Either path uses a tight timeout so
            // a hung webhook can't stall the request.
            if (class_exists(\App\Services\Http\SafeHttp::class)) {
                /** @var object $safe */
                $safe = app(\App\Services\Http\SafeHttp::class);
                if (method_exists($safe, 'post')) {
                    $safe->post($url, $payload, ['timeout' => self::HTTP_TIMEOUT_SECONDS]);
                    return;
                }
            }

            Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('SecurityAlertService: webhook dispatch failed', [
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cheap allowlist check. We require HTTPS and the canonical Slack
     * (`hooks.slack.com`) or Discord (`discord.com`, `discordapp.com`)
     * hostnames. This blocks 99% of SSRF foot-guns where someone sets
     * `SECURITY_ALERTS_SLACK_WEBHOOK=http://169.254.169.254/...`.
     */
    private function urlLooksSafe(string $url, string $transport): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        $allowed = match ($transport) {
            'slack' => ['hooks.slack.com'],
            'discord' => ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'],
            default => [],
        };

        foreach ($allowed as $needle) {
            if ($host === $needle || str_ends_with($host, '.' . $needle)) {
                return true;
            }
        }
        return false;
    }
}
