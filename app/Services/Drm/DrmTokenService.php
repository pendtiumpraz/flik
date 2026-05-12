<?php

declare(strict_types=1);

namespace App\Services\Drm;

use App\Models\DrmSession;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Issues + validates short-lived JWTs used by the playback pipeline.
 *
 * Two token shapes:
 *  - Playback token (TTL ~10 min, default): bound to a DrmSession + device,
 *    presented on segment requests.
 *  - Key request token (TTL ~5 min, default): one-shot scoped JWT bound to
 *    a specific keyId, presented to the AES key delivery endpoint.
 *
 * Both are HS256-signed using the application's symmetric key derived from
 * APP_KEY. firebase/php-jwt is used when available; otherwise a manual
 * base64+HMAC implementation provides equivalent semantics.
 */
class DrmTokenService
{
    protected const ISSUER = 'flik';
    protected const AUD_PLAYBACK = 'playback';
    protected const AUD_KEY = 'drm-key';
    protected const ALG = 'HS256';

    /**
     * Issue a playback token bound to the given DrmSession.
     *
     * @param  int  $ttlSeconds  Token validity window (default 600s / 10 min).
     */
    public function issuePlaybackToken(DrmSession $session, int $ttlSeconds = 600): string
    {
        $now = time();

        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::AUD_PLAYBACK,
            'sub' => (string) $session->user_id,
            'movie_id' => $session->movie_id,
            'session_id' => $session->session_token,
            'fp' => $session->device_fingerprint,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => uniqid('pb_', true),
        ];

        return $this->encode($payload);
    }

    /**
     * Decode + validate a playback token. Returns the payload as an
     * associative array, or null on any failure (signature, expiry, audience).
     *
     * @return array<string,mixed>|null
     */
    public function validatePlaybackToken(string $jwt): ?array
    {
        $payload = $this->decode($jwt);

        if ($payload === null) {
            return null;
        }

        if (($payload['iss'] ?? null) !== self::ISSUER) {
            return null;
        }

        if (($payload['aud'] ?? null) !== self::AUD_PLAYBACK) {
            return null;
        }

        return $payload;
    }

    /**
     * Issue a tighter-scoped JWT for the AES key delivery endpoint.
     *
     * @param  string  $keyId      Identifier of the content key being requested
     *                             (typically the session_token or a derived id).
     * @param  int     $ttlSeconds Token validity (default 300s / 5 min).
     */
    public function issueKeyRequestToken(DrmSession $session, string $keyId, int $ttlSeconds = 300): string
    {
        $now = time();

        $payload = [
            'iss' => self::ISSUER,
            'aud' => self::AUD_KEY,
            'sub' => (string) $session->user_id,
            'movie_id' => $session->movie_id,
            'session_id' => $session->session_token,
            'kid' => $keyId,
            'fp' => $session->device_fingerprint,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => uniqid('kr_', true),
        ];

        return $this->encode($payload);
    }

    /**
     * Decode + validate a key-request token. Returns payload or null.
     *
     * @return array<string,mixed>|null
     */
    public function validateKeyRequestToken(string $jwt): ?array
    {
        $payload = $this->decode($jwt);

        if ($payload === null) {
            return null;
        }

        if (($payload['iss'] ?? null) !== self::ISSUER) {
            return null;
        }

        if (($payload['aud'] ?? null) !== self::AUD_KEY) {
            return null;
        }

        return $payload;
    }

    // ────────────────────────────────────────────────────────────
    // Encoding internals
    // ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        $secret = $this->signingSecret();

        if (class_exists(JWT::class)) {
            return JWT::encode($payload, $secret, self::ALG);
        }

        return $this->manualEncode($payload, $secret);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function decode(string $jwt): ?array
    {
        $secret = $this->signingSecret();

        if (class_exists(JWT::class) && class_exists(Key::class)) {
            try {
                $decoded = JWT::decode($jwt, new Key($secret, self::ALG));
            } catch (ExpiredException | SignatureInvalidException $e) {
                return null;
            } catch (\Throwable $e) {
                return null;
            }

            return json_decode(json_encode($decoded), true);
        }

        return $this->manualDecode($jwt, $secret);
    }

    /**
     * Derive an HMAC secret from APP_KEY. APP_KEY ships as `base64:...`;
     * we strip the prefix and decode so the signing key is full entropy.
     */
    protected function signingSecret(): string
    {
        $appKey = (string) config('app.key', '');

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $appKey !== '' ? $appKey : 'flik-drm-fallback-key';
    }

    // ────────────────────────────────────────────────────────────
    // Manual JWT fallback (HS256)
    // ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function manualEncode(array $payload, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => self::ALG];

        $segments = [
            $this->base64UrlEncode((string) json_encode($header)),
            $this->base64UrlEncode((string) json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function manualDecode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $headerJson = $this->base64UrlDecode($h64);
        $payloadJson = $this->base64UrlDecode($p64);
        $signature = $this->base64UrlDecode($s64);

        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? null) !== self::ALG) {
            return null;
        }

        $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        if (isset($payload['nbf']) && time() < (int) $payload['nbf']) {
            return null;
        }

        return $payload;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
