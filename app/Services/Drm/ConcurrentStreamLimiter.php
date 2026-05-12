<?php

namespace App\Services\Drm;

use App\Models\PlaybackConcurrentLock;
use App\Models\User;

/**
 * Concurrent-stream gate.
 *
 * Enforces the per-user max-streams limit derived from the user's
 * active subscription plan (`subscription_plans.max_screens`).
 *
 * Backed by the `playback_concurrent_locks` table — each row is one
 * active playback session keyed by `session_token`. Sessions that
 * stop heartbeating expire naturally via `expires_at`.
 *
 * No Redis dependency: the table queries are cheap and indexed on
 * (user_id, expires_at). A Redis-backed implementation can swap in
 * later behind the same interface without touching call sites.
 */
class ConcurrentStreamLimiter
{
    /**
     * Default heartbeat extension if caller does not specify one.
     */
    public const DEFAULT_EXTEND_MINUTES = 5;

    /**
     * Default max streams if user has no active subscription plan.
     */
    public const DEFAULT_MAX_STREAMS = 1;

    /**
     * Try to acquire (or refresh) a stream slot for this session.
     *
     * Logic:
     *  1. Count active locks for the user (expires_at > now).
     *  2. If the current `$sessionToken` is already among those locks,
     *     just refresh it and allow.
     *  3. Otherwise, if `count >= $maxStreams`, deny.
     *  4. Otherwise, create a new lock and allow.
     *
     * Returns true on success (slot acquired or already held), false
     * when the user is at their stream cap on a different session.
     */
    public function acquire(User $user, string $sessionToken, int $movieId, int $maxStreams): bool
    {
        $activeLocks = PlaybackConcurrentLock::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->get(['id', 'session_token']);

        $existing = $activeLocks->firstWhere('session_token', $sessionToken);

        if ($existing) {
            // Same session reconnecting — just refresh and allow.
            $this->heartbeat($sessionToken);

            return true;
        }

        if ($activeLocks->count() >= $maxStreams) {
            return false;
        }

        PlaybackConcurrentLock::create([
            'user_id'       => $user->id,
            'session_token' => $sessionToken,
            'movie_id'      => $movieId,
            'heartbeat_at'  => now(),
            'expires_at'    => now()->addMinutes(self::DEFAULT_EXTEND_MINUTES),
        ]);

        return true;
    }

    /**
     * Bump heartbeat + expiry for an existing lock.
     *
     * Silently no-ops if the session token is not found — callers should
     * treat a missing lock as "session lost", not as an error.
     */
    public function heartbeat(string $sessionToken, int $extendMinutes = self::DEFAULT_EXTEND_MINUTES): void
    {
        PlaybackConcurrentLock::query()
            ->where('session_token', $sessionToken)
            ->update([
                'heartbeat_at' => now(),
                'expires_at'   => now()->addMinutes($extendMinutes),
            ]);
    }

    /**
     * Release the slot held by this session (player closed cleanly).
     */
    public function release(string $sessionToken): void
    {
        PlaybackConcurrentLock::query()
            ->where('session_token', $sessionToken)
            ->delete();
    }

    /**
     * Number of currently-active concurrent streams for a user.
     */
    public function currentCount(User $user): int
    {
        return PlaybackConcurrentLock::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Resolve the max-streams limit for a user from their active plan.
     *
     * Falls back to {@see DEFAULT_MAX_STREAMS} if the user has no
     * active subscription or the plan has no `max_screens` set.
     */
    public function getMaxStreamsForUser(User $user): int
    {
        $plan = $user->currentPlan();

        if ($plan === null) {
            return self::DEFAULT_MAX_STREAMS;
        }

        $max = (int) ($plan->max_screens ?? 0);

        return $max > 0 ? $max : self::DEFAULT_MAX_STREAMS;
    }
}
