<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\TrendingMovie;
use App\Services\Trending\TrendingAggregator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * /trending — standalone "what's hot" page.
 *
 * Auth-optional (matches mood discovery and the homepage trending
 * shelf). Reads the pre-aggregated `trending_movies` cache; the
 * heavy lifting happens in the scheduler.
 */
class TrendingController extends Controller
{
    /** How many rows to show per window on the page (UI cap, < TOP_N in aggregator). */
    private const PAGE_LIMIT = 50;

    public function index(): View|Factory
    {
        $windows = array_keys(TrendingAggregator::WINDOWS);

        // Pull all four windows in a single query and bucket client-side
        // — saves us four round-trips. The cache cap (100 rows/window)
        // means the upper bound is 400 rows total, comfortably small.
        $rowsByWindow = TrendingMovie::query()
            ->with(['movie.genres'])
            ->whereIn('window', $windows)
            ->orderBy('rank')
            ->get()
            ->filter(fn ($r) => $r->movie !== null)
            ->groupBy('window')
            ->map(fn ($group) => $group->take(self::PAGE_LIMIT)->values());

        // Ensure every window key exists in the view (empty collection
        // when the cache is empty — view shows the empty-state copy).
        foreach ($windows as $w) {
            $rowsByWindow[$w] = $rowsByWindow[$w] ?? collect();
        }

        $genres = Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]);

        return view('trending.index', [
            'rowsByWindow' => $rowsByWindow,
            'windows' => $windows,
            'genres' => $genres,
        ]);
    }
}
