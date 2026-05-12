<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DetectSpoilerInComment;
use App\Models\Comment;
use App\Services\Ai\Tasks\SpoilerDetector;
use Illuminate\Console\Command;

/**
 * Backfill AI spoiler detection for existing comments.
 *
 * Examples:
 *   php artisan flik:ai:spoilers                       # only un-checked comments (spoiler_checked_at IS NULL)
 *   php artisan flik:ai:spoilers --all                 # every comment (re-run, overwrites previous verdicts)
 *   php artisan flik:ai:spoilers --queue               # dispatch as background jobs
 *   php artisan flik:ai:spoilers --movie=42            # scope to one movie
 *   php artisan flik:ai:spoilers --limit=200           # cap on number of comments processed
 */
class DetectAllSpoilers extends Command
{
    protected $signature = 'flik:ai:spoilers
        {--all : Re-check every comment, even ones already analysed}
        {--movie= : Restrict to a single movie ID}
        {--queue : Dispatch as background jobs instead of running synchronously}
        {--limit=0 : Cap on number of comments (0 = no limit)}';

    protected $description = 'Backfill AI spoiler detection for existing comments.';

    public function handle(SpoilerDetector $detector): int
    {
        $all = (bool) $this->option('all');
        $movieId = $this->option('movie');
        $useQueue = (bool) $this->option('queue');
        $limit = (int) $this->option('limit');

        $query = Comment::query()->orderBy('id');

        if (! $all) {
            $query->whereNull('spoiler_checked_at');
        }

        if ($movieId !== null && $movieId !== '') {
            $query->where('movie_id', (int) $movieId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No comments match the criteria.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Detecting spoilers across %d comment(s) (%s mode, %s).',
            $total,
            $useQueue ? 'queued' : 'sync',
            $all ? 'all' : 'unchecked only',
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $okCount = 0;
        $failCount = 0;
        $spoilerCount = 0;

        $query->chunkById(100, function ($chunk) use (
            $detector,
            $useQueue,
            $bar,
            &$okCount,
            &$failCount,
            &$spoilerCount,
        ): void {
            foreach ($chunk as $comment) {
                try {
                    if ($useQueue) {
                        DetectSpoilerInComment::dispatch($comment->id);
                        $okCount++;
                    } else {
                        $result = $detector->detect($comment);
                        $okCount++;
                        if ($result['is_spoiler']) {
                            $spoilerCount++;
                        }
                    }
                } catch (\Throwable $e) {
                    $failCount++;
                    $this->line('');
                    $this->error(sprintf('  comment#%d -> %s', $comment->id, $e->getMessage()));
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done. %s: %d, failed: %d%s.',
            $useQueue ? 'Dispatched' : 'Processed',
            $okCount,
            $failCount,
            $useQueue ? '' : sprintf(', flagged as spoiler: %d', $spoilerCount),
        ));

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
