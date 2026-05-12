<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight chat fan-out event for a Watch Party room.
 *
 * Kept separate from {@see WatchPartySync} because chat traffic has
 * a very different shape (bursty, no playback state) and downstream
 * consumers (analytics, moderation) may want to subscribe to chat
 * without seeing every play/pause/seek tick.
 *
 * Channel matches WatchPartySync so a single Pusher subscription is
 * enough on the frontend; events are differentiated via broadcastAs().
 */
class WatchPartyChat implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $message;
    public string $userName;
    public ?int $userId;
    public int $timestamp;

    public function __construct(
        string $roomCode,
        string $message,
        string $userName,
        ?int $userId = null
    ) {
        $this->roomCode = $roomCode;
        $this->message = $message;
        $this->userName = $userName;
        $this->userId = $userId;
        $this->timestamp = now()->getTimestampMs();
    }

    /**
     * Broadcast on the same presence channel as WatchPartySync so a
     * single subscription on the frontend covers both event types.
     *
     * @return PresenceChannel
     */
    public function broadcastOn()
    {
        return new PresenceChannel('watch-party.' . $this->roomCode);
    }

    /**
     * Stable event name for `.listen('.watch-party.chat', cb)`.
     */
    public function broadcastAs(): string
    {
        return 'watch-party.chat';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'user_name' => $this->userName,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp,
        ];
    }
}
