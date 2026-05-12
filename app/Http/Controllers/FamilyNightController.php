<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\User;
use App\Services\Ai\Tasks\FamilyMovieNightRecommender;
use App\Services\Ai\Tasks\MoodDiscoveryService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Family Movie Night controller (Feature N4).
 *
 * Lets the signed-in user pick a few co-viewers + tonight's constraints
 * (kid-safety, runtime cap, language, mood) and then renders 5 AI-curated
 * picks the whole group should enjoy.
 */
class FamilyNightController extends Controller
{
    /**
     * GET /family-night — show the picker form.
     */
    public function form(): View|Factory
    {
        $current = auth()->user();

        // Pickable viewers = self + up to 50 other regular users (lightweight UI).
        $viewers = User::query()
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        return view('family-night.form', [
            'viewers'        => $viewers,
            'currentUserId'  => $current->id,
            'moods'          => MoodDiscoveryService::MOOD_VOCABULARY,
            'languages'      => $this->languages(),
            'submitted'      => false,
            'movies'         => collect(),
            'genres'         => collect(),
            'constraints'    => [],
            'selectedIds'    => [$current->id],
        ]);
    }

    /**
     * POST /family-night — run the recommender and re-render the page with results.
     */
    public function recommend(Request $request, FamilyMovieNightRecommender $recommender): View|Factory
    {
        $data = $request->validate([
            'user_ids'              => 'required|array|min:1|max:8',
            'user_ids.*'            => 'integer|exists:users,id',
            'min_age'               => 'nullable|integer|min:0|max:99',
            'duration_max_minutes'  => 'nullable|integer|min:30|max:360',
            'language'              => 'nullable|string|max:10',
            'mood'                  => 'nullable|string|max:30',
        ]);

        // Cheap rate-limit (AI-backed).
        $rateKey = 'family-night:' . (auth()->id() ?? $request->ip());
        $error = null;
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            $error = 'Terlalu banyak permintaan. Coba lagi dalam 1 menit.';
        } else {
            RateLimiter::hit($rateKey, 60);
        }

        $constraints = array_filter([
            'min_age'              => $data['min_age']              ?? null,
            'duration_max_minutes' => $data['duration_max_minutes'] ?? null,
            'language'             => $data['language']             ?? null,
            'mood'                 => $data['mood']                 ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $movieModels = $error
            ? collect()
            : $recommender->recommend($data['user_ids'], $constraints);

        $viewers = User::query()
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        return view('family-night.result', [
            'viewers'       => $viewers,
            'selectedIds'   => array_map('intval', $data['user_ids']),
            'currentUserId' => auth()->id(),
            'moods'         => MoodDiscoveryService::MOOD_VOCABULARY,
            'languages'     => $this->languages(),
            'constraints'   => $constraints,
            'submitted'     => true,
            'movies'        => $movieModels->map(fn (Movie $m) => $this->mapMovie($m)),
            'genres'        => Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]),
            'error'         => $error,
        ]);
    }

    /**
     * Languages offered in the picker (display label => ISO code).
     *
     * @return array<string, string>
     */
    protected function languages(): array
    {
        return [
            'Indonesia' => 'id',
            'English'   => 'en',
            'Korean'    => 'ko',
            'Japanese'  => 'ja',
            'Mandarin'  => 'zh',
            'Hindi'     => 'hi',
            'Arabic'    => 'ar',
            'French'    => 'fr',
        ];
    }

    /**
     * Mirror VelflixController::mapMovie() so cards render identically.
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
