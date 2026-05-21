<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\Movie;
use App\Models\MovieView;
use App\Models\TrendingMovie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * TrendingAggregator
 * --------------------------------------------------------------------------
 * Sweeps `movie_views` for a given time window, scores each movie, and
 * rewrites the `trending_movies` cache. Truncate-and-insert per window
 * (one row per movie) — the table is owned by this service. Don't
 * UPDATE rows from anywhere else.
 *
 * Score formula
 * -------------
 *   raw   = view_count + (unique_viewer_count * 2) + recency_boost
 *   final = raw * featured_penalty
 *
 *   recency_boost: 1.0 to 0.0 linearly across the window — newer views
 *     in the window count for slightly more than views at the edge.
 *     Anchored to the window's end so a "hot right now" film with all
 *     views in the last 10 minutes outscores a film with the same view
 *     count spread evenly.
 *
 *   featured_penalty: 0.5 when `movies.is_popular = true`. Rationale —
 *     we don't have a true `is_featured` flag yet (that ships with the
 *     editorial-promotion epic). `is_popular` is the closest proxy:
 *     popular movies already get top-billing in <x-home.hero-slider>
 *     and Trending Now (popularity-sorted), so they don't need a
 *     second free pass through organic trending too. Half-weighting
 *     (not full exclusion) keeps a genuinely-hot popular film visible
 *     while letting organic upstarts surface.
 *
 * Empty-table behaviour
 * ---------------------
 * If no MovieView rows exist in the window, we WIPE the cache for that
 * window (so a stale "yesterday's hot list" doesn't keep showing 24h
 * after the last view) and return cleanly. The shelf component handles
 * the empty list with a "no data yet" treatment.
 */
class TrendingAggregator
{
    /** Supported windows + their lookback duration (in minutes). */
    public const WINDOWS = [
        '1h' => 60,
        '24h' => 60 * 24,
        '7d' => 60 * 24 * 7,
        '30d' => 60 * 24 * 30,
    ];

    /** Cap rows kept per window — UI shows top 12, page shows top 50; 100 leaves headroom. */
    private const TOP_N = 100;

    /** Half-weight multiplier applied to `is_popular` movies (see class docblock). */
    private const FEATURED_PENALTY = 0.5;

    /**
     * Recompute one window. The window MUST be one of the keys of
     * self::WINDOWS or this throws — typo'd schedules should fail loud
     * rather than silently writing nothing.
     */
    public function compute(string $window): void
    {
        if (! isset(self::WINDOWS[$window])) {
            throw new InvalidArgumentException("Unknown trending window: {$window}");
        }

        $minutes = self::WINDOWS[$window];
        $now = CarbonImmutable::now();
        $from = $now->subMinutes($minutes);

        try {
            // ── Aggregate raw counts per movie ──────────────────────
            //
            // We do COUNT(*) and COUNT(DISTINCT user_id) in one pass.
            // Anonymous views (user_id IS NULL) collapse to a single
            // "0" unique, which matches the intent: anonymous traffic
            // contributes to view_count but can't manufacture unique
            // viewers without an account. Distinct ip_hash would be a
            // future addition once we trust the pepper rotation story.
            $rows = MovieView::query()
                ->selectRaw('movie_id')
                ->selectRaw('COUNT(*) as view_count')
                ->selectRaw('COUNT(DISTINCT user_id) as unique_viewer_count')
                ->selectRaw('MAX(viewed_at) as last_viewed_at')
                ->where('viewed_at', '>=', $from)
                ->groupBy('movie_id')
                ->get();

            if ($rows->isEmpty()) {
                // No traffic in the window → blank the cache cleanly.
                // Without this, a previously-populated window would
                // keep serving stale rows long after the source
                // events aged out.
                TrendingMovie::where('window', $window)->delete();
                Log::info('TrendingAggregator: no views in window — cleared cache', [
                    'window' => $window,
                ]);

                return;
            }

            // Bulk-fetch the is_popular flag for penalty calculation.
            // Avoids N+1 lookups inside the scoring loop.
            $movieIds = $rows->pluck('movie_id')->all();
            $popularFlags = Movie::query()
                ->whereIn('id', $movieIds)
                ->pluck('is_popular', 'id');

            $windowSeconds = max(1, $minutes * 60);

            // ── Score each candidate ────────────────────────────────
            $scored = $rows->map(function ($row) use ($popularFlags, $now, $windowSeconds) {
                $views = (int) $row->view_count;
                $uniques = (int) $row->unique_viewer_count;

                // Recency boost — linear decay from 1.0 (just viewed)
                // to 0.0 (viewed exactly at the window boundary). A
                // film with its activity concentrated near `now`
                // beats one with the same volume spread evenly.
                //
                // Carbon 3 made diffInSeconds() SIGNED by default, so
                // `$now->diffInSeconds($lastViewedAt)` returns a negative
                // number when `$lastViewedAt` is in the past (the only
                // case we see in production). `max(0, negative)` then
                // collapses to 0 → every recency boost evaluates to 1.0
                // and the term loses all discrimination. Avoid the
                // Carbon API entirely and compute the timestamp delta
                // directly so the result is Carbon-version-agnostic.
                $lastViewedAt = $row->last_viewed_at
                    ? CarbonImmutable::parse($row->last_viewed_at)
                    : $now;
                $ageSeconds = max(0, $now->getTimestamp() - $lastViewedAt->getTimestamp());
                $recencyBoost = 1.0 - min(1.0, $ageSeconds / $windowSeconds);

                $raw = $views + ($uniques * 2) + $recencyBoost;

                // Featured-vs-organic split: half-weight is_popular.
                $isPopular = (bool) ($popularFlags[$row->movie_id] ?? false);
                $finalScore = $isPopular ? ($raw * self::FEATURED_PENALTY) : $raw;

                return [
                    'movie_id' => (int) $row->movie_id,
                    'view_count' => $views,
                    'unique_viewer_count' => $uniques,
                    'score' => round($finalScore, 4),
                ];
            })
                ->sortByDesc('score')
                ->values()
                ->take(self::TOP_N);

            // ── Rewrite the cache atomically ────────────────────────
            DB::transaction(function () use ($window, $scored, $now) {
                // Truncate just this window's slice — other windows
                // (1h vs 24h etc.) must be unaffected so they can run
                // on independent schedules.
                TrendingMovie::where('window', $window)->delete();

                if ($scored->isEmpty()) {
                    return;
                }

                $insert = $scored->values()->map(function ($row, $idx) use ($window, $now) {
                    return [
                        'movie_id' => $row['movie_id'],
                        'window' => $window,
                        'view_count' => $row['view_count'],
                        'unique_viewer_count' => $row['unique_viewer_count'],
                        'score' => $row['score'],
                        'rank' => $idx + 1,
                        'computed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                // Chunked insert keeps the prepared statement under
                // MySQL's max_allowed_packet for very wide TOP_N.
                foreach (array_chunk($insert, 50) as $chunk) {
                    TrendingMovie::insert($chunk);
                }
            });

            Log::info('TrendingAggregator: recomputed window', [
                'window' => $window,
                'movie_ids' => $scored->count(),
            ]);
        } catch (Throwable $e) {
            // Logged but rethrown — aggregator failures should surface
            // in the scheduler's failure handler. The trending UI
            // already degrades gracefully when the table is empty.
            Log::error('TrendingAggregator: compute failed', [
                'window' => $window,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
