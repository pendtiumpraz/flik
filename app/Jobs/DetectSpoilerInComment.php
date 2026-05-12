<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Comment;
use App\Services\Ai\Tasks\SpoilerDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job: run AI spoiler detection against a freshly-created comment.
 *
 * Dispatched by App\Listeners\DetectSpoilerOnComment after Comment::created.
 * Runs on the dedicated `ai-realtime` queue alongside the moderation pipeline
 * so spoiler flags surface fast enough for the next page render.
 */
class DetectSpoilerInComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of retry attempts before failing.
     */
    public int $tries = 2;

    /**
     * Seconds to wait before a queued attempt is considered stuck.
     */
    public int $timeout = 60;

    public function __construct(
        public int $commentId,
    ) {
        $this->onQueue('ai-realtime');
    }

    public function handle(SpoilerDetector $detector): void
    {
        $comment = Comment::find($this->commentId);

        if (! $comment) {
            Log::info('DetectSpoilerInComment: comment vanished before detection', [
                'comment_id' => $this->commentId,
            ]);

            return;
        }

        $result = $detector->detect($comment);

        Log::info('DetectSpoilerInComment processed', [
            'comment_id' => $comment->id,
            'movie_id' => $comment->movie_id,
            'is_spoiler' => $result['is_spoiler'],
            'confidence' => $result['confidence'],
        ]);
    }

    /**
     * Backoff strategy: 10s, 30s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }
}
