<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class VelflixController extends Controller
{
    /**
     * Get movies by genre slug.
     *
     * @param  string  $genreSlug
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getMoviesByGenre($genreSlug)
    {
        return Movie::byGenre($genreSlug)
            ->with('genres')
            ->orderByDesc('popularity')
            ->limit(20)
            ->get()
            ->map(function ($movie) {
                return [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'poster_path' => $movie->poster_url,
                    'vote_average' => (float) $movie->vote_average,
                    'genre_ids' => $movie->genres->pluck('id')->toArray(),
                ];
            });
    }

    public function index(): View|Factory
    {
        $popular = Movie::popular()
            ->with('genres')
            ->limit(20)
            ->get()
            ->map(function ($movie) {
                return [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'original_title' => $movie->original_title,
                    'overview' => $movie->overview,
                    'poster_path' => $movie->poster_url,
                    'backdrop_path' => $movie->backdrop_url,
                    'vote_average' => (float) $movie->vote_average,
                    'genre_ids' => $movie->genres->pluck('id')->toArray(),
                ];
            });

        $trending = Movie::trending()
            ->with('genres')
            ->limit(20)
            ->get()
            ->map(function ($movie) {
                return [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'poster_path' => $movie->poster_url,
                    'vote_average' => (float) $movie->vote_average,
                    'genre_ids' => $movie->genres->pluck('id')->toArray(),
                ];
            });

        $genres = Genre::all()->mapWithKeys(function ($genre) {
            return [$genre->id => $genre->name];
        });

        $comedies = $this->getMoviesByGenre('comedy');
        $action = $this->getMoviesByGenre('action');
        $western = $this->getMoviesByGenre('western');
        $horror = $this->getMoviesByGenre('horror');
        $thriller = $this->getMoviesByGenre('thriller');
        $animation = $this->getMoviesByGenre('animation');

        // Continue Watching (logged-in users)
        $continueWatching = collect();
        if (auth()->check()) {
            $continueWatching = \App\Models\WatchHistory::where('user_id', auth()->id())
                ->where('completed', false)
                ->with('movie.genres')
                ->orderByDesc('last_watched_at')
                ->limit(10)
                ->get()
                ->map(function ($history) {
                    $movie = $history->movie;
                    return [
                        'id' => $movie->id,
                        'title' => $movie->title,
                        'poster_path' => $movie->poster_url,
                        'vote_average' => (float) $movie->vote_average,
                        'genre_ids' => $movie->genres->pluck('id')->toArray(),
                        'progress' => $history->progress_percentage,
                    ];
                });
        }

        // Top Rated movies
        $topRated = Movie::with('genres')
            ->where('vote_average', '>=', 7)
            ->orderByDesc('vote_average')
            ->limit(20)
            ->get()
            ->map(function ($movie) {
                return [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'poster_path' => $movie->poster_url,
                    'vote_average' => (float) $movie->vote_average,
                    'genre_ids' => $movie->genres->pluck('id')->toArray(),
                ];
            });

        return view('main', [
            'popular' => $popular,
            'genres' => $genres,
            'trending' => $trending,
            'comedies' => $comedies,
            'western' => $western,
            'action' => $action,
            'horror' => $horror,
            'thriller' => $thriller,
            'animation' => $animation,
            'continueWatching' => $continueWatching,
            'topRated' => $topRated,
        ]);
    }

    /**
     * @param  mixed  $id
     * @return View|Factory
     */
    public function show($id): View|Factory
    {
        $movie = Movie::with(['genres', 'casts'])->findOrFail($id);

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
            'genres' => $movie->genres->map(function ($genre) {
                return ['id' => $genre->id, 'name' => $genre->name];
            })->toArray(),
            'credits' => [
                'cast' => $movie->casts->map(function ($cast) {
                    return [
                        'name' => $cast->name,
                        'character' => $cast->pivot->character,
                        'profile_path' => $cast->profile_path,
                    ];
                })->toArray(),
            ],
            'videos' => [
                'results' => $movie->youtube_key
                    ? [['key' => $movie->youtube_key, 'type' => 'Trailer']]
                    : [],
            ],
        ];

        // User-specific data
        $inWatchlist = auth()->check() ? auth()->user()->hasInWatchlist($movie->id) : false;
        $userRating = auth()->check() ? auth()->user()->getRatingFor($movie->id) : null;

        // Comments
        $comments = $movie->comments()
            ->topLevel()
            ->with(['user', 'replies.user'])
            ->latest()
            ->take(20)
            ->get();

        // Community rating
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
        ]);
    }
}
