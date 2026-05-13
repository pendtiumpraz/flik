<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reject passwords that appear in the Have-I-Been-Pwned (HIBP) breach corpus.
 *
 * Uses the k-anonymity API (https://api.pwnedpasswords.com/range/{prefix}):
 *   1. SHA1 the candidate password.
 *   2. Send only the first 5 hex chars of the hash to HIBP.
 *   3. HIBP returns every suffix it knows that starts with that prefix.
 *   4. We compare locally — the full hash never leaves the server.
 *
 * Behavior knobs:
 *   - Disabled entirely when config('security.password.check_breach') is false.
 *   - 24-hour Redis/cache lookup keyed by SHA1 prefix; HIBP responses are
 *     stable enough that a daily refresh is plenty.
 *   - Fail-OPEN on network errors (timeout, non-200, exception). The HIBP
 *     dependency must never lock legitimate users out — we log a warning
 *     and let the password through. The {@see StrongPassword} rule still
 *     enforces the deterministic policy in front of this one.
 */
final class NotBreached implements ValidationRule
{
    private const HIBP_ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    /** Cache TTL in seconds (24 hours). */
    private const CACHE_TTL = 86_400;

    /** HTTP timeout in seconds. Kept tight — registration is interactive. */
    private const HTTP_TIMEOUT = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // StrongPassword handles type / empty validation.
        }

        if (! (bool) config('security.password.check_breach', true)) {
            return;
        }

        $hash = strtoupper(sha1($value));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $body = $this->fetchPrefix($prefix);
        if ($body === null) {
            // Fail-open: we already logged the warning in fetchPrefix().
            return;
        }

        // Response format: one "SUFFIX:COUNT" per line (CRLF separated).
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            [$candidateSuffix, $countStr] = array_pad(explode(':', $line, 2), 2, '0');

            if (strcasecmp($candidateSuffix, $suffix) === 0 && (int) $countStr > 0) {
                $fail('Password ini ditemukan dalam database kebocoran data. Silakan pilih password lain. / This password has appeared in known data breaches. Please choose another.');

                return;
            }
        }
    }

    /**
     * Fetch a prefix range from HIBP, with 24h cache and fail-open on error.
     *
     * Returned string is the raw response body (suffix:count lines). Returns
     * null on any network/HTTP failure so callers can no-op.
     */
    private function fetchPrefix(string $prefix): ?string
    {
        $cacheKey = 'hibp:range:' . $prefix;

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['Add-Padding' => 'true']) // length-blinding
                ->get(self::HIBP_ENDPOINT . $prefix);
        } catch (Throwable $e) {
            Log::warning('HIBP request failed (fail-open)', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('HIBP request non-2xx (fail-open)', [
                'prefix' => $prefix,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = (string) $response->body();
        Cache::put($cacheKey, $body, self::CACHE_TTL);

        return $body;
    }
}
