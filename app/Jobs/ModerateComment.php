<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Services\Ai\Tasks\CommentModerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job: classify a freshly-created Comment and persist moderation verdict.
 *
 * Dispatched by App\Listeners\ModerateNewComment after Comment::created.
 * Runs on the dedicated `ai-realtime` queue so it can be scaled separately
 * from heavier subtitle/embedding jobs.
 */
class ModerateComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of retry attempts before failing.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before a queued attempt is considered stuck.
     */
    public int $timeout = 60;

    public function __construct(
        public int $commentId
    ) {
        $this->onQueue('ai-realtime');
    }

    public function handle(CommentModerator $moderator): void
    {
        $comment = Comment::find($this->commentId);

        if (!$comment) {
            Log::info('ModerateComment: comment vanished before moderation', [
                'comment_id' => $this->commentId,
            ]);
            return;
        }

        $result = $moderator->moderate($comment);

        Log::info('ModerateComment processed', [
            'comment_id' => $comment->id,
            'movie_id' => $comment->movie_id,
            'label' => $result['label'],
            'confidence' => $result['confidence'],
            'status' => $result['status'],
        ]);
    }

    /**
     * Backoff strategy: 10s, 30s, 90s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }
}
