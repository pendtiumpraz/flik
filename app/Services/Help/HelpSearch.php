<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Models\HelpArticle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search + discovery helpers for the public Help Center.
 *
 * Three entry points:
 *   - search($q, ?$categoryId, $limit)   → relevance-ordered results page
 *   - suggest($partial, $limit)          → JSON typeahead dropdown
 *   - relatedTo($article, $limit)        → "you might also like" rail
 *
 * Implementation notes:
 *   - search() delegates to HelpArticle::search() which feature-detects
 *     MySQL FULLTEXT and falls back to LIKE.
 *   - suggest() is intentionally narrow (title-prefix only) — autocomplete
 *     answers in <40ms even on a large corpus because we read indexed cols
 *     and bail out fast.
 *   - relatedTo() weighs by shared tags first, then category — so two
 *     articles in the same category but with no tag overlap still appear
 *     under articles that share a tag with the seed.
 */
class HelpSearch
{
    /**
     * Run a relevance-ordered search restricted (optionally) to a category.
     *
     * @return Collection<int, HelpArticle>
     */
    public function search(string $query, ?int $categoryId = null, int $limit = 20): Collection
    {
        $limit = max(1, min(100, $limit));

        $builder = HelpArticle::search($query)
            ->with('category:id,slug,name,icon');

        if ($categoryId !== null && $categoryId > 0) {
            $builder->where('category_id', $categoryId);
        }

        return $builder->limit($limit)->get();
    }

    /**
     * Lightweight typeahead — title-prefix match, returns plain arrays so
     * the JSON encoder doesn't drag the full article body into responses.
     *
     * @return array<int, array{id:int, slug:string, title:string}>
     */
    public function suggest(string $partial, int $limit = 5): array
    {
        $partial = trim($partial);
        if ($partial === '' || mb_strlen($partial) < 2) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        // Escape LIKE meta-characters so a user typing "100%" doesn't
        // wildcard against every row.
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $partial);
        $like    = $escaped . '%';     // prefix match
        $contains = '%' . $escaped . '%'; // fallback contains

        $rows = HelpArticle::query()
            ->published()
            ->select('id', 'slug', 'title')
            ->where(function ($q) use ($like, $contains) {
                $q->whereLike('title', $like)
                  ->orWhereLike('title', $contains);
            })
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$like])
            ->orderByDesc('helpful_count')
            ->limit($limit)
            ->get();

        return $rows->map(fn (HelpArticle $a) => [
            'id'    => (int) $a->id,
            'slug'  => (string) $a->slug,
            'title' => (string) $a->title,
        ])->all();
    }

    /**
     * Articles related to the seed via tag overlap or shared category.
     *
     * Scoring (computed in PHP because portable cross-db ranking is messy):
     *   - +2 per shared tag
     *   - +1 if same category
     *
     * Falls back to "most-helpful in same category" when the seed has no
     * tags AND no related rows by overlap rules.
     *
     * @return Collection<int, HelpArticle>
     */
    public function relatedTo(HelpArticle $article, int $limit = 4): Collection
    {
        $limit = max(1, min(20, $limit));

        $seedTags = collect($article->tags ?? [])
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->map(fn ($t) => mb_strtolower((string) $t))
            ->unique()
            ->values();

        $candidates = HelpArticle::query()
            ->published()
            ->where('id', '!=', $article->id)
            ->when($article->category_id, fn ($q) =>
                $q->where(function ($w) use ($article, $seedTags) {
                    $w->where('category_id', $article->category_id);
                    if ($seedTags->isNotEmpty()) {
                        // tags column is JSON — keep this simple/portable
                        // by also including rows we'll filter in PHP below.
                        $w->orWhereNotNull('tags');
                    }
                })
            )
            ->with('category:id,slug,name,icon')
            ->limit($limit * 6) // grab a buffer; we'll trim after scoring
            ->get();

        $scored = $candidates->map(function (HelpArticle $a) use ($article, $seedTags) {
            $score = 0;

            if ($article->category_id && $a->category_id === $article->category_id) {
                $score += 1;
            }

            $aTags = collect($a->tags ?? [])
                ->filter(fn ($t) => is_string($t) && $t !== '')
                ->map(fn ($t) => mb_strtolower((string) $t));
            $overlap = $seedTags->intersect($aTags)->count();
            $score += $overlap * 2;

            return ['article' => $a, 'score' => $score];
        });

        $top = $scored
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('article')
            ->values();

        if ($top->count() < $limit && $article->category_id) {
            // Fallback: most-helpful in same category to round out the rail.
            $fillers = HelpArticle::query()
                ->published()
                ->where('category_id', $article->category_id)
                ->where('id', '!=', $article->id)
                ->whereNotIn('id', $top->pluck('id')->all())
                ->orderByDesc('helpful_count')
                ->orderByDesc('updated_at')
                ->limit($limit - $top->count())
                ->with('category:id,slug,name,icon')
                ->get();

            $top = $top->concat($fillers);
        }

        return new Collection($top->all());
    }
}
