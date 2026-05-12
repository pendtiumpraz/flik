<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Ai\Tasks\SmartWatchlistPrioritizer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * Smart Watchlist Priority controller (Feature I8).
 *
 * Renders the user's watchlist re-ordered by AI/heuristic priority so the
 * films they're most likely to enjoy NOW float to the top.
 *
 * Result is cached 1h per user to avoid hitting the AI on every page view.
 */
class SmartWatchlistController extends Controller
{
    /**
     * GET /watchlist/smart — show the prioritized watchlist.
     */
    public function prioritized(SmartWatchlistPrioritizer $prioritizer): View|Factory
    {
        $user = auth()->user();

        // Cache key includes a quick signature of the watchlist so adds/removes
        // invalidate without us tracking events. Prefer the watchlists table
        // because it's the surface this view depends on.
        $signature = (string) \App\Models\Watchlist::where('user_id', $user->id)
            ->selectRaw('COALESCE(MAX(updated_at), MAX(created_at)) AS sig, COUNT(*) AS c')
            ->first()?->sig . ':' . \App\Models\Watchlist::where('user_id', $user->id)->count();

        $cacheKey = "smart-watchlist:user:{$user->id}:{$signature}";

        $prioritized = Cache::remember(
            $cacheKey,
            now()->addHour(),
            fn () => $prioritizer->prioritize($user)
        );

        $movies = $prioritized->map(fn ($w) => $this->mapMovie($w->movie));

        return view('watchlist.smart', [
            'movies' => $movies,
            'genres' => Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]),
            'count'  => $movies->count(),
        ]);
    }

    /**
     * Shape Movie → array consumed by <x-movies>. Mirrors VelflixController::mapMovie().
     */
    protected function mapMovie(Movie $movie): array
    {
        return [
            'id'             => $movie->id,
            'slug'           => $movie->slug,
            'title'          => $movie->title,
            'original_title' => $movie->original_title,
            'overview'       => $movie->overview,
            'release_date'   => $movie->release_date ? $movie->release_date->format('Y-m-d') : null,
            'poster_path'    => $movie->effective_poster_url,
            'backdrop_path'  => $movie->effective_backdrop_url,
            'slider_path'    => $movie->effective_slider_url,
            'vote_average'   => (float) $movie->vote_average,
            'vote_count'     => $movie->vote_count,
            'genre_ids'      => $movie->genres->pluck('id')->toArray(),
        ];
    }
}
