<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\Movie;
use App\Models\MovieView;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ViewTracker
 * --------------------------------------------------------------------------
 * Single entrypoint for "someone watched / opened" a movie. Writes one row
 * to `movie_views` per event, with built-in de-duplication so a single user
 * hammering refresh doesn't inflate trending scores.
 *
 * Call sites (intentional list — keep this comment in sync):
 *   - WatchHistoryController::updateProgress — first progress write per
 *     session. The strongest signal ("they pressed play and watched").
 *   - VelflixController::show — page-view-only (lower weight, no
 *     duration). Captures discovery interest even when the user bounces.
 *
 * DE-DUPLICATION
 * --------------
 * Within a 30-minute window, the same (user_id|ip_hash|session_id)
 * watching the same movie counts ONCE. Implemented two ways for
 * defence-in-depth:
 *   1. A short-TTL Cache lock keyed on the identity tuple — blocks
 *      bursts within a second without a DB round-trip.
 *   2. A `where('viewed_at', '>=', now - 30min)` SELECT — catches
 *      multi-process / multi-server bursts the local cache misses.
 *
 * PRIVACY
 * -------
 * `ip_hash = sha256(ip + APP_KEY)` — the pepper means the hash cannot
 * be reversed to an IP even if the table leaks. We only ever need it
 * for uniqueness counts; we never report raw IPs in admin views.
 */
class ViewTracker
{
    /** De-dup window — same identity won't re-count within this many minutes. */
    private const DEDUP_MINUTES = 30;

    /** Cache lock TTL (seconds) — pre-empts in-process bursts. */
    private const LOCK_TTL_SECONDS = 60;

    /**
     * Record a view event. Safe to call repeatedly — no-ops within the
     * 30-minute de-dup window. Never throws into the caller (the chatbot
     * exception aside, services log + swallow per CLAUDE.md conventions).
     */
    public function record(
        Movie $movie,
        ?User $user = null,
        ?Request $request = null,
        ?int $durationSeconds = null,
    ): void {
        try {
            $request ??= app()->bound('request') ? request() : null;

            $sessionId = $this->resolveSessionId($request);
            $ipHash = $this->resolveIpHash($request);
            $device = $this->resolveDevice($request);
            $userId = $user?->getKey();

            // Cache lock — pre-empts within-process / fast-burst dupes
            // before we even hit the DB. The DB check below is the
            // authoritative gate for multi-server scenarios.
            $lockKey = $this->lockKey($movie->id, $userId, $ipHash, $sessionId);
            if (Cache::has($lockKey)) {
                return;
            }

            if ($this->wasRecentlyRecorded($movie->id, $userId, $ipHash, $sessionId)) {
                // Refresh the lock so we don't keep re-querying.
                Cache::put($lockKey, 1, self::LOCK_TTL_SECONDS);

                return;
            }

            // forceCreate bypasses $fillable / $guarded — matches the
            // canonical "system-trusted write" pattern used by
            // AuditLogger and WatchHistoryController.
            MovieView::forceCreate([
                'movie_id' => $movie->id,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip_hash' => $ipHash,
                'viewed_at' => now(),
                'duration_seconds' => $durationSeconds,
                'device' => $device,
            ]);

            Cache::put($lockKey, 1, self::LOCK_TTL_SECONDS);
        } catch (Throwable $e) {
            // Tracking failures must NEVER bubble up — the player /
            // detail page must still respond even when the trending
            // table is unreachable. Match the AuditLogger pattern.
            Log::warning('ViewTracker::record failed', [
                'movie_id' => $movie->id ?? null,
                'user_id' => $user?->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull (or fabricate) a stable session identifier. We prefer the
     * Laravel session ID when present; fall back to a random token so
     * anonymous CLI / API callers still have a per-call identity for
     * the de-dup check.
     */
    private function resolveSessionId(?Request $request): ?string
    {
        if ($request && $request->hasSession()) {
            try {
                $id = $request->session()->getId();
                if ($id) {
                    // session IDs can exceed 40 chars under some drivers
                    // (e.g. redis with custom prefixes); truncate to fit
                    // the column without forcing a length collision.
                    return substr($id, 0, 40);
                }
            } catch (Throwable) {
                // session not started — fall through
            }
        }

        return substr(Str::random(40), 0, 40);
    }

    /**
     * Build the peppered IP hash. `APP_KEY` is the pepper — without it
     * a leaked table could be reversed via rainbow tables for the
     * (~4 billion) IPv4 space; with it, the pepper is unrecoverable.
     */
    private function resolveIpHash(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        $ip = $request->ip();
        if (! $ip) {
            return null;
        }

        $pepper = (string) config('app.key');

        return hash('sha256', $ip.'|'.$pepper);
    }

    /**
     * Coarse device classification from the user-agent. We only need
     * 20 chars (column limit) — fine-grained device fingerprinting
     * lives in DeviceFingerprinter for DRM, not here.
     */
    private function resolveDevice(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return null;
        }

        return match (true) {
            str_contains($ua, 'smarttv') || str_contains($ua, 'roku') || str_contains($ua, 'appletv') => 'tv',
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'iphone') || str_contains($ua, 'android') || str_contains($ua, 'mobile') => 'mobile',
            default => 'desktop',
        };
    }

    /**
     * Is there a recent row for this (movie, identity) tuple? Matches
     * on user_id OR ip_hash OR session_id so authenticated and
     * anonymous bursts both get suppressed.
     */
    private function wasRecentlyRecorded(
        int $movieId,
        ?int $userId,
        ?string $ipHash,
        ?string $sessionId,
    ): bool {
        $threshold = now()->subMinutes(self::DEDUP_MINUTES);

        $query = MovieView::query()
            ->where('movie_id', $movieId)
            ->where('viewed_at', '>=', $threshold);

        $query->where(function ($q) use ($userId, $ipHash, $sessionId) {
            $applied = false;

            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
                $applied = true;
            }
            if ($ipHash !== null) {
                $q->orWhere('ip_hash', $ipHash);
                $applied = true;
            }
            if ($sessionId !== null) {
                $q->orWhere('session_id', $sessionId);
                $applied = true;
            }

            // No identity at all → no dedup possible; force the OR group
            // to evaluate false so we still insert.
            if (! $applied) {
                $q->whereRaw('1 = 0');
            }
        });

        return $query->exists();
    }

    private function lockKey(
        int $movieId,
        ?int $userId,
        ?string $ipHash,
        ?string $sessionId,
    ): string {
        return sprintf(
            'trending:view-lock:%d:%s:%s:%s',
            $movieId,
            $userId ?? '-',
            $ipHash ? substr($ipHash, 0, 16) : '-',
            $sessionId ? substr($sessionId, 0, 16) : '-',
        );
    }
}
