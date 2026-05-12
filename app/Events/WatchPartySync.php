<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time playback / chat fan-out for a Watch Party room.
 *
 * Members subscribe to presence-watch-party.{roomCode}; the host
 * triggers this event on every play/pause/seek/chat, and member
 * clients reconcile their players to (position, is_playing).
 *
 * Actions:
 *  - play   : position = current host position when play resumed
 *  - pause  : position = current host position when paused
 *  - seek   : position = new target (member jumps to it)
 *  - join   : a user joined (presence event also fires)
 *  - leave  : a user left
 *  - chat   : payload includes `message` and `user_name`
 */
class WatchPartySync implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $action;
    public float $position;
    public ?int $userId;
    public int $timestamp;
    public array $extra;

    /**
     * @param  string  $roomCode  watch_parties.room_code
     * @param  string  $action    play|pause|seek|join|leave|chat
     * @param  float   $position  current playback position in seconds
     * @param  int|null $userId   who triggered (host for play/pause/seek)
     * @param  array   $extra     freeform payload (chat: ['message' => ..., 'user_name' => ...])
     */
    public function __construct(
        string $roomCode,
        string $action,
        float $position = 0.0,
        ?int $userId = null,
        array $extra = []
    ) {
        $this->roomCode = $roomCode;
        $this->action = $action;
        $this->position = $position;
        $this->userId = $userId;
        $this->extra = $extra;
        $this->timestamp = now()->getTimestampMs();
    }

    /**
     * @return PresenceChannel
     */
    public function broadcastOn()
    {
        return new PresenceChannel('watch-party.' . $this->roomCode);
    }

    /**
     * Use a stable event name so frontend can `.listen('.watch-party.sync', cb)`.
     */
    public function broadcastAs(): string
    {
        return 'watch-party.sync';
    }

    /**
     * Slim payload — clients only need to know what happened + where.
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'position' => $this->position,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp,
            'extra' => $this->extra,
        ];
    }
}
