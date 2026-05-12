<?php

namespace App\Http\Controllers;

use App\Models\YearInReview;
use App\Services\Ai\Tasks\YearInReviewGenerator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class YearInReviewController extends Controller
{
    public function __construct(
        protected YearInReviewGenerator $generator,
    ) {}

    /**
     * Show the user's year-in-review for $year (defaults to previous calendar year).
     * Generates and persists the review on first visit.
     */
    public function show(?int $year = null)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        abort_unless($user, 403);

        $year ??= Carbon::now()->subYear()->year;
        $year = $this->validateYear($year);

        $review = YearInReview::where('user_id', $user->id)
            ->where('year', $year)
            ->first();

        if (!$review) {
            $payload = $this->generator->generate($user, $year);

            // Race-safe: another request may have written one in between — use updateOrCreate.
            $review = YearInReview::updateOrCreate(
                ['user_id' => $user->id, 'year' => $year],
                [
                    'stats'        => $payload['stats'],
                    'narrative'    => $payload['narrative'],
                    'generated_at' => now(),
                ],
            );
        }

        $shareable = $this->generator
            ? $this->buildShareablePayload($user, $review)
            : [];

        return view('year-in-review.show', [
            'review'    => $review,
            'year'      => (int) $review->year,
            'stats'     => is_array($review->stats) ? $review->stats : [],
            'narrative' => (string) $review->narrative,
            'shareable' => $shareable,
        ]);
    }

    /**
     * POST /year-in-review/{id}/share — bump the share counter and return the
     * shareable payload (for client-side share-sheet / copy-link usage).
     */
    public function share(Request $request, int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        abort_unless($user, 403);

        $review = YearInReview::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $newCount = $review->incrementShare();

        return response()->json([
            'status'       => 'ok',
            'shared_count' => $newCount,
            'shareable'    => $this->buildShareablePayload($user, $review),
            'share_url'    => url('/year-in-review/' . $review->year),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Clamp $year to a sensible range. Anything outside [2000, currentYear] falls
     * back to the previous year so we never try to render reviews for the future.
     */
    protected function validateYear(int $year): int
    {
        $currentYear = (int) Carbon::now()->year;
        if ($year < 2000 || $year > $currentYear) {
            return $currentYear - 1;
        }

        return $year;
    }

    /**
     * Recompute the lightweight shareable payload from persisted stats.
     */
    protected function buildShareablePayload($user, YearInReview $review): array
    {
        $stats = is_array($review->stats) ? $review->stats : [];
        $top3 = collect($stats['top_genres'] ?? [])->take(3)->pluck('name')->values()->all();

        return [
            'title'        => "Tahun {$review->year} {$user->name} di FLiK",
            'top_3_genres' => $top3,
            'total_hours'  => (float) ($stats['total_watch_hours'] ?? 0),
        ];
    }
}
