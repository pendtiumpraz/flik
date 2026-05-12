<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\Ai\Tasks\MovieComparator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Movie vs Movie AI comparison.
 *
 * Suggested wiring (NOT modified here, see report):
 *   GET  /compare       → form()
 *   POST /compare       → compare()
 *   POST /api/compare   → compareApi()  (auth)
 */
class MovieComparisonController extends Controller
{
    /**
     * GET — render the two-movie selection form.
     */
    public function form(): View|Factory
    {
        return view('comparison.form', [
            'movies' => $this->movieOptions(),
        ]);
    }

    /**
     * POST — validate the two selected movies, run the comparator,
     * and render the result view.
     */
    public function compare(Request $request, MovieComparator $comparator): View|Factory
    {
        $data = $this->validateInput($request);

        if (!$this->checkRateLimit($request)) {
            return view('comparison.form', [
                'movies' => $this->movieOptions(),
                'error'  => 'Terlalu banyak permintaan. Tunggu sebentar ya.',
            ])->with([
                'movie_a' => $data['movie_a'],
                'movie_b' => $data['movie_b'],
            ]);
        }

        [$movieA, $movieB] = $this->loadPair((int) $data['movie_a'], (int) $data['movie_b']);

        try {
            $result = $comparator->compare($movieA, $movieB);
        } catch (InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->withErrors(['movie_b' => $e->getMessage()]);
        }

        return view('comparison.result', [
            'movieA' => $movieA,
            'movieB' => $movieB,
            'result' => $result,
        ]);
    }

    /**
     * POST JSON — same comparison, returned as a JSON payload for AJAX consumers.
     */
    public function compareApi(Request $request, MovieComparator $comparator): JsonResponse
    {
        $data = $this->validateInput($request);

        if (!$this->checkRateLimit($request)) {
            return response()->json([
                'ok'    => false,
                'error' => 'rate_limited',
                'message' => 'Terlalu banyak permintaan. Tunggu sebentar ya.',
            ], 429);
        }

        [$movieA, $movieB] = $this->loadPair((int) $data['movie_a'], (int) $data['movie_b']);

        try {
            $result = $comparator->compare($movieA, $movieB);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok'      => false,
                'error'   => 'invalid_pair',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok'     => true,
            'result' => $result,
        ]);
    }

    /**
     * Shared validation rules — keeps form + API in sync.
     *
     * @return array{movie_a:int|string, movie_b:int|string}
     */
    protected function validateInput(Request $request): array
    {
        return $request->validate([
            'movie_a' => ['required', 'integer', 'exists:movies,id', 'different:movie_b'],
            'movie_b' => ['required', 'integer', 'exists:movies,id', 'different:movie_a'],
        ], [
            'movie_a.different' => 'Pilih dua film yang berbeda.',
            'movie_b.different' => 'Pilih dua film yang berbeda.',
        ]);
    }

    /**
     * AI calls aren't free — cap to 12 comparisons / minute / user (or IP).
     */
    protected function checkRateLimit(Request $request): bool
    {
        $key = 'movie-compare:' . (auth()->id() ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 12)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    /**
     * Eager-load both films with the relations the comparator + view need.
     *
     * @return array{0: Movie, 1: Movie}
     */
    protected function loadPair(int $idA, int $idB): array
    {
        $movies = Movie::with(['genres', 'castMembers'])
            ->whereIn('id', [$idA, $idB])
            ->get()
            ->keyBy('id');

        return [
            $movies->get($idA),
            $movies->get($idB),
        ];
    }

    /**
     * Lightweight movie list for the dropdown / autocomplete.
     * Returns id + title + year ordered by popularity then title.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,title:string,year:?string,label:string}>
     */
    protected function movieOptions(): \Illuminate\Support\Collection
    {
        return Movie::query()
            ->select('id', 'title', 'release_date', 'popularity')
            ->orderByDesc('popularity')
            ->orderBy('title')
            ->get()
            ->map(function (Movie $m): array {
                $year = $m->release_date?->format('Y');

                return [
                    'id'    => (int) $m->id,
                    'title' => (string) $m->title,
                    'year'  => $year,
                    'label' => $m->title . ($year ? ' (' . $year . ')' : ''),
                ];
            });
    }
}
