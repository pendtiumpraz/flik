<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use App\Models\UserPreference;
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

        return redirect('/movies')->with('success', 'Preferensi tersimpan! Ini rekomendasi awal untukmu.');
    }
}
