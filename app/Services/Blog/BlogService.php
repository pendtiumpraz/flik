<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Models\BlogPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlogService — encapsulates blog ops that don't belong on the model:
 *   - scheduled-publish cron flip
 *   - related-post computation (shared category + shared movies + recency)
 *   - pure markdown→html (delegates to BlogPost so the model and service
 *     never drift)
 *
 * No external dependencies beyond Laravel's collection helpers — safe to
 * call from web controllers, console commands, and queue workers alike.
 */
class BlogService
{
    /**
     * Flip every scheduled post whose `scheduled_for` is in the past to
     * the published state. Returns the number of rows promoted.
     *
     * Idempotent and queue-safe. The console wrapper runs every 5 min;
     * a late run just promotes whatever's overdue and moves on.
     */
    public function publishScheduled(): int
    {
        $count = 0;

        BlogPost::query()
            ->scheduled()
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->chunkById(50, function ($posts) use (&$count): void {
                foreach ($posts as $post) {
                    /** @var BlogPost $post */
                    try {
                        $post->status = BlogPost::STATUS_PUBLISHED;
                        $post->published_at = $post->published_at ?? $post->scheduled_for ?? now();
                        $post->save();
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('BlogService::publishScheduled — failed to promote post', [
                            'post_id' => $post->id,
                            'slug'    => $post->slug,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    /**
     * Find related posts using a 3-signal scoring blend:
     *   - shared category (strongest)
     *   - shared movie mentions (mid)
     *   - recency tiebreak (newest first)
     *
     * Excludes the source post itself + non-published rows.
     *
     * @return Collection<int, BlogPost>
     */
    public function relatedPosts(BlogPost $post, int $limit = 4): Collection
    {
        $limit = max(1, $limit);

        // Movie IDs referenced by the source post — drives the shared-movie signal.
        $movieIds = $post->movies()->pluck('movies.id')->all();

        $query = BlogPost::query()
            ->published()
            ->where('id', '!=', $post->id);

        // Score columns are SQL-side so we can ORDER BY in a single query.
        // Use raw expressions guarded against missing pivot rows.
        $score = DB::raw(
            '('
            . ($post->category_id ? 'CASE WHEN category_id = ' . (int) $post->category_id . ' THEN 3 ELSE 0 END' : '0')
            . ' + '
            . (!empty($movieIds)
                ? '(SELECT COUNT(*) FROM blog_post_movie bpm '
                    . 'WHERE bpm.blog_post_id = blog_posts.id '
                    . 'AND bpm.movie_id IN (' . implode(',', array_map('intval', $movieIds)) . '))'
                : '0')
            . ') as relevance_score'
        );

        return $query
            ->select('blog_posts.*')
            ->selectRaw((string) $score)
            ->orderByDesc('relevance_score')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Pure markdown → html. Delegates to BlogPost::renderMarkdown so the
     * model mutator and any external callers always agree on the output.
     */
    public function markdownToHtml(string $md): string
    {
        return BlogPost::renderMarkdown($md);
    }
}
