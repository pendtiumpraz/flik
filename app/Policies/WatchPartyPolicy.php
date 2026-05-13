<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WatchParty;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see WatchParty}.
 *
 * Distinct ability matrix:
 *   - view():   host OR an active member may see the room
 *   - update(): host only (max_members, settings)
 *   - chat():   any active member (host included) may broadcast text
 *   - end():    host only — terminates the party
 *   - sync():   host only — controls playback state for everyone
 *   - delete(): admin or host (rare; we soft-end via end() in practice)
 *
 * Admin Gate::before bypass still applies for support intervention.
 */
class WatchPartyPolicy
{
    use HandlesAuthorization;

    /** Browsing the global parties list is admin-only. */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * The host always sees the room. Members see it while their member
     * row is active (no `left_at`). We re-check membership cheaply via
     * the `members` relation — this assumes the caller hasn't already
     * filtered it.
     */
    public function view(User $user, WatchParty $party): bool
    {
        if ($party->isHost($user->id)) {
            return true;
        }

        return $party->members()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WatchParty $party): bool
    {
        return $party->isHost($user->id);
    }

    public function delete(User $user, WatchParty $party): bool
    {
        return $party->isHost($user->id);
    }

    /**
     * Custom ability — only an active member (or the host) may push chat
     * messages into the room. Used by CommentPolicy-style explicit calls
     * (`$this->authorize('chat', $party)`).
     */
    public function chat(User $user, WatchParty $party): bool
    {
        if ($party->hasEnded()) {
            return false;
        }

        if ($party->isHost($user->id)) {
            return true;
        }

        return $party->members()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }

    /** Host-only: ends the room for everyone. */
    public function end(User $user, WatchParty $party): bool
    {
        return $party->isHost($user->id);
    }

    /** Host-only: pushes play/pause/seek events to all members. */
    public function sync(User $user, WatchParty $party): bool
    {
        return $party->isHost($user->id);
    }

    /**
     * Anyone authenticated may attempt to join; capacity + ended-state
     * checks live in the controller because they aren't authorization
     * concerns (they're business state).
     */
    public function join(User $user, WatchParty $party): bool
    {
        return true;
    }

    /**
     * Members leave themselves — never another user. Hosts leaving via
     * this method ends the entire room (handled in controller).
     */
    public function leave(User $user, WatchParty $party): bool
    {
        if ($party->isHost($user->id)) {
            return true;
        }

        return $party->members()
            ->where('user_id', $user->id)
            ->exists();
    }
}
