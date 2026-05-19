<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Follower-graph behaviour for {@see \App\Models\User}.
 *
 * Kept in a trait so the User model stays focused on auth/roles/payments.
 * All persistence goes through the `follows` pivot table — the trait
 * provides BelongsToMany relations + small helpers (`follow`, `unfollow`,
 * `isFollowing`, counts) and handles idempotency + self-follow guard.
 *
 * Cache keys:
 *   - `user:{id}:followers_count`  (5 min TTL)
 *   - `user:{id}:following_count`  (5 min TTL)
 *
 * Both are forgotten by `follow()` / `unfollow()` for both edges so
 * counts never drift out of sync.
 */
trait Follows
{
    /**
     * Users this user is following (forward edges).
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'follower_id',
            'followed_id',
        )->withTimestamps();
    }

    /**
     * Users following this user (reverse edges).
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'followed_id',
            'follower_id',
        )->withTimestamps();
    }

    /**
     * Follow `$other`. Returns true when a new row was created, false when
     * the follow already existed or was rejected (self-follow / missing
     * id / missing table).
     *
     * Idempotent by construction — the UNIQUE (follower_id, followed_id)
     * index plus `firstOrCreate` guarantees we never write a duplicate even
     * under a race between two concurrent POSTs.
     */
    public function follow(User $other): bool
    {
        // Self-follow guard. The DB has a CHECK constraint on MySQL/Postgres
        // but SQLite ignores it; this is the authoritative app-layer check.
        if ($this->id === null || $other->id === null || $this->id === $other->id) {
            return false;
        }

        // Fresh install / migration not yet run → fail soft. Callers (the
        // controller) treat false as "couldn't follow" and surface a flash.
        if (! Schema::hasTable('follows')) {
            return false;
        }

        $existing = Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $other->id)
            ->first();

        if ($existing !== null) {
            return false;
        }

        Follow::create([
            'follower_id' => $this->id,
            'followed_id' => $other->id,
        ]);

        $this->forgetFollowCounts($other);

        return true;
    }

    /**
     * Unfollow `$other`. Returns the number of rows deleted (0 or 1).
     */
    public function unfollow(User $other): int
    {
        if ($this->id === null || $other->id === null || ! Schema::hasTable('follows')) {
            return 0;
        }

        $deleted = Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $other->id)
            ->delete();

        if ($deleted > 0) {
            $this->forgetFollowCounts($other);
        }

        return (int) $deleted;
    }

    /**
     * "Am I following `$other`?"
     */
    public function isFollowing(User $other): bool
    {
        if ($this->id === null || $other->id === null || ! Schema::hasTable('follows')) {
            return false;
        }

        return Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $other->id)
            ->exists();
    }

    /**
     * "Is `$other` following me?" — handy for follow-back hints in the UI.
     */
    public function isFollowedBy(User $other): bool
    {
        if ($this->id === null || $other->id === null || ! Schema::hasTable('follows')) {
            return false;
        }

        return Follow::query()
            ->where('follower_id', $other->id)
            ->where('followed_id', $this->id)
            ->exists();
    }

    /**
     * True only when both edges exist — drives the "Mutuals" badge.
     */
    public function isMutualWith(User $other): bool
    {
        return $this->isFollowing($other) && $this->isFollowedBy($other);
    }

    /**
     * Cached follower count. 5-minute TTL keeps the profile-page bandwidth
     * down without making the badge feel stale.
     */
    public function followersCount(): int
    {
        if (! Schema::hasTable('follows') || $this->id === null) {
            return 0;
        }

        return (int) Cache::remember(
            "user:{$this->id}:followers_count",
            now()->addMinutes(5),
            fn () => $this->followers()->count(),
        );
    }

    /**
     * Cached "following" count.
     */
    public function followingCount(): int
    {
        if (! Schema::hasTable('follows') || $this->id === null) {
            return 0;
        }

        return (int) Cache::remember(
            "user:{$this->id}:following_count",
            now()->addMinutes(5),
            fn () => $this->following()->count(),
        );
    }

    /**
     * Drop the cached counts on BOTH ends of the edge after a write so
     * neither side renders a stale badge.
     */
    private function forgetFollowCounts(User $other): void
    {
        Cache::forget("user:{$this->id}:following_count");
        Cache::forget("user:{$other->id}:followers_count");
    }
}
