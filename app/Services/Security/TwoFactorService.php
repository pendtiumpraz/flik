<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Pure-PHP TOTP (RFC 6238) + recovery-code service.
 *
 * No external dependencies — base32 encoding and the HOTP HMAC dance are
 * implemented inline below (~30 lines).  We deliberately avoid pulling in
 * pragmarx/google2fa or bacon/bacon-qr-code so the package set stays lean
 * and the auth flow has no surprise dependencies.
 *
 * Algorithm parameters (locked to Google Authenticator / Authy defaults):
 *   - 20-byte (160-bit) secret, encoded as 32-char base32
 *   - SHA1
 *   - 30-second time step
 *   - 6-digit code
 *   - ±1 step drift tolerance (configurable via $windowDriftSec)
 */
final class TwoFactorService
{
    /** RFC 4648 base32 alphabet (no padding chars used in TOTP secrets). */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const TIME_STEP = 30;
    private const DIGITS = 6;
    private const HASH_ALGO = 'sha1';

    /**
     * Generate a fresh 160-bit secret (32 base32 chars, no padding).
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Current TOTP code for a secret. Useful for surfacing the code during
     * the setup screen if we ever want a "test it works" preview.
     */
    public function currentCode(string $secret): string
    {
        return $this->totpAt($secret, (int) floor(time() / self::TIME_STEP));
    }

    /**
     * Verify a 6-digit code against a secret, with ±$windowDriftSec leeway
     * (default 30s ⇒ accepts the previous, current, and next time steps to
     * tolerate clock skew between the server and the user's phone).
     *
     * Constant-time comparison via hash_equals to defuse timing attacks
     * against the digit comparison.
     */
    public function verify(string $secret, string $code, int $windowDriftSec = 30): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $now = (int) floor(time() / self::TIME_STEP);
        $window = (int) floor($windowDriftSec / self::TIME_STEP);

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $this->totpAt($secret, $now + $i);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the otpauth:// URI that authenticator apps consume.
     *
     * Format: otpauth://totp/{Issuer}:{Account}?secret={B32}&issuer={Issuer}
     * URL-encoded per Google Authenticator key URI spec.
     */
    public function qrCodeUrl(User $user, string $secret): string
    {
        $issuer = (string) (config('app.name') ?: 'FLiK');
        $account = (string) ($user->email ?: ('user-' . $user->id));

        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::TIME_STEP,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Generate $n recovery codes — 10 hex chars each (5 random bytes).
     * Hex (not base32) so users can dictate them over the phone without
     * confusing 0/O / 1/I.
     *
     * @return array<int,string>
     */
    public function generateRecoveryCodes(int $n = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }

        return $codes;
    }

    /**
     * Consume a recovery code: case-insensitive, single-use, persists the
     * trimmed list back to the user.  Returns true iff the code matched
     * and was removed.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        if (!is_array($codes) || $codes === []) {
            return false;
        }

        $needle = strtoupper(trim($code));
        $kept = [];
        $matched = false;

        foreach ($codes as $existing) {
            if (!$matched && strtoupper(trim((string) $existing)) === $needle) {
                $matched = true;
                continue; // skip — consumed
            }
            $kept[] = $existing;
        }

        if (!$matched) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $kept,
        ])->save();

        return true;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * RFC 6238 TOTP at a specific counter (Unix time / 30).
     */
    private function totpAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }

        // Counter as 8-byte big-endian.
        $binCounter = pack('N*', 0, $counter);

        $hmac = hash_hmac(self::HASH_ALGO, $binCounter, $key, true);

        // RFC 4226 §5.3 dynamic truncation.
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $truncated = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $code = $truncated % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * RFC 4648 base32 encoder (no padding — matches the otpauth convention).
     */
    private function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    /**
     * RFC 4648 base32 decoder. Tolerant of casing, padding, hyphens and
     * spaces because users sometimes paste the secret manually.
     */
    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s\-=]+/', '', $encoded) ?? '');
        if ($encoded === '') {
            return '';
        }

        $bits = '';
        for ($i = 0, $len = strlen($encoded); $i < $len; $i++) {
            $pos = strpos(self::BASE32_ALPHABET, $encoded[$i]);
            if ($pos === false) {
                return ''; // invalid char ⇒ refuse to decode
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
