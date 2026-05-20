<?php

namespace App\Events;

use App\Models\Comment;
use App\Models\CommentReaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by {@see \App\Observers\CommentReactionObserver} after a
 * reaction is created/updated/deleted. Broadcasts the fresh count map
 * to every viewer subscribed to the parent movie's comment channel so
 * pill counts update live without polling.
 *
 * Broadcasting is gated by env: when BROADCAST_DRIVER=null (default for
 * fresh installs without Pusher creds) the {@see dispatchIfBroadcastable}
 * helper silently skips the dispatch entirely. Callers should ALWAYS go
 * through that helper rather than calling ::dispatch() directly so the
 * "no Pusher → no exception" graceful-degrade behaviour is preserved.
 */
class CommentReactionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $commentId;

    public int $movieId;

    /** @var array<string, int> */
    public array $counts;

    public int $total;

    public ?int $byUserId;

    public string $reaction;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        int $commentId,
        int $movieId,
        array $counts,
        int $total,
        ?int $byUserId,
        string $reaction
    ) {
        $this->commentId = $commentId;
        $this->movieId = $movieId;
        $this->counts = $counts;
        $this->total = $total;
        $this->byUserId = $byUserId;
        $this->reaction = $reaction;
    }

    /**
     * Graceful entry point. No-ops when broadcasting is disabled so the
     * reaction toggle never raises if Pusher is down/unset.
     */
    public static function dispatchIfBroadcastable(Comment $comment, CommentReaction $reaction): void
    {
        // 'null' is Laravel's "broadcasting disabled" driver. The
        // legacy `BROADCAST_DRIVER` env name and the modern
        // `BROADCAST_CONNECTION` are both honoured by core, so we
        // check the resolved config value rather than env() directly.
        $driver = (string) (config('broadcasting.default') ?: 'null');
        if ($driver === 'null' || $driver === '') {
            return;
        }

        $counts = $comment->reactionsByType();
        $total = array_sum($counts);

        event(new self(
            commentId: (int) $comment->id,
            movieId: (int) $comment->movie_id,
            counts: $counts,
            total: $total,
            byUserId: (int) $reaction->user_id,
            reaction: (string) $reaction->reaction,
        ));
    }

    /**
     * Private channel keyed by movie so a single Pusher subscription
     * picks up reaction updates for every comment on the page.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('movie.' . $this->movieId . '.comments')];
    }

    public function broadcastAs(): string
    {
        return 'comment.reaction.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'comment_id' => $this->commentId,
            'counts' => $this->counts,
            'total' => $this->total,
            'by_user_id' => $this->byUserId,
            'reaction' => $this->reaction,
        ];
    }
}
