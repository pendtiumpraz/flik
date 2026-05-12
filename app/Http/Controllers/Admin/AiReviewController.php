<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Models\MovieAiReview;
use App\Services\Ai\Tasks\MovieReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin UI for generating & viewing multi-perspective AI movie reviews.
 *
 * Pairs a Movie with up to four `MovieAiReview` rows — one per perspective
 * (critic / casual / family / academic). Generation is synchronous; long
 * provider timeouts are bounded by AiClient's HTTP timeout (30s).
 */
class AiReviewController extends Controller
{
    public function __construct(
        protected MovieReviewer $reviewer,
    ) {}

    /**
     * Show all 4 perspectives for a given movie (existing reviews + empty slots).
     */
    public function index(Movie $movie): View
    {
        $existing = MovieAiReview::where('movie_id', $movie->id)
            ->get()
            ->keyBy('perspective');

        // Build a complete grid: every perspective present, value=null if not yet generated.
        $reviews = [];
        foreach (MovieAiReview::PERSPECTIVES as $p) {
            $reviews[$p] = $existing->get($p);
        }

        return view('admin.movies.ai-reviews', [
            'movie'       => $movie,
            'reviews'     => $reviews,
            'perspectives'=> MovieAiReview::PERSPECTIVES,
            'labels'      => MovieAiReview::PERSPECTIVE_LABELS,
        ]);
    }

    /**
     * Generate one or more perspectives. Accepts either a single
     * `perspective` field or an array `perspectives[]` from the form.
     */
    public function generate(Request $request, Movie $movie): RedirectResponse
    {
        $validated = $request->validate([
            'perspective'     => ['nullable', 'string'],
            'perspectives'    => ['nullable', 'array'],
            'perspectives.*'  => ['string', 'in:' . implode(',', MovieAiReview::PERSPECTIVES)],
        ]);

        // Normalize into a unique list of perspectives to generate.
        $targets = collect($validated['perspectives'] ?? [])
            ->merge(array_filter([$validated['perspective'] ?? null]))
            ->filter(fn ($p) => in_array($p, MovieAiReview::PERSPECTIVES, true))
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return back()->with('error', 'Pilih minimal satu perspective untuk di-generate.');
        }

        $succeeded = [];
        $failed    = [];

        foreach ($targets as $perspective) {
            try {
                $this->reviewer->review($movie, $perspective);
                $succeeded[] = MovieAiReview::PERSPECTIVE_LABELS[$perspective] ?? $perspective;
            } catch (\Throwable $e) {
                Log::warning('AiReviewController generate failed', [
                    'movie_id'    => $movie->id,
                    'perspective' => $perspective,
                    'error'       => $e->getMessage(),
                ]);
                $failed[] = ($perspective) . ' (' . $e->getMessage() . ')';
            }
        }

        $redirect = redirect()->route('admin.movies.ai-reviews.index', $movie);

        if (!empty($succeeded) && empty($failed)) {
            return $redirect->with('success', 'Review berhasil di-generate: ' . implode(', ', $succeeded) . '.');
        }

        if (!empty($succeeded) && !empty($failed)) {
            return $redirect
                ->with('success', 'Sebagian berhasil: ' . implode(', ', $succeeded) . '.')
                ->with('error', 'Sebagian gagal: ' . implode('; ', $failed));
        }

        return $redirect->with('error', 'Gagal generate review: ' . implode('; ', $failed));
    }
}
