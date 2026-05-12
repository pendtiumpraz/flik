<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Models\WatchParty;

/**
 * Authorization for the `presence-watch-party.{roomCode}` presence channel.
 *
 * Allows a user onto the channel only if:
 *   - the room exists and hasn't ended, AND
 *   - the user is either the host OR an active member (left_at IS NULL).
 *
 * Returns the user's "presence data" (id + name) on success — Pusher
 * exposes this to other channel subscribers so the members panel can
 * render names without an extra round-trip.
 *
 * Returning false/null denies the join.
 */
class WatchPartyChannel
{
    /**
     * @return array<string,mixed>|false
     */
    public function join(User $user, string $roomCode): array|false
    {
        $party = WatchParty::where('room_code', $roomCode)->first();

        if (! $party || $party->hasEnded()) {
            return false;
        }

        $isHost = $party->isHost($user->id);

        $isActiveMember = $party->members()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isHost && ! $isActiveMember) {
            return false;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'is_host' => $isHost,
        ];
    }
}
