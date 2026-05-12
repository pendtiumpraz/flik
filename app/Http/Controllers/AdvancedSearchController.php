<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Ai\Search\DecadeStyleSearchService;
use App\Services\Ai\Search\DirectorActorSearchService;
use App\Services\Ai\Search\ImageSearchService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Advanced Search Controller — fronts three AI-powered discovery modes:
 *   • K2 — Image Search        : identify a film from an uploaded still / poster.
 *   • K5 — Decade-style Search : "film 70an seperti The Godfather" → curated picks.
 *   • K6 — Director / Actor    : list the catalog films for a person (with AI for directors).
 *
 * Each mode has a GET form view + POST handler that re-renders the same view with results.
 * AI calls are rate-limited per-user to keep cost predictable.
 */
class AdvancedSearchController extends Controller
{
    /**
     * Max upload size for image search (KB). 8 MB.
     */
    protected const IMAGE_MAX_KB = 8192;

    // ─── K2 — Image Search ────────────────────────────────────────────

    public function imageForm(): View|Factory
    {
        return view('search.image', [
            'movies'    => collect(),
            'genres'    => $this->genreMap(),
            'submitted' => false,
            'error'     => null,
            'imagePreview' => null,
        ]);
    }

    public function imageSearch(Request $request, ImageSearchService $service): View|Factory
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:' . self::IMAGE_MAX_KB],
        ]);

        if ($error = $this->rateLimit('search-image')) {
            return view('search.image', [
                'movies'    => collect(),
                'genres'    => $this->genreMap(),
                'submitted' => true,
                'error'     => $error,
                'imagePreview' => null,
            ]);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $data['image'];
        $mime = $file->getMimeType() ?: 'image/jpeg';

        $bytes = @file_get_contents($file->getRealPath());
        if ($bytes === false || $bytes === '') {
            return view('search.image', [
                'movies'    => collect(),
                'genres'    => $this->genreMap(),
                'submitted' => true,
                'error'     => 'Tidak bisa membaca file gambar. Coba upload ulang.',
                'imagePreview' => null,
            ]);
        }

        $base64 = base64_encode($bytes);
        $movieModels = $service->searchByImage($base64, $mime);

        // Inline preview (data URI) so the user sees what they uploaded next to results.
        $preview = 'data:' . $mime . ';base64,' . $base64;

        return view('search.image', [
            'movies'    => $movieModels->map(fn (Movie $m) => $this->mapMovie($m, [
                '_ai_confidence' => $m->getAttribute('_ai_confidence'),
                '_ai_guess_title' => $m->getAttribute('_ai_guess_title'),
            ])),
            'genres'    => $this->genreMap(),
            'submitted' => true,
            'error'     => null,
            'imagePreview' => $preview,
        ]);
    }

    // ─── K5 — Decade-style Search ─────────────────────────────────────

    public function vibeForm(): View|Factory
    {
        return view('search.vibe', [
            'vibeInput' => '',
            'eraInput'  => '',
            'movies'    => collect(),
            'genres'    => $this->genreMap(),
            'submitted' => false,
            'error'     => null,
        ]);
    }

    public function vibeSearch(Request $request, DecadeStyleSearchService $service): View|Factory
    {
        $data = $request->validate([
            'vibe'  => ['required', 'string', 'min:2', 'max:300'],
            'era'   => ['nullable', 'string', 'max:20'],
            'count' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        if ($error = $this->rateLimit('search-vibe')) {
            return view('search.vibe', [
                'vibeInput' => $data['vibe'],
                'eraInput'  => $data['era'] ?? '',
                'movies'    => collect(),
                'genres'    => $this->genreMap(),
                'submitted' => true,
                'error'     => $error,
            ]);
        }

        $count = (int) ($data['count'] ?? 12);

        $movieModels = $service->searchByVibe(
            vibe: $data['vibe'],
            era: $data['era'] ?? null,
            count: $count,
        );

        return view('search.vibe', [
            'vibeInput' => $data['vibe'],
            'eraInput'  => $data['era'] ?? '',
            'movies'    => $movieModels->map(fn (Movie $m) => $this->mapMovie($m)),
            'genres'    => $this->genreMap(),
            'submitted' => true,
            'error'     => null,
        ]);
    }

    // ─── K6 — Director / Actor Search ─────────────────────────────────

    public function personForm(): View|Factory
    {
        return view('search.person', [
            'personInput' => '',
            'typeInput'   => 'auto',
            'movies'      => collect(),
            'genres'      => $this->genreMap(),
            'submitted'   => false,
            'error'       => null,
        ]);
    }

    public function personSearch(Request $request, DirectorActorSearchService $service): View|Factory
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', 'in:auto,director,actor'],
        ]);

        $type = $data['type'] ?? 'auto';

        if ($error = $this->rateLimit('search-person')) {
            return view('search.person', [
                'personInput' => $data['name'],
                'typeInput'   => $type,
                'movies'      => collect(),
                'genres'      => $this->genreMap(),
                'submitted'   => true,
                'error'       => $error,
            ]);
        }

        $movieModels = $service->searchByPerson($data['name'], $type);

        return view('search.person', [
            'personInput' => $data['name'],
            'typeInput'   => $type,
            'movies'      => $movieModels->map(fn (Movie $m) => $this->mapMovie($m, [
                '_match_type'    => $m->getAttribute('_match_type'),
                '_ai_guess_title'=> $m->getAttribute('_ai_guess_title'),
            ])),
            'genres'      => $this->genreMap(),
            'submitted'   => true,
            'error'       => null,
        ]);
    }

    // ─── Internals ────────────────────────────────────────────────────

    /**
     * Per-user rate limiting — 15 hits / 60s per bucket.
     * Returns an Indonesian error string when limited, null otherwise.
     */
    protected function rateLimit(string $bucket): ?string
    {
        $key = $bucket . ':' . (auth()->id() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($key, 15)) {
            return 'Terlalu banyak permintaan. Tunggu sebentar ya.';
        }
        RateLimiter::hit($key, 60);
        return null;
    }

    /**
     * id→name genre map for the <x-movies> popover pills.
     */
    protected function genreMap(): \Illuminate\Support\Collection
    {
        return Genre::all()->mapWithKeys(fn ($g) => [$g->id => $g->name]);
    }

    /**
     * Shape a Movie model into the array structure <x-movies> expects.
     * Mirrors VelflixController::mapMovie() so cards render identically.
     *
     * @param  array<string, mixed>  $extra  Extra keys to merge (e.g. AI metadata).
     * @return array<string, mixed>
     */
    protected function mapMovie(Movie $movie, array $extra = []): array
    {
        return array_merge([
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
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
