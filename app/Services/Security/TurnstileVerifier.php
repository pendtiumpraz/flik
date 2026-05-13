<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side verifier for Cloudflare Turnstile CAPTCHA tokens.
 *
 * Turnstile is Cloudflare's free, privacy-friendly drop-in for hCaptcha /
 * reCAPTCHA. The widget on the client posts a one-shot token under the
 * `cf-turnstile-response` form field; we POST it (along with our secret
 * key and the user's IP) to Cloudflare's siteverify endpoint to confirm
 * the challenge was actually solved before this exact server saw it.
 *
 * Behaviour knobs (FLiK's standard env-gating pattern):
 *   - {@see enabled()} returns false unless BOTH `services.turnstile.site_key`
 *     AND `services.turnstile.secret_key` are populated. When false, the
 *     {@see \App\Rules\CaptchaPassed} rule and the
 *     `<x-captcha-turnstile>` component both become silent no-ops so dev
 *     environments without Cloudflare keys don't break login/register.
 *   - Tokens are single-use and short-lived (Cloudflare expires them after
 *     ~5 minutes), so we DO NOT cache verification responses — each call
 *     hits Cloudflare's API directly.
 *   - On any network/parse error we fail-closed in production (return
 *     false → validation rule fails → user sees "verifikasi gagal") and
 *     fail-open in local/dev so missing internet doesn't block work.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
final class TurnstileVerifier
{
    /** Cloudflare siteverify endpoint. Public, HTTPS-only. */
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * HTTP timeout in seconds for the siteverify call. Tight on purpose —
     * the user is staring at a "Submitting…" spinner while we wait.
     */
    private const HTTP_TIMEOUT = 4;

    /**
     * True when both site_key and secret_key are populated in config.
     *
     * Callers (CaptchaPassed rule, blade component) gate themselves on this
     * so the integration is invisible when keys are missing.
     */
    public function enabled(): bool
    {
        $site = (string) config('services.turnstile.site_key', '');
        $secret = (string) config('services.turnstile.secret_key', '');

        return $site !== '' && $secret !== '';
    }

    /**
     * Verify a single Turnstile response token against Cloudflare.
     *
     * @param  string       $token     Raw value of the `cf-turnstile-response` field.
     * @param  string|null  $remoteIp  End-user IP. Optional but recommended — Cloudflare
     *                                 uses it to detect token-replay across networks.
     * @return bool                    True only when Cloudflare confirms the token is
     *                                 valid AND fresh AND tied to this site_key.
     */
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (! $this->enabled()) {
            // No keys configured → treat as a pass; the calling rule should
            // never even reach here when enabled() is false but we belt-and-
            // brace it so a misuse doesn't accidentally lock everyone out.
            return true;
        }

        if (trim($token) === '') {
            // Empty token = user never solved the widget (or JS broken).
            // No need to round-trip to Cloudflare for that.
            return false;
        }

        $payload = [
            'secret' => (string) config('services.turnstile.secret_key'),
            'response' => $token,
        ];

        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::HTTP_TIMEOUT)
                ->post(self::SITEVERIFY_URL, $payload);
        } catch (Throwable $e) {
            // Network unreachable, DNS fail, TLS handshake error, etc.
            return $this->handleFailure('Turnstile siteverify request threw', [
                'error' => $e->getMessage(),
                'remote_ip' => $remoteIp,
            ]);
        }

        if (! $response->successful()) {
            return $this->handleFailure('Turnstile siteverify non-2xx response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'remote_ip' => $remoteIp,
            ]);
        }

        /** @var array<string,mixed> $body */
        $body = (array) $response->json();
        $success = (bool) ($body['success'] ?? false);

        if (! $success) {
            // Cloudflare returns `error-codes` like ["invalid-input-response",
            // "timeout-or-duplicate"]. Log them at info-level so admins can
            // distinguish "expired token" from "wrong secret key" without
            // alarming the warning channel.
            Log::info('Turnstile token rejected', [
                'error_codes' => $body['error-codes'] ?? [],
                'remote_ip' => $remoteIp,
            ]);
        }

        return $success;
    }

    /**
     * Decide whether transport-level failures should fail-closed or open.
     *
     * Production: fail-closed (return false). Better to inconvenience users
     * during a Cloudflare outage than to drop the CAPTCHA wall entirely
     * during an attack window.
     *
     * Local / dev / testing: fail-open. Devs without internet shouldn't be
     * blocked from logging into the seeder accounts.
     *
     * @param  array<string,mixed>  $context
     */
    private function handleFailure(string $message, array $context): bool
    {
        $isProduction = app()->environment('production');

        if ($isProduction) {
            Log::warning($message . ' (fail-closed)', $context);

            return false;
        }

        Log::warning($message . ' (fail-open in non-prod)', $context);

        return true;
    }
}
