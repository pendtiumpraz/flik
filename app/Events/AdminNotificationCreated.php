<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AdminNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AdminNotificationCreated
 * --------------------------------------------------------------------------
 * Realtime fan-out for {@see AdminNotification}. Implements
 * {@see ShouldBroadcastNow} (not the queued ShouldBroadcast) because the
 * latency contract for the bell widget is sub-second — queueing the
 * broadcast adds dispatcher-pickup jitter the UI would feel.
 *
 * Channel naming contract (peer-NOTIF #2 + #3 rely on this VERBATIM):
 *
 *     admin-notifications.all-admins      ← when audience == 'all_admins'
 *     admin-notifications.{roleName}      ← one channel per audience role
 *
 * Event name on the wire:   `created`
 *
 * Payload (JSON):
 *     {id, category, title, message, severity, meta, action_url, created_at}
 *
 * Broadcast driver fallback: if BROADCAST_DRIVER=null (e.g. local dev
 * without Pusher) Laravel silently drops the broadcast — the DB row is
 * already persisted so the polling endpoint covers the gap.
 */
class AdminNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AdminNotification $notification,
    ) {
    }

    /**
     * Fan-out: one private channel per audience role, or the special
     * `all-admins` wildcard channel when audience == `all_admins`.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $audience = $this->notification->audience;

        if ($audience === AdminNotification::AUDIENCE_ALL) {
            return [new PrivateChannel('admin-notifications.all-admins')];
        }

        $roles = array_filter(array_map('trim', explode(',', (string) $audience)));

        return array_values(array_map(
            static fn (string $role) => new PrivateChannel('admin-notifications.' . $role),
            $roles,
        ));
    }

    public function broadcastAs(): string
    {
        return 'created';
    }

    /**
     * Wire payload — peer agents key their JS listeners off these fields.
     * Keep them in sync with the contract section in the model docblock.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $n = $this->notification;

        return [
            'id'         => $n->id,
            'category'   => $n->category,
            'title'      => $n->title,
            'message'    => $n->message,
            'severity'   => $n->severity,
            'meta'       => $n->meta,
            'action_url' => $n->action_url,
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }
}
