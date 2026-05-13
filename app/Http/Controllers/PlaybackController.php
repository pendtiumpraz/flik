<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DrmSession;
use App\Models\Movie;
use App\Services\Audit\AuditLogger;
use App\Services\Drm\ConcurrentStreamLimiter;
use App\Services\Drm\DeviceFingerprinter;
use App\Services\Drm\DrmKeyService;
use App\Services\Drm\DrmTokenService;
use App\Services\Drm\PlaybackManifestGenerator;
use App\Support\SecurityEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * HTTP entrypoints for the encrypted-playback flow.
 *
 *   1. config()    — issues a fresh DrmSession + JWTs, returns a bundle the
 *                    JS player needs to bootstrap (manifest URL, key URL
 *                    template, fingerprint script).
 *   2. manifest()  — emits the dynamic per-session HLS manifest.
 *   3. key()       — delivers the raw 16-byte AES content key after JWT +
 *                    geo + replay validation. Public route, JWT-protected.
 *   4. heartbeat() — refreshes the concurrent-stream lock and revalidates
 *                    device fingerprint; player calls this every ~30s.
 *
 * The controller is intentionally thin: every security decision lives in
 * the matching service class so unit tests can target one concern at a time.
 */
class PlaybackController extends Controller
{
    public function __construct(
        protected DrmKeyService $keys,
        protected DrmTokenService $tokens,
        protected ConcurrentStreamLimiter $streams,
        protected DeviceFingerprinter $fingerprinter,
        protected PlaybackManifestGenerator $manifests,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Bootstrap a playback session.
     *
     * Returns a JSON envelope the front-end uses to wire up Shaka:
     *  - manifestUrl:        signed media manifest URL
     *  - keyUrlTemplate:     `/drm/key/{sessionToken}/{keyId}` — Shaka substitutes keyId
     *  - jwt:                playback JWT (also embedded in manifestUrl)
     *  - fingerprintScript:  inline JS exposing window.flikFingerprint()
     *  - sessionToken:       opaque session id (used for heartbeats + key URL)
     */
    public function config(Request $request, Movie $movie): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Subscription gate. We honour the canonical helper on the User model
        // so feature-flag changes (free trials, promo windows) only need to
        // adjust hasActiveSubscription() in one place.
        if (! $user->hasActiveSubscription()) {
            return response()->json([
                'error' => 'subscription_required',
                'message' => 'An active subscription is required to play this title.',
            ], 402);
        }

        // Concurrent-stream gate. We need a session_token to acquire — but
        // the session row doesn't exist yet, so we mint a candidate token,
        // try to acquire a slot, then create the DrmSession with that token.
        $maxStreams = $this->streams->getMaxStreamsForUser($user);

        // Use a temporary token shape; the real session will adopt it on save.
        $candidateToken = bin2hex(random_bytes(32)); // 64-char hex, parity with Str::random(64)

        if (! $this->streams->acquire($user, $candidateToken, $movie->id, $maxStreams)) {
            return response()->json([
                'error' => 'stream_limit_reached',
                'message' => "Concurrent stream limit ({$maxStreams}) reached. Stop another device first.",
                'max_streams' => $maxStreams,
            ], 429);
        }

        // We don't know the device fingerprint at config-time — the player
        // computes it client-side and POSTs it back via heartbeat(). The
        // session row binds to the fingerprint on first heartbeat (TOFU).
        $session = $this->keys->createSession(
            user: $user,
            movie: $movie,
            deviceFingerprint: null,
            ip: $request->ip(),
            country: $this->resolveCountry($request),
            ttlMinutes: 30,
        );

        // The session_token generated inside DrmKeyService::createSession differs
        // from our candidate. Re-point the concurrent-stream lock at the real
        // token so heartbeats hit the right row.
        $this->streams->release($candidateToken);
        $this->streams->acquire($user, $session->session_token, $movie->id, $maxStreams);

        $playbackJwt = $this->tokens->issuePlaybackToken($session, 600);

        $manifestUrl = route('playback.manifest', ['movie' => $movie])
            . '?token=' . rawurlencode($playbackJwt);

        return response()->json([
            'manifestUrl'        => $manifestUrl,
            'keyUrlTemplate'     => '/drm/key/{sessionToken}/{keyId}',
            'jwt'                => $playbackJwt,
            'fingerprintScript'  => $this->fingerprinter->generateClientFingerprintScript(),
            'sessionToken'       => $session->session_token,
            'heartbeatIntervalMs' => 30_000,
            'heartbeatUrl'       => route('playback.heartbeat', ['movie' => $movie]),
        ]);
    }

    /**
     * Emit the dynamic m3u8 for this session.
     *
     * Token validation is strict: we reject without distinguishing reason
     * (signature/expiry/audience all collapse to a generic 403) so the
     * endpoint can't be probed for token introspection.
     */
    public function manifest(Request $request, Movie $movie): Response
    {
        $jwt = (string) $request->query('token', '');

        if ($jwt === '') {
            return response('Missing playback token.', 403);
        }

        $payload = $this->tokens->validatePlaybackToken($jwt);

        if ($payload === null) {
            return response('Invalid playback token.', 403);
        }

        // The token's movie_id MUST match the URL-bound movie. Otherwise a
        // valid token for movie A could be replayed against movie B.
        if ((int) ($payload['movie_id'] ?? 0) !== $movie->id) {
            return response('Token / movie mismatch.', 403);
        }

        $sessionId = (string) ($payload['session_id'] ?? '');
        $session = DrmSession::query()->where('session_token', $sessionId)->first();

        if ($session === null || ! $session->isActive()) {
            return response('Session expired.', 403);
        }

        $manifest = $this->manifests->generate($movie, $session, $this->tokens);

        // Cache busting on the client matters more than CDN caching here —
        // the manifest carries short-lived JWTs, so revalidation is mandatory.
        return response($manifest, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Deliver the raw AES-128 content key.
     *
     * Public route (no Laravel auth middleware) because the player fetches
     * this from the manifest, which is served to authenticated origins only.
     * Authorisation is enforced via the key-request JWT + geo gate.
     */
    public function key(Request $request, string $sessionToken, string $keyId): Response
    {
        $jwt = (string) $request->query('token', '');

        if ($jwt === '') {
            return $this->denyKey('missing_token', $sessionToken, $keyId);
        }

        $payload = $this->tokens->validateKeyRequestToken($jwt);

        if ($payload === null) {
            return $this->denyKey('invalid_token', $sessionToken, $keyId);
        }

        // Tie the JWT claims to the URL params — prevents key URL replay
        // with a token issued for a different session/key.
        if (($payload['session_id'] ?? null) !== $sessionToken) {
            return $this->denyKey('session_mismatch', $sessionToken, $keyId);
        }

        if (($payload['kid'] ?? null) !== $keyId) {
            return $this->denyKey('key_mismatch', $sessionToken, $keyId);
        }

        $session = DrmSession::query()
            ->where('session_token', $sessionToken)
            ->with('movie')
            ->first();

        if ($session === null || ! $session->isActive()) {
            return $this->denyKey('session_expired', $sessionToken, $keyId, $session?->user_id);
        }

        // Geo gate. movies.geo_allow holds an array of ISO-3166 alpha-2
        // codes, or null for worldwide. Empty array = denied everywhere.
        $movie = $session->movie;

        if ($movie !== null && $this->geoBlocked($movie, $request)) {
            return $this->denyKey('geo_restricted', $sessionToken, $keyId, $session->user_id, $movie);
        }

        $key = $this->keys->getKey($sessionToken);

        if ($key === null) {
            return $this->denyKey('key_unavailable', $sessionToken, $keyId, $session->user_id, $movie);
        }

        // Successful key handout — audit at low severity for usage analytics.
        $this->safeAudit(SecurityEvents::DRM_KEY_REQUEST, $movie, [
            'session_token' => $sessionToken,
            'key_id'        => $keyId,
            'movie_id'      => $movie?->id,
            'user_id'       => $session->user_id,
        ]);

        // Raw 16-byte binary. NO base64 — Shaka's clear-key flow expects raw.
        return response($key, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => '16',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build a 403 response AND emit a DRM_KEY_DENIED security audit. The
     * outward-facing message stays generic so an attacker can't probe
     * which check failed; the structured reason lives in the audit row.
     */
    private function denyKey(
        string $reason,
        string $sessionToken,
        string $keyId,
        ?int $userId = null,
        ?Movie $movie = null,
    ): Response {
        $this->safeAudit(SecurityEvents::DRM_KEY_DENIED, $movie, [
            'reason'        => $reason,
            'session_token' => $sessionToken,
            'key_id'        => $keyId,
            'user_id'       => $userId,
            'movie_id'      => $movie?->id,
        ]);

        // Generic message — every failure path collapses to a single body
        // so the endpoint can't be probed for token introspection.
        return response($reason === 'geo_restricted' ? 'Geo-restricted.' : 'Key request denied.', $reason === 'geo_restricted' ? 451 : 403);
    }

    /**
     * Best-effort audit write — DRM key delivery must never break on a
     * downstream audit / Slack outage.
     *
     * @param  array<string,mixed>  $meta
     */
    private function safeAudit(string $event, ?Movie $subject, array $meta = []): void
    {
        try {
            $this->audit->security($event, $subject, $meta);
        } catch (Throwable $e) {
            \Log::warning('PlaybackController: audit write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Heartbeat: extend stream lock + revalidate device fingerprint.
     *
     * Body shape: { "fingerprint": { canvas, webgl, audio, screen, ... } }
     * Returns 200 OK on success, 403 if anything mismatches.
     */
    public function heartbeat(Request $request, Movie $movie): JsonResponse
    {
        $jwt = (string) ($request->bearerToken() ?? $request->input('token', ''));

        if ($jwt === '') {
            return response()->json(['error' => 'missing_token'], 403);
        }

        $payload = $this->tokens->validatePlaybackToken($jwt);

        if ($payload === null) {
            return response()->json(['error' => 'invalid_token'], 403);
        }

        if ((int) ($payload['movie_id'] ?? 0) !== $movie->id) {
            return response()->json(['error' => 'movie_mismatch'], 403);
        }

        $sessionId = (string) ($payload['session_id'] ?? '');
        $session = DrmSession::query()->where('session_token', $sessionId)->first();

        if ($session === null || ! $session->isActive()) {
            return response()->json(['error' => 'session_expired'], 403);
        }

        // Device fingerprint validation. First heartbeat binds the fingerprint
        // (TOFU); subsequent heartbeats must match. A mismatch indicates the
        // session_token has been copied to another device.
        $clientFingerprint = (array) $request->input('fingerprint', []);

        if (! empty($clientFingerprint)) {
            if (! $this->fingerprinter->validateClaim($clientFingerprint, $session)) {
                return response()->json(['error' => 'fingerprint_mismatch'], 403);
            }

            // Bind on first heartbeat.
            if (empty($session->device_fingerprint)) {
                $session->forceFill([
                    'device_fingerprint' => $this->fingerprinter->hash($clientFingerprint),
                ])->save();
            }
        }

        // Refresh concurrent-stream lock. Silently no-ops if the lock has
        // expired — the player will discover that on its next playback request.
        $this->streams->heartbeat($session->session_token);

        return response()->json([
            'ok' => true,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Best-effort country resolution.
     *
     * Tries CDN-supplied headers first (CF-IPCountry, X-Country-Code,
     * X-Geoip-Country), then falls back to an injected GeoIpResolver if
     * the peer agent has provided one. Returns null when undetermined.
     */
    protected function resolveCountry(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Country-Code', 'X-Geoip-Country', 'X-AppEngine-Country'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && strlen($value) === 2) {
                return strtoupper($value);
            }
        }

        // Optional GeoIpResolver — resolved through the container if bound.
        if (app()->bound('App\\Services\\GeoIpResolver')) {
            try {
                /** @var object{resolve:callable}|null $resolver */
                $resolver = app('App\\Services\\GeoIpResolver');
                if ($resolver !== null && method_exists($resolver, 'resolve')) {
                    $country = $resolver->resolve($request->ip());
                    if (is_string($country) && strlen($country) === 2) {
                        return strtoupper($country);
                    }
                }
            } catch (\Throwable $e) {
                // Geo resolution must never fail the request; fall through.
            }
        }

        return null;
    }

    /**
     * Returns true when this request's country is NOT in the movie's
     * geo_allow list. A null/empty list means "worldwide" → never blocked.
     */
    protected function geoBlocked(Movie $movie, Request $request): bool
    {
        $allow = $movie->geo_allow ?? null;

        // Column exists but JSON-cast may not be wired up; tolerate strings.
        if (is_string($allow)) {
            $decoded = json_decode($allow, true);
            $allow = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($allow) || $allow === []) {
            // null or missing → unrestricted. Empty array = explicit "deny all"
            // would be unusual but we treat it as unrestricted to avoid
            // accidentally blocking everyone if a misconfigured admin saves [].
            return false;
        }

        $country = $this->resolveCountry($request);

        if ($country === null) {
            // Can't determine country and geo restriction is active → deny.
            return true;
        }

        $normalised = array_map(static fn ($c) => strtoupper((string) $c), $allow);

        return ! in_array($country, $normalised, true);
    }
}
