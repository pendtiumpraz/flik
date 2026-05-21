<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\UserPreference;
use App\Services\Referrals\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    /** Available eras shown in the quiz (kept in sync with the view). */
    protected const ERAS = ['1950s', '1960s', '1970s', '1980s', '1990s', '2000s', '2010s', '2020s'];

    /** Mood choices (kept in sync with the view). */
    protected const MOODS = ['cozy', 'intense', 'romantic', 'thrilling', 'inspiring', 'funny', 'mind-bending', 'dark'];

    /**
     * Show the 3-question onboarding quiz.
     */
    public function quiz(): View
    {
        $genres = Genre::orderBy('name')->get(['id', 'name', 'slug']);

        return view('onboarding.quiz', [
            'genres' => $genres,
            'eras'   => self::ERAS,
            'moods'  => self::MOODS,
        ]);
    }

    /**
     * Save the user's preferences and send them to the catalog.
     */
    public function submit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'favorite_genres'      => ['required', 'array', 'min:1', 'max:8'],
            'favorite_genres.*'    => ['string'],
            'favorite_eras'        => ['required', 'array', 'min:1', 'max:8'],
            'favorite_eras.*'      => ['string', 'in:' . implode(',', self::ERAS)],
            'preferred_moods'      => ['nullable', 'array', 'max:8'],
            'preferred_moods.*'    => ['string', 'in:' . implode(',', self::MOODS)],
            'language_preference'  => ['nullable', 'string', 'max:32'],
        ]);

        UserPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'favorite_genres'     => array_values($validated['favorite_genres']),
                'favorite_eras'       => array_values($validated['favorite_eras']),
                'preferred_moods'     => array_values($validated['preferred_moods'] ?? []),
                'language_preference' => $validated['language_preference'] ?? null,
                'onboarded_at'        => now(),
            ]
        );

        // Quiz complete — burn the dismiss-banner session flag and bust the
        // user's cached recommendations so the next /api/recommendations
        // hit re-runs through the engine (which will now route through
        // ColdStartRecommender via UserPreference).
        $request->session()->forget('onboarding.pending');
        try {
            \Cache::forget("flik:user:{$request->user()->id}:recommendations");
        } catch (\Throwable $e) {
            \Log::info('OnboardingController: failed to clear rec cache', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // ── Refer-a-friend: mark qualified ──────────────────────────
        // Onboarding completion is the "qualified" trigger — both the
        // referrer and the new user get a small coin grant. The service
        // is a no-op when the user wasn't referred or already qualified.
        try {
            app(ReferralService::class)->markQualified($request->user());
        } catch (\Throwable $e) {
            \Log::warning('OnboardingController: referral qualify failed', [
                'user_id' => $request->user()->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return redirect('/movies')->with('success', 'Preferensi tersimpan! Ini rekomendasi awal untukmu.');
    }

    /**
     * Dismiss the "Tell us what you like" banner on the home page.
     * The banner is rendered only when session('onboarding.pending') is true,
     * so clearing the flag is the cheapest way to suppress it for the rest
     * of the session. A persistent dismissal would require a column on the
     * user — overkill for a single nudge.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $request->session()->forget('onboarding.pending');
        return response()->json(['ok' => true]);
    }
}
