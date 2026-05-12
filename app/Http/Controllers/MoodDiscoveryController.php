<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Ai\Tasks\MoodDiscoveryService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MoodDiscoveryController extends Controller
{
    /**
     * GET — mood input form.
     */
    public function form(): View|Factory
    {
        return view('discovery.mood', [
            'moodInput' => '',
            'movies'    => collect(),
            'genres'    => $this->genreMap(),
            'tags'      => [],
            'submitted' => false,
        ]);
    }

    /**
     * POST — run mood discovery and render the same view with results.
     */
    public function discover(Request $request, MoodDiscoveryService $service): View|Factory
    {
        $data = $request->validate([
            'mood'  => 'required|string|min:2|max:200',
            'count' => 'nullable|integer|min:1|max:24',
        ]);

        // Cheap rate-limit (AI calls aren't free).
        $rateKey = 'mood-discover:' . (auth()->id() ?? $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 15)) {
            return view('discovery.mood', [
                'moodInput' => $data['mood'],
                'movies'    => collect(),
                'genres'    => $this->genreMap(),
                'tags'      => [],
                'submitted' => true,
                'error'     => 'Terlalu banyak permintaan. Tunggu sebentar ya.',
            ]);
        }
        RateLimiter::hit($rateKey, 60);

        $count = (int) ($data['count'] ?? 8);

        $movieModels = $service->recommend($data['mood'], $count);

        return view('discovery.mood', [
            'moodInput' => $data['mood'],
            'movies'    => $movieModels->map(fn (Movie $m) => $this->mapMovie($m)),
            'genres'    => $this->genreMap(),
            'tags'      => [],
            'submitted' => true,
        ]);
    }

    /**
     * Build the id→name genre map the <x-movies> component expects for pills.
     */
    protected function genreMap(): \Illuminate\Support\Collection
    {
        return Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]);
    }

    /**
     * Shape a Movie model into the array structure the <x-movies> component reads.
     * Mirrors VelflixController::mapMovie() so cards render identically.
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
