<?php

declare(strict_types=1);

namespace App\Services\Drm;

use App\Models\DrmSession;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Manages per-session AES-128 content keys for DIY DRM playback.
 *
 * - Each session gets its own randomly generated 16-byte content key.
 * - Keys are persisted encrypted (Laravel `encrypted` cast on DrmSession).
 * - Key delivery is rate-limit aware via `key_request_count` + `last_key_request_at`.
 *
 * This service is intentionally storage-agnostic: it does NOT decide where
 * encrypted segments live or how the key is delivered to clients. Callers
 * (controllers, manifest generator, encryptor) compose this with token
 * services and HTTP guards.
 */
class DrmKeyService
{
    /**
     * Generate a fresh raw 16-byte AES-128 content key.
     *
     * Returned bytes are RAW BINARY — do not log, base64 only when transporting.
     */
    public function generateContentKey(): string
    {
        return random_bytes(16);
    }

    /**
     * Create a new DRM session for the given user/movie pair.
     *
     * The session_token is a 64-char URL-safe random string handed to the
     * player. The raw content_key is generated here and stored encrypted.
     *
     * @param  int  $ttlMinutes  How long the session is valid (default 30 min).
     */
    public function createSession(
        User $user,
        Movie $movie,
        ?string $deviceFingerprint = null,
        ?string $ip = null,
        ?string $country = null,
        int $ttlMinutes = 30,
    ): DrmSession {
        $session = new DrmSession();
        $session->user_id = $user->id;
        $session->movie_id = $movie->id;
        $session->session_token = $this->generateSessionToken();
        $session->device_fingerprint = $deviceFingerprint;
        $session->client_ip = $ip;
        $session->country_code = $country !== null ? strtoupper(substr($country, 0, 2)) : null;
        // The `encrypted` cast handles ciphertext-at-rest transparently.
        $session->content_key = $this->generateContentKey();
        $session->key_request_count = 0;
        $session->expires_at = now()->addMinutes($ttlMinutes);
        $session->save();

        return $session;
    }

    /**
     * Look up a session by token, validate liveness, bump request counter
     * and return the RAW 16-byte content key.
     *
     * Returns null when the session is missing, expired or revoked. Callers
     * should treat null as "deny key delivery" without leaking which case it was.
     */
    public function getKey(string $sessionToken): ?string
    {
        $session = DrmSession::query()
            ->where('session_token', $sessionToken)
            ->first();

        if ($session === null) {
            return null;
        }

        if (! $session->isActive()) {
            return null;
        }

        // Atomically bump the request meter so abuse is observable.
        $session->forceFill([
            'last_key_request_at' => now(),
            'key_request_count' => $session->key_request_count + 1,
        ])->save();

        $key = $session->content_key;

        if (! is_string($key) || strlen($key) !== 16) {
            return null;
        }

        return $key;
    }

    /**
     * Rotate (re-issue) the content key for an existing session.
     *
     * Existing manifests/segments encrypted with the previous key will no
     * longer decrypt — caller is responsible for re-encrypting or invalidating
     * downstream artifacts as needed.
     */
    public function rotateSession(DrmSession $session): void
    {
        $session->content_key = $this->generateContentKey();
        $session->key_request_count = 0;
        $session->last_key_request_at = null;
        $session->save();
    }

    /**
     * 64-char URL-safe random token. `Str::random` uses random_bytes under
     * the hood — adequate for opaque session identifiers.
     */
    protected function generateSessionToken(): string
    {
        return Str::random(64);
    }
}
