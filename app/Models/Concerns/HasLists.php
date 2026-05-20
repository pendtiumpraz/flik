<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\UserList;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adds user-curated list ownership + subscription behaviour to {@see \App\Models\User}.
 *
 * Kept in a trait so the User model file stays narrow and focused on
 * auth/roles/payments. The three relations cover the three use cases:
 *
 *   - {@see HasLists::lists()} — every list this user owns, including
 *     private ones. Use on owner-only screens (/lists/mine).
 *   - {@see HasLists::publicLists()} — public-only filter for someone
 *     ELSE's profile page (`/u/{username}` and `/u/{username}/lists`).
 *   - {@see HasLists::followedLists()} — lists the user has subscribed
 *     to via the {@see \App\Models\UserListFollower} edge.
 */
trait HasLists
{
    /**
     * All lists owned by this user (any visibility).
     */
    public function lists(): HasMany
    {
        return $this->hasMany(UserList::class)->latest();
    }

    /**
     * Public-only lists for display on someone else's profile. Filtered at
     * the relation level so callers don't have to remember the scope.
     */
    public function publicLists(): HasMany
    {
        return $this->hasMany(UserList::class)
            ->where('visibility', UserList::VISIBILITY_PUBLIC)
            ->latest();
    }

    /**
     * Lists this user has chosen to follow (subscribe to updates from).
     * Distinct from `lists()` which is ownership.
     */
    public function followedLists(): BelongsToMany
    {
        return $this->belongsToMany(UserList::class, 'user_list_followers')
            ->withTimestamps();
    }
}
