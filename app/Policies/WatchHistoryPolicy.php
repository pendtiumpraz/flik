<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see WatchHistory}.
 *
 * Per-user resume positions. Treated as private viewing data — never
 * leak across accounts. Admin Gate::before bypass still applies.
 */
class WatchHistoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WatchHistory $history): bool
    {
        return (int) $history->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WatchHistory $history): bool
    {
        return (int) $history->user_id === (int) $user->id;
    }

    public function delete(User $user, WatchHistory $history): bool
    {
        return (int) $history->user_id === (int) $user->id;
    }
}
