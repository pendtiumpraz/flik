<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\LoginAttempt;

/**
 * Brute-force protection for the email/password login flow.
 *
 * Two-axis lockout — both must be checked on every POST /login:
 *   1. Per-account  (`isLockedOut`)   → defends a single high-value target
 *   2. Per-IP       (`isIpLockedOut`) → defends against credential stuffing
 *      across many usernames from one source
 *
 * Plus a progressive delay (`progressiveDelaySeconds`) that the controller
 * `sleep()`s before validation. This raises attacker cost without ever
 * locking out a fat-fingered legit user.
 *
 * All knobs live in `config/security.php` → `throttle`.
 *
 * Persistence: backed by the `login_attempts` table (App\Models\LoginAttempt).
 * We *don't* use Laravel's cache-based RateLimiter for the per-account count
 * because we want a tamper-resistant audit trail (the admin unlock view
 * needs to render history, and we want this to survive a Redis flush).
 *
 * The named 'login' RateLimiter in RouteServiceProvider is a separate
 * coarse outer guard against floods — it complements but does not replace
 * the persistent counts here.
 */
class LoginThrottle
{
    /**
     * Record an attempt — call this for both successes and failures so
     * the admin "login attempt history" view is complete.
     */
    public function record(string $email, string $ip, ?string $ua, bool $success): void
    {
        // LoginAttempt uses $guarded = ['*'] (mass-assignment audit,
        // 2026-05-13). LoginThrottle is the canonical write path; use
        // forceCreate to bypass the guard from this trusted system sink.
        LoginAttempt::forceCreate([
            'email'        => $this->normalise($email),
            'ip'           => $ip,
            'user_agent'   => $ua === null ? null : mb_substr($ua, 0, 255),
            'success'      => $success,
            'attempted_at' => now(),
        ]);
    }

    /**
     * True when this email has accumulated `max_attempts` *failed* logins
     * in the rolling window. The user sees a 429 + friendly message.
     */
    public function isLockedOut(string $email): bool
    {
        return $this->recentFailureCount($email) >= $this->maxAttempts();
    }

    /**
     * True when this IP has accumulated `ip_max_attempts` *failed* logins
     * in the rolling window across any account. Defends against credential
     * stuffing where each request uses a different username.
     */
    public function isIpLockedOut(string $ip): bool
    {
        $count = LoginAttempt::query()
            ->fromIp($ip)
            ->where('success', false)
            ->recentMinutes($this->lockoutMinutes())
            ->count();

        return $count >= $this->ipMaxAttempts();
    }

    /**
     * Return the number of seconds the controller should `sleep()` before
     * processing the request — escalates with each recent failure for the
     * email but caps at the last value of the configured ladder.
     *
     * Examples (default ladder [0,1,2,4,8,16]):
     *   0 prior failures → 0s
     *   1               → 1s
     *   2               → 2s
     *   3               → 4s
     *   4               → 8s
     *   5+              → 16s (also locked out at this point)
     */
    public function progressiveDelaySeconds(string $email): int
    {
        $ladder = config('security.throttle.progressive_delay', [0, 1, 2, 4, 8, 16]);
        if (! is_array($ladder) || $ladder === []) {
            return 0;
        }

        $failures = $this->recentFailureCount($email);
        $maxIndex = count($ladder) - 1;
        $index    = min($failures, $maxIndex);

        return max(0, (int) $ladder[$index]);
    }

    /**
     * Clear failed attempts for an email — called on successful login and
     * by the admin "unlock" endpoint. Keeps successful rows around so the
     * audit trail is preserved.
     */
    public function unlock(string $email): void
    {
        LoginAttempt::query()
            ->forEmail($email)
            ->where('success', false)
            ->delete();
    }

    // ── Internals ─────────────────────────────────────────────────────

    private function recentFailureCount(string $email): int
    {
        return LoginAttempt::query()
            ->forEmail($email)
            ->where('success', false)
            ->recentMinutes($this->lockoutMinutes())
            ->count();
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function lockoutMinutes(): int
    {
        return max(1, (int) config('security.throttle.lockout_minutes', 15));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('security.throttle.max_attempts', 5));
    }

    private function ipMaxAttempts(): int
    {
        return max(1, (int) config('security.throttle.ip_max_attempts', 20));
    }
}
