<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\KnownDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Geo\GeoIpResolver;
use App\Support\SecurityEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LoginAlertService
 * --------------------------------------------------------------------------
 * On every successful login we:
 *   1. Compute a stable per-request fingerprint (sha256 of ip + UA +
 *      Accept-Language). Coarse but private — we don't need browser
 *      fingerprinting libraries, just "looks like the same browser
 *      on the same network".
 *   2. Look up (user_id, fingerprint) in `known_devices`. If the row is
 *      missing it's a new device and we record + flag for alert.
 *   3. Compare the resolved country against the user's last 5 logins
 *      (from `audit_logs` action="auth.login"). A new country is also
 *      worth alerting on — VPN/relocation/account takeover.
 *   4. Fire a velocity heuristic: if the user has signed in from 3+
 *      distinct countries in the last 60 minutes we record an
 *      `security.suspicious_geo_velocity` audit event. Future work can
 *      escalate this to forced re-verification — for now we just log.
 *
 * Trusted devices (`trusted = true`) suppress alerts but are still
 * touched (last_seen_at bumped + audit row recorded).
 */
class LoginAlertService
{
    /**
     * How many recent logins to scan for the "new country" check.
     */
    private const COUNTRY_HISTORY_DEPTH = 5;

    /**
     * Velocity window for the suspicious-geo heuristic.
     */
    private const VELOCITY_WINDOW_MINUTES = 60;

    /**
     * How many distinct countries in the velocity window before we flag.
     */
    private const VELOCITY_COUNTRY_THRESHOLD = 3;

    public function __construct(
        private readonly GeoIpResolver $geo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Compute a stable per-request fingerprint.
     *
     * The triple (IP, UA, Accept-Language) is a deliberately coarse
     * signal — different enough across devices/networks to detect the
     * "new device" case, but stable enough that the same browser on
     * the same Wi-Fi reuses the row across sessions.
     */
    public function fingerprint(Request $request): string
    {
        $ip = (string) ($request->ip() ?? '');
        $ua = (string) ($request->userAgent() ?? '');
        $lang = (string) ($request->header('Accept-Language') ?? '');

        return hash('sha256', $ip . '|' . $ua . '|' . $lang);
    }

    /**
     * Record the device touch and report what changed.
     *
     * @return array{
     *     is_new_device: bool,
     *     is_new_country: bool,
     *     should_alert: bool,
     *     device: \App\Models\KnownDevice
     * }
     */
    public function recordAndCheck(User $user, Request $request): array
    {
        $fingerprint = $this->fingerprint($request);
        $ip          = (string) ($request->ip() ?? '');
        $userAgent   = (string) ($request->userAgent() ?? '');
        $country     = $this->resolveCountrySafely($ip);

        // Country comparison must happen BEFORE we audit the current login,
        // otherwise the current row pollutes the "last 5" history.
        $isNewCountry = $this->isNewCountry($user, $country);

        $existing = KnownDevice::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->first();

        if ($existing === null) {
            $device = KnownDevice::create([
                'user_id'            => $user->id,
                'device_fingerprint' => $fingerprint,
                'ip'                 => $ip !== '' ? $ip : '0.0.0.0',
                'country'            => $country,
                'user_agent'         => $userAgent !== '' ? $userAgent : null,
                'first_seen_at'      => now(),
                'last_seen_at'       => now(),
                'trusted'            => false,
            ]);

            $isNewDevice = true;
        } else {
            // Touch existing row. Refresh IP/country/UA in case they've
            // drifted (mobile network change, etc.) — fingerprint is the
            // identity, not the auxiliary fields.
            $existing->update([
                'ip'           => $ip !== '' ? $ip : $existing->ip,
                'country'      => $country ?? $existing->country,
                'user_agent'   => $userAgent !== '' ? $userAgent : $existing->user_agent,
                'last_seen_at' => now(),
            ]);

            $device      = $existing;
            $isNewDevice = false;
        }

        // Trusted devices never alert, but new-country still matters even
        // on a trusted device (could be VPN takeover) — we surface it.
        $shouldAlert = ($isNewDevice || $isNewCountry) && ! $device->trusted;

        // Audit *after* the new-country check so the lookup window is clean.
        $this->logLoginAudit($user, $ip, $country, $isNewDevice, $isNewCountry);

        // Velocity heuristic — runs every login, regardless of alert status.
        $this->checkSuspiciousVelocity($user, $request, $country);

        return [
            'is_new_device'  => $isNewDevice,
            'is_new_country' => $isNewCountry,
            'should_alert'   => $shouldAlert,
            'device'         => $device,
        ];
    }

    /**
     * Check whether $country is absent from the user's last N login countries.
     *
     * - Returns false when we don't have a country (private/loopback IP,
     *   resolver failure) — we don't alert on "unknown vs unknown".
     * - Returns false on the very first login (no history).
     */
    private function isNewCountry(User $user, ?string $country): bool
    {
        if ($country === null) {
            return false;
        }

        try {
            $recent = \App\Models\AuditLog::query()
                ->where('user_id', $user->id)
                ->where('action', 'auth.login')
                ->latest('id')
                ->limit(self::COUNTRY_HISTORY_DEPTH)
                ->pluck('meta');
        } catch (Throwable $e) {
            Log::debug('LoginAlertService: history lookup failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }

        if ($recent->isEmpty()) {
            // First-ever login — not "new country", just initial.
            return false;
        }

        foreach ($recent as $meta) {
            $previous = is_array($meta) ? ($meta['country'] ?? null) : null;
            if (is_string($previous) && strtoupper($previous) === strtoupper($country)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Velocity heuristic: 3+ distinct countries in the last 60 minutes
     * for the same user → log to audit_logs as suspicious_geo_velocity.
     *
     * For now we only log; future work can hook this into a forced
     * re-verification flow (clear sessions, require 2FA, etc.).
     */
    private function checkSuspiciousVelocity(User $user, Request $request, ?string $currentCountry): void
    {
        try {
            $rows = \App\Models\AuditLog::query()
                ->where('user_id', $user->id)
                ->where('action', 'auth.login')
                ->where('created_at', '>=', now()->subMinutes(self::VELOCITY_WINDOW_MINUTES))
                ->latest('id')
                ->limit(20)
                ->get(['meta', 'created_at']);
        } catch (Throwable $e) {
            return;
        }

        $countries = [];
        foreach ($rows as $row) {
            $meta = $row->meta;
            $c = is_array($meta) ? ($meta['country'] ?? null) : null;
            if (is_string($c) && $c !== '') {
                $countries[strtoupper($c)] = true;
            }
        }
        if ($currentCountry !== null) {
            $countries[strtoupper($currentCountry)] = true;
        }

        if (count($countries) >= self::VELOCITY_COUNTRY_THRESHOLD) {
            try {
                // Goes through ::security() so the row is flagged is_security
                // AND a SecurityEventLogged event fires for Slack/Discord
                // fan-out at "critical" severity (mapped in SecurityEvents).
                $this->audit->security(
                    event: SecurityEvents::SUSPICIOUS_GEO_VELOCITY,
                    subject: $user,
                    meta: [
                        'window_minutes'     => self::VELOCITY_WINDOW_MINUTES,
                        'distinct_countries' => array_keys($countries),
                        'current_country'    => $currentCountry,
                        'current_ip'         => (string) ($request->ip() ?? ''),
                    ],
                    user: $user,
                );
            } catch (Throwable $e) {
                Log::warning('LoginAlertService: failed to record suspicious velocity', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist the per-login audit row — single source of truth for the
     * country-history scan above.
     */
    private function logLoginAudit(
        User $user,
        string $ip,
        ?string $country,
        bool $isNewDevice,
        bool $isNewCountry,
    ): void {
        // 'auth.login' (legacy) is preserved as the country-history key —
        // the isNewCountry() lookup pivots on the action string, so renaming
        // here would silently break the heuristic for existing users. The
        // new SecurityEvents::NEW_DEVICE_LOGIN / NEW_COUNTRY_LOGIN rows are
        // emitted ALONGSIDE this canonical row.
        try {
            $this->audit->log(
                action: 'auth.login',
                subject: $user,
                meta: [
                    'ip'             => $ip !== '' ? $ip : null,
                    'country'        => $country,
                    'is_new_device'  => $isNewDevice,
                    'is_new_country' => $isNewCountry,
                ],
                user: $user,
            );
        } catch (Throwable $e) {
            Log::warning('LoginAlertService: failed to write login audit', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // New-device / new-country events go through ::security() so the
        // SecurityAlertService can fan out to Slack/Discord. We only emit
        // when the corresponding signal is true so the audit trail isn't
        // polluted with "false" rows on every routine login.
        if ($isNewDevice) {
            try {
                $this->audit->security(
                    event: SecurityEvents::NEW_DEVICE_LOGIN,
                    subject: $user,
                    meta: ['ip' => $ip !== '' ? $ip : null, 'country' => $country],
                    user: $user,
                );
            } catch (Throwable $e) {
                Log::warning('LoginAlertService: failed to record new device', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($isNewCountry) {
            try {
                $this->audit->security(
                    event: SecurityEvents::NEW_COUNTRY_LOGIN,
                    subject: $user,
                    meta: ['ip' => $ip !== '' ? $ip : null, 'country' => $country],
                    user: $user,
                );
            } catch (Throwable $e) {
                Log::warning('LoginAlertService: failed to record new country', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * GeoIpResolver throws on neither path, but be defensive — a resolver
     * failure must never block login.
     */
    private function resolveCountrySafely(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }

        try {
            return $this->geo->country($ip);
        } catch (Throwable $e) {
            Log::debug('LoginAlertService: GeoIpResolver failed', [
                'ip'    => $ip,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
