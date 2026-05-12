<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SmartWatchlistPrioritizer (Feature I8)
 *
 * Re-orders the user's Watchlist so the films they're most likely to enjoy
 * RIGHT NOW float to the top.
 *
 * Pipeline:
 *   1. Build a viewing-context profile from the user's recent WatchHistory:
 *        - top genres (recency-weighted)
 *        - mood pattern (if movies have ai_tags)
 *        - active time-of-day buckets (morning/afternoon/evening/late_night)
 *   2. Local heuristic score per watchlist item:
 *        - genre overlap with recent watching
 *        - mood overlap (ai_tags ∩ recent moods)
 *        - trending / popularity bonus
 *        - time-of-day fit (e.g. action/thriller in evening, family/comedy in afternoon)
 *   3. Optional AI re-rank of the top 10 by slug; AI failure → trust local score.
 *
 * Returns a Collection<int, Watchlist> in priority order (most-recommended first).
 * Each Watchlist item has its movie eager-loaded with genres.
 */
class SmartWatchlistPrioritizer
{
    /**
     * Time-of-day → preferred genre slugs (lowercase).
     * Used as a soft bonus in scoring.
     */
    protected const TIME_GENRE_BONUS = [
        'morning'    => ['family', 'animation', 'documentary', 'comedy'],
        'afternoon'  => ['adventure', 'comedy', 'family', 'animation'],
        'evening'    => ['action', 'thriller', 'drama', 'science-fiction', 'crime'],
        'late_night' => ['horror', 'thriller', 'mystery', 'drama', 'crime'],
    ];

    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $kb,
    ) {}

    /**
     * Prioritize the given user's watchlist.
     *
     * @return Collection<int, Watchlist>  ordered: highest priority first
     */
    public function prioritize(User $user): Collection
    {
        // 1. Load watchlist (eager-load movie + its genres for scoring + display).
        $watchlist = Watchlist::with(['movie.genres'])
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn (Watchlist $w) => $w->movie !== null)
            ->values();

        if ($watchlist->isEmpty()) {
            return collect();
        }

        // 2. Build a viewing-context profile from recent watch history.
        $context = $this->buildUserContext($user);

        // 3. Local heuristic scoring.
        $scored = $watchlist->map(function (Watchlist $w) use ($context) {
            $w->_priority_score = $this->scoreMovie($w->movie, $context);
            return $w;
        })->sortByDesc('_priority_score')->values();

        // 4. Optional AI re-rank on the top 10.
        $top = $scored->take(10);
        $reranked = $this->aiRerank($top, $context);

        if ($reranked !== null && $reranked->isNotEmpty()) {
            // Append the rest of the watchlist (positions 11+) after the re-ranked top.
            $tail = $scored->slice(10)->values();
            return $reranked->concat($tail)->values();
        }

        return $scored;
    }

    // ───────────────────────────────────────────────────────────────────
    // CONTEXT BUILDING
    // ───────────────────────────────────────────────────────────────────

    /**
     * Build a profile of what the user has been watching lately.
     *
     * @return array{
     *     top_genres: array<string,float>,     // genre name (lower) => weight
     *     top_moods: array<string,float>,      // mood tag (lower) => weight
     *     time_buckets: array<string,int>,     // bucket => count
     *     dominant_time: string,               // e.g. 'evening'
     *     trending_titles: list<string>        // currently-trending movie titles
     * }
     */
    protected function buildUserContext(User $user): array
    {
        $topGenres   = [];
        $topMoods    = [];
        $timeBuckets = ['morning' => 0, 'afternoon' => 0, 'evening' => 0, 'late_night' => 0];

        $hasAiTagsColumn = $this->aiTagsColumnExists();

        $recent = WatchHistory::with(['movie.genres'])
            ->where('user_id', $user->id)
            ->orderByDesc('last_watched_at')
            ->limit(40)
            ->get();

        $now = now();

        foreach ($recent as $i => $h) {
            if (!$h->movie) continue;

            // Recency weight: most recent gets ×1.0, decays linearly.
            $recencyWeight = max(0.2, 1.0 - ($i / 40.0));

            foreach ($h->movie->genres as $g) {
                $key = mb_strtolower($g->name);
                $topGenres[$key] = ($topGenres[$key] ?? 0.0) + $recencyWeight;
            }

            if ($hasAiTagsColumn && is_array($h->movie->ai_tags ?? null)) {
                foreach ($h->movie->ai_tags as $tag) {
                    if (!is_string($tag)) continue;
                    $key = mb_strtolower(trim($tag));
                    if ($key === '') continue;
                    $topMoods[$key] = ($topMoods[$key] ?? 0.0) + $recencyWeight;
                }
            }

            // Bucket the watch hour.
            if ($h->last_watched_at) {
                $hour = (int) $h->last_watched_at->format('G');
                $timeBuckets[$this->bucketForHour($hour)]++;
            }
        }

        arsort($topGenres);
        arsort($topMoods);

        // Determine the user's currently-active bucket = dominant from now (current hour).
        $currentBucket = $this->bucketForHour((int) $now->format('G'));

        // If user has actual history, pick their dominant historical bucket if stronger.
        $historicalDominant = array_keys($timeBuckets, max($timeBuckets))[0] ?? $currentBucket;
        $dominantTime = max($timeBuckets) > 0 ? $historicalDominant : $currentBucket;

        // Trending titles (used only for AI prompt context).
        $trendingTitles = Movie::trending()
            ->limit(8)
            ->pluck('title')
            ->all();

        return [
            'top_genres'      => array_slice($topGenres, 0, 8, true),
            'top_moods'       => array_slice($topMoods, 0, 6, true),
            'time_buckets'    => $timeBuckets,
            'dominant_time'   => $dominantTime,
            'trending_titles' => $trendingTitles,
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    // LOCAL SCORING
    // ───────────────────────────────────────────────────────────────────

    /**
     * Score a single watchlist movie against the user context.
     */
    protected function scoreMovie(Movie $movie, array $context): float
    {
        $score = 0.0;

        // (a) Genre overlap with recent watching (weighted by recency weights).
        $movieGenres = $movie->genres->map(fn ($g) => mb_strtolower($g->name))->all();
        foreach ($movieGenres as $g) {
            if (isset($context['top_genres'][$g])) {
                $score += $context['top_genres'][$g] * 2.0;
            }
        }

        // (b) Mood fit via ai_tags overlap.
        if (is_array($movie->ai_tags ?? null) && !empty($context['top_moods'])) {
            foreach ($movie->ai_tags as $tag) {
                if (!is_string($tag)) continue;
                $key = mb_strtolower(trim($tag));
                if (isset($context['top_moods'][$key])) {
                    $score += $context['top_moods'][$key] * 1.5;
                }
            }
        }

        // (c) Trending bonus.
        if ($movie->is_trending) {
            $score += 2.0;
        }

        // (d) Popularity (capped so it never dominates personal signals).
        $score += min(2.0, ((float) $movie->popularity) / 100.0);

        // (e) Time-of-day fit.
        $bucketGenres = self::TIME_GENRE_BONUS[$context['dominant_time']] ?? [];
        if (!empty($bucketGenres)) {
            $movieGenreSlugs = $movie->genres->map(fn ($g) => mb_strtolower($g->slug ?? $g->name))->all();
            $bucketHits = count(array_intersect($bucketGenres, $movieGenreSlugs));
            $score += $bucketHits * 1.0;
        }

        // (f) Vote average gentle bonus.
        $score += ((float) $movie->vote_average) / 10.0 * 0.8;

        return $score;
    }

    // ───────────────────────────────────────────────────────────────────
    // AI RE-RANK
    // ───────────────────────────────────────────────────────────────────

    /**
     * Ask the AI to re-order the top candidates by current-best fit.
     *
     * @param  Collection<int, Watchlist>  $top
     * @return Collection<int, Watchlist>|null
     */
    protected function aiRerank(Collection $top, array $context): ?Collection
    {
        if ($top->isEmpty()) {
            return null;
        }

        $payload = $top->map(function (Watchlist $w) {
            $m = $w->movie;
            return [
                'slug'    => $m->slug,
                'title'   => $m->title,
                'year'    => $m->release_date?->format('Y'),
                'genres'  => $m->genres->pluck('name')->take(4)->values()->all(),
                'rating'  => $m->vote_average !== null ? round((float) $m->vote_average, 1) : null,
                'tags'    => is_array($m->ai_tags ?? null) ? array_slice($m->ai_tags, 0, 4) : [],
                'trending'=> (bool) $m->is_trending,
            ];
        })->values()->all();

        $genresStr = implode(', ', array_keys($context['top_genres'])) ?: 'no recent signal';
        $moodsStr  = implode(', ', array_keys($context['top_moods']))  ?: 'no mood signal';
        $timeStr   = $context['dominant_time'];

        $userPrompt = "User's watchlist (" . count($payload) . " films):\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nUser's recent viewing context:\n"
            . "- Top genres: {$genresStr}\n"
            . "- Mood patterns: {$moodsStr}\n"
            . "- Most active time: {$timeStr}\n\n"
            . "Re-order this watchlist by which films the user will most enjoy NOW based on recent viewing.\n"
            . "Output JSON array of slugs in priority order (highest first). "
            . "Use ONLY slugs from the input. No prose, no markdown, no code fences.";

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => 'You are a film curator. Respond with strict JSON array of slugs only.'],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'max_tokens'  => 400,
                'temperature' => 0.4,
            ]);

            $content = trim((string) ($response['content'] ?? ''));
            if ($content === '') return null;

            $slugs = $this->extractSlugs($content);
            if (empty($slugs)) return null;

            // Re-order watchlist items by AI slug order; preserve unmatched at the end of this slice.
            $bySlug = $top->keyBy(fn (Watchlist $w) => $w->movie->slug);
            $ordered = collect();
            $seen = [];
            foreach ($slugs as $slug) {
                if ($bySlug->has($slug) && !isset($seen[$slug])) {
                    $ordered->push($bySlug->get($slug));
                    $seen[$slug] = true;
                }
            }
            // Append any candidates the AI dropped (don't lose user's watchlist items).
            foreach ($bySlug as $slug => $w) {
                if (!isset($seen[$slug])) {
                    $ordered->push($w);
                }
            }

            return $ordered;
        } catch (\Throwable $e) {
            Log::warning('SmartWatchlistPrioritizer: AI re-rank failed', [
                'user_watchlist_size' => $top->count(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // HELPERS
    // ───────────────────────────────────────────────────────────────────

    /**
     * Bucket an hour-of-day (0-23) into morning/afternoon/evening/late_night.
     */
    protected function bucketForHour(int $hour): string
    {
        return match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default                   => 'late_night',
        };
    }

    /**
     * Pull a JSON array of slugs from AI output (tolerates code fences and stray prose).
     *
     * @return list<string>
     */
    protected function extractSlugs(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/im', '', $content) ?? $content;
        $content = trim($content);

        if (!str_starts_with($content, '[')) {
            if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return [];

        return array_values(array_filter(
            array_map(fn ($s) => is_string($s) ? trim($s) : null, $decoded),
            fn ($s) => is_string($s) && $s !== '',
        ));
    }

    /**
     * Cached probe for the optional `ai_tags` column. Degrades gracefully if absent.
     */
    protected function aiTagsColumnExists(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cached = Schema::hasColumn('movies', 'ai_tags');
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }
}
