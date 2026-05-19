<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Trending\ViewTracker;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VelflixController extends Controller
{
    /**
     * Standard movie array shape used across the app.
     */
    private function mapMovie(Movie $movie, array $extra = []): array
    {
        return array_merge([
            'id' => $movie->id,
            'slug' => $movie->slug,
            'title' => $movie->title,
            'original_title' => $movie->original_title,
            'overview' => $movie->overview,
            'release_date' => $movie->release_date ? $movie->release_date->format('Y-m-d') : null,
            // Use effective_* accessors that rotate among multiple variants if present.
            'poster_path' => $movie->effective_poster_url,     // Portrait 2:3 (~500×750)
            'backdrop_path' => $movie->effective_backdrop_url, // Landscape 16:9
            'slider_path' => $movie->effective_slider_url,     // Cinematic ~2.4:1
            'vote_average' => (float) $movie->vote_average,
            'vote_count' => $movie->vote_count,
            'genre_ids' => $movie->genres->pluck('id')->toArray(),
        ], $extra);
    }

    private function getMoviesByGenre($genreSlug)
    {
        return Movie::byGenre($genreSlug)
            ->with('genres')
            ->orderByDesc('popularity')
            ->limit(20)
            ->get()
            ->map(fn ($m) => $this->mapMovie($m));
    }

    public function index(): View|Factory
    {
        // ── HERO SLIDER (top 7 popular) ─────────────────────────
        $heroSlider = Movie::popular()
            ->with('genres')
            ->limit(7)
            ->get()
            ->map(fn ($m) => $this->mapMovie($m));

        // ── TRENDING / FILTERED with pagination ─────────────────
        // If any filter (letter, q, genre, year, sort) → apply as filter
        // Else → show trending by default
        $hasFilter = request()->hasAny(['letter', 'q', 'genre', 'country', 'year', 'type', 'status', 'language', 'sort']);
        $trendingQuery = Movie::with('genres');

        // A-Z first-letter filter
        if ($letterParam = request('letter')) {
            if ($letterParam === '0-9') {
                $trendingQuery->where('title', 'REGEXP', '^[0-9]');
            } elseif ($letterParam !== 'All') {
                $trendingQuery->where('title', 'LIKE', strtoupper($letterParam) . '%');
            }
        }

        // Search query
        if ($q = trim(request('q', ''))) {
            $trendingQuery->where(function ($qr) use ($q) {
                $qr->where('title', 'LIKE', "%{$q}%")
                   ->orWhere('original_title', 'LIKE', "%{$q}%");
            });
        }

        // Genre slug filter
        if ($genreSlug = request('genre')) {
            $trendingQuery->whereHas('genres', fn ($q) => $q->where('slug', $genreSlug));
        }

        // Year filter
        if ($year = request('year')) {
            $trendingQuery->whereYear('release_date', $year);
        }

        // Sort
        $sort = request('sort', 'default');
        match ($sort) {
            'latest'        => $trendingQuery->orderByDesc('release_date'),
            'most-popular'  => $trendingQuery->orderByDesc('popularity'),
            'highest-rated' => $trendingQuery->orderByDesc('vote_average'),
            'a-z'           => $trendingQuery->orderBy('title'),
            'z-a'           => $trendingQuery->orderByDesc('title'),
            default         => $hasFilter
                                ? $trendingQuery->orderByDesc('popularity')
                                : $trendingQuery->trending(),
        };

        $trendingPaginator = $trendingQuery
            ->paginate(24, ['*'], 'trending')
            ->withQueryString();

        $trending = $trendingPaginator->getCollection()->map(fn ($m) => $this->mapMovie($m));

        // Dynamic header label
        $trendingTitle = $hasFilter ? 'Filtered Results' : 'Trending Now';

        // ── COMING SOON (release date in the future, atau popularity rendah dengan release > 6 bulan lalu sebagai fallback) ─
        $comingSoon = Movie::with('genres')
            ->where(function ($q) {
                $q->where('release_date', '>', now())
                  ->orWhere(function ($q2) {
                      // Fallback: kalau gak ada future release, ambil yang akan datang dalam 1 tahun
                      $q2->where('release_date', '>=', now()->subDays(30))
                         ->where('release_date', '<=', now()->addYear());
                  });
            })
            ->orderBy('release_date')
            ->limit(12)
            ->get()
            ->map(fn ($m) => $this->mapMovie($m));

        // Kalau coming soon kosong, fallback ke yang baru dirilis
        if ($comingSoon->isEmpty()) {
            $comingSoon = Movie::with('genres')
                ->whereNotNull('release_date')
                ->orderByDesc('release_date')
                ->limit(12)
                ->get()
                ->map(fn ($m) => $this->mapMovie($m));
        }

        // ── TOP 10 (Today / Week / Month) ───────────────────────
        // Berbasis WatchHistory count + fallback ke popularity
        $topToday = $this->getTopByPeriod('today');
        $topWeek = $this->getTopByPeriod('week');
        $topMonth = $this->getTopByPeriod('month');

        // ── A-Z (active letter from query string for highlight) ─
        $letter = request('letter', 'All');
        $hasFilterFlag = $hasFilter;

        // ── GENRE map (untuk popover hover di card) ─────────────
        $genres = Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]);
        $allGenres = Genre::orderBy('name')->get();

        // ── CONTINUE WATCHING ───────────────────────────────────
        $continueWatching = collect();
        if (auth()->check()) {
            $continueWatching = \App\Models\WatchHistory::where('user_id', auth()->id())
                ->where('completed', false)
                ->with('movie.genres')
                ->orderByDesc('last_watched_at')
                ->limit(10)
                ->get()
                ->map(fn ($h) => $this->mapMovie($h->movie, ['progress' => $h->progress_percentage]));
        }

        // ── FILTER OPTIONS ──────────────────────────────────────
        $filterOptions = [
            'genres' => $allGenres,
            'countries' => ['Indonesia', 'United States', 'United Kingdom', 'Japan', 'South Korea', 'India', 'Hong Kong', 'France', 'China'],
            'years' => range(now()->year, 1950),
            'types' => ['Movie', 'TV Series', 'Documentary', 'Short Film'],
            'statuses' => ['Released', 'Coming Soon', 'In Production', 'Restored'],
            'languages' => ['Indonesia', 'English', 'Mandarin', 'Korean', 'Japanese', 'Hindi', 'Arabic'],
            'sortOptions' => ['Default', 'Latest', 'Most Popular', 'Highest Rated', 'A-Z', 'Z-A'],
        ];

        return view('main', [
            'heroSlider' => $heroSlider,
            'genres' => $genres,
            'trending' => $trending,
            'trendingPaginator' => $trendingPaginator,
            'trendingTitle' => $trendingTitle,
            'hasFilter' => $hasFilterFlag,
            'comingSoon' => $comingSoon,
            'topToday' => $topToday,
            'topWeek' => $topWeek,
            'topMonth' => $topMonth,
            'currentLetter' => $letter,
            'continueWatching' => $continueWatching,
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Get top 10 movies for a period (today/week/month).
     * Uses watch_histories count, falls back to popularity if no data.
     */
    private function getTopByPeriod(string $period)
    {
        $since = match ($period) {
            'today' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->subWeek(),
            'month' => Carbon::now()->subMonth(),
            default => Carbon::now()->subWeek(),
        };

        // Try watch-based ranking first
        $topByViews = Movie::with('genres')
            ->withCount(['watchHistories as period_views' => function ($q) use ($since) {
                $q->where('last_watched_at', '>=', $since);
            }])
            ->having('period_views', '>', 0)
            ->orderByDesc('period_views')
            ->limit(10)
            ->get();

        // Fallback to popularity if no watch data
        if ($topByViews->isEmpty()) {
            $topByViews = Movie::with('genres')
                ->orderByDesc('popularity')
                ->limit(10)
                ->get();
        }

        return $topByViews->values()->map(fn ($m, $idx) => array_merge(
            $this->mapMovie($m),
            ['rank' => $idx + 1]
        ));
    }

    /**
     * Get movies starting with a letter (or '#' for 0-9).
     */
    private function getAZMovies(string $letter)
    {
        $query = Movie::with('genres')->orderBy('title');

        if ($letter === '#') {
            $query->where('title', 'REGEXP', '^[0-9]');
        } elseif ($letter === 'All') {
            $query->limit(50);
        } else {
            $query->where('title', 'LIKE', strtoupper($letter) . '%');
        }

        return $query->limit(50)->get()->map(fn ($m) => $this->mapMovie($m));
    }

    /**
     * @param  Movie  $watch  Resolved via slug (Movie::getRouteKeyName())
     * @return View|Factory
     */
    public function show(Movie $watch, Request $request, ViewTracker $viewTracker): View|Factory
    {
        $movie = $watch->loadMissing(['genres', 'castMembers']);

        // Lower-weight trending signal: page-view-only. No duration
        // (they haven't pressed play yet) — the higher-weight signal
        // fires from WatchHistoryController::updateProgress when they
        // actually start watching. ViewTracker dedups within 30 min
        // so reloading the detail page doesn't spam counts.
        $viewTracker->record(
            movie: $movie,
            user: auth()->user(),
            request: $request,
            durationSeconds: null,
        );

        // ── AI-generated assets (lazy-loaded; sections render conditionally) ───
        $movie->load([
            'trivia' => fn ($q) => $q->orderBy('sort_order')->limit(8),
            'quotes' => fn ($q) => $q->limit(3),
            'aiReviews',
            'behindScenes',
        ]);

        // ── Series support ───────────────────────────────────────────────
        // When this row represents a TV series, eager-load the full
        // seasons → episodes tree so the picker can render without N+1.
        // We also pull the current user's watch_histories filtered to
        // this series' episode IDs so the per-episode progress bar +
        // "first unwatched" CTA work off a single round-trip.
        $episodeProgress = collect();
        if ($movie->isSeries()) {
            $movie->load(['seasons.episodes']);

            $episodeIds = $movie->seasons
                ->flatMap(fn ($s) => $s->episodes->pluck('id'))
                ->all();

            if (auth()->check() && ! empty($episodeIds)) {
                $episodeProgress = \App\Models\WatchHistory::query()
                    ->where('user_id', auth()->id())
                    ->whereIn('episode_id', $episodeIds)
                    ->get()
                    // Keyed by episode_id so the Blade can do O(1) lookups.
                    ->keyBy('episode_id');
            }
        }

        $playMovie = [
            'id' => $movie->id,
            'title' => $movie->title,
            'original_title' => $movie->original_title ?? $movie->title,
            'overview' => $movie->overview,
            'poster_path' => $movie->poster_url,
            'backdrop_path' => $movie->backdrop_url,
            'release_date' => $movie->release_date ? $movie->release_date->format('Y-m-d') : null,
            'vote_average' => (float) $movie->vote_average,
            'vote_count' => $movie->vote_count,
            'genres' => $movie->genres->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->toArray(),
            'credits' => [
                'cast' => $movie->castMembers->map(fn ($c) => [
                    'name' => $c->name,
                    'character' => $c->pivot->character,
                    'profile_path' => $c->profile_path,
                ])->toArray(),
            ],
            'videos' => [
                'results' => $movie->youtube_key
                    ? [['key' => $movie->youtube_key, 'type' => 'Trailer']]
                    : [],
            ],
        ];

        $inWatchlist = auth()->check() ? auth()->user()->hasInWatchlist($movie->id) : false;
        $userRating = auth()->check() ? auth()->user()->getRatingFor($movie->id) : null;

        $comments = $movie->comments()
            ->topLevel()
            ->with(['user', 'replies.user'])
            ->latest()
            ->take(20)
            ->get();

        $avgRating = $movie->average_rating;
        $ratingsCount = $movie->ratings_count;

        return view('components.movies.show', [
            'movies' => $playMovie,
            'movieModel' => $movie,
            'inWatchlist' => $inWatchlist,
            'userRating' => $userRating,
            'comments' => $comments,
            'avgRating' => $avgRating,
            'ratingsCount' => $ratingsCount,
            // Series-only payload (empty collection for standalone movies
            // so the view can render it unconditionally).
            'episodeProgress' => $episodeProgress,
        ]);
    }
}
