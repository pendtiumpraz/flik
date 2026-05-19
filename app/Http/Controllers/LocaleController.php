<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Explicit locale switch endpoint.
 *
 * Wired by routes/web.php:
 *   POST /locale/{code}  → LocaleController@switch
 *
 * The middleware (App\Http\Middleware\SetLocale) handles every other
 * resolution branch (query, header, etc.); this controller is the canonical
 * "user clicked the flag in the header" path:
 *
 *   - validates the code against config('locales.available') so an attacker
 *     can't poison the session with arbitrary input,
 *   - writes it to the session AND to users.preferred_locale (when authed)
 *     so the choice survives across devices,
 *   - bounces back to the referring page so the user stays in context.
 *
 * Anonymous visitors are supported — the row write is skipped, the session
 * still gets the value.
 */
class LocaleController extends Controller
{
    public function switch(Request $request, string $code): RedirectResponse
    {
        $available = array_keys((array) config('locales.available', []));

        // Whitelist check — silently fall through to current locale rather
        // than 422'ing, the worst case is the user notices the flag didn't
        // change and tries again. Throwing here would feel broken.
        if (! in_array($code, $available, true)) {
            return back();
        }

        $request->session()->put('locale', $code);

        $user = $request->user();
        if ($user !== null) {
            // forceFill so the value lands even though `preferred_locale`
            // isn't in $fillable — saves a one-liner of $fillable churn
            // every time a peer adds a new column.
            $user->forceFill(['preferred_locale' => $code])->save();
        }

        return back()->with('status', __('Language updated'));
    }
}
