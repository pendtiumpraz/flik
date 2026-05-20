<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\UserPreference;
use App\Services\Referrals\ReferralService;
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
}
