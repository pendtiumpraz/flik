<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see Watchlist} pivot rows.
 *
 * Watchlists are strictly per-user — no cross-user visibility, even for
 * read. Admin Gate::before bypass still applies for support tooling.
 */
class WatchlistPolicy
{
    use HandlesAuthorization;

    /** Listing watchlists is always scoped to the authenticated user. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Watchlist $watchlist): bool
    {
        return (int) $watchlist->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Watchlist $watchlist): bool
    {
        return (int) $watchlist->user_id === (int) $user->id;
    }

    public function delete(User $user, Watchlist $watchlist): bool
    {
        return (int) $watchlist->user_id === (int) $user->id;
    }
}
