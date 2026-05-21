<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AnalyzeCommentSentiment;
use App\Models\Comment;
use App\Services\Ai\Tasks\CommentSentimentAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill sentiment scores for historical comments.
 *
 * Pre-listener-wiring (FIX #7), every comment created before today has
 * NULL `sentiment` / `sentiment_score`. Walks `comments` in chunks ordered
 * by id desc (so the most-recently-relevant rows process first) and
 * dispatches the AnalyzeCommentSentiment job in CHUNK_SIZE batches.
 *
 * Defaults match the analyzer: bulk-mode chunks of 40 comments per AI
 * call, so a --limit=500 run = roughly 13 LLM calls on the ai-batch queue.
 *
 * Usage:
 *   php artisan flik:ai:sentiment-backfill           # 500 comments, queued
 *   php artisan flik:ai:sentiment-backfill --limit=2000
 *   php artisan flik:ai:sentiment-backfill --sync    # run inline (no queue)
 *   php artisan flik:ai:sentiment-backfill --redo    # also re-process rows that already have a sentiment
 */
final class SentimentBackfill extends Command
{
    protected $signature = 'flik:ai:sentiment-backfill
                            {--limit=500 : Max comments to process this run}
                            {--sync : Run analyzer inline instead of queuing}
                            {--redo : Re-process comments that already have sentiment_analyzed_at}';

    protected $description = 'Backfill sentiment scores for historical comments (chunks of 40 per AI call).';

    public function handle(CommentSentimentAnalyzer $analyzer): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sync  = (bool) $this->option('sync');
        $redo  = (bool) $this->option('redo');

        $query = Comment::query()
            ->when(! $redo, fn ($q) => $q->whereNull('sentiment_analyzed_at'))
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit($limit);

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No comments to backfill — everything already has sentiment.');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$total} comments ({$limit} cap)" . ($sync ? ' [sync]' : ' [queued]'));

        $processed = 0;
        $query->select(['id'])->chunkById(CommentSentimentAnalyzer::BULK_CHUNK_SIZE, function (Collection $rows) use (&$processed, $sync, $analyzer) {
            $ids = $rows->pluck('id')->all();

            if ($sync) {
                $comments = Comment::with('movie')->whereIn('id', $ids)->get();
                $analyzer->analyzeBulk(new Collection($comments->all()));
            } else {
                AnalyzeCommentSentiment::dispatch(null, $ids);
            }

            $processed += count($ids);
            $this->line("  · dispatched chunk of " . count($ids) . " (total {$processed})");
        }, 'id', 'id');

        $this->info("Done. {$processed} comments " . ($sync ? 'processed inline.' : 'queued to ai-batch.'));
        return self::SUCCESS;
    }
}
