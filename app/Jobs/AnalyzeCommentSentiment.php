<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Services\Ai\Tasks\CommentSentimentAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Queueable wrapper around CommentSentimentAnalyzer.
 *
 * Two dispatch modes:
 *   - new self($comment)            — single comment, one AI call
 *   - new self(null, [ids…])        — bulk, one AI call for many comments
 *
 * Routed to the `ai-batch` queue (slow, cost-sensitive lane) — sentiment
 * scoring is not user-blocking and benefits from batching, so it shouldn't
 * compete with realtime tasks like search or chat.
 */
class AnalyzeCommentSentiment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 120;

    /**
     * @param  Comment|null  $comment    single-comment mode
     * @param  array<int, int>  $commentIds  bulk mode (used when $comment is null)
     */
    public function __construct(
        public ?Comment $comment = null,
        public array $commentIds = [],
    ) {
        $this->onQueue('ai-batch');
    }

    public function handle(CommentSentimentAnalyzer $analyzer): void
    {
        if ($this->comment instanceof Comment) {
            $analyzer->analyze($this->comment->loadMissing('movie'));
            return;
        }

        if (empty($this->commentIds)) {
            return;
        }

        /** @var EloquentCollection<int, Comment> $comments */
        $comments = Comment::with('movie')
            ->whereIn('id', $this->commentIds)
            ->get();

        if ($comments->isEmpty()) {
            return;
        }

        $analyzer->analyzeBulk(new Collection($comments->all()));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('AnalyzeCommentSentiment job permanently failed', [
            'comment_id' => $this->comment->id ?? null,
            'bulk_count' => count($this->commentIds),
            'error' => $e->getMessage(),
        ]);
    }
}
