<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve and apply the request locale.
 *
 * Resolution order (highest priority first — first match wins, evaluated
 * on EVERY request — no first-visit short-circuits):
 *   1. `?lang=XX` query param (one-shot override + share-friendly URLs).
 *   2. `session('locale')` (this-tab preference, written ONLY when the
 *      user explicitly switches via `?lang=...` or POST /locale/...).
 *   3. `auth()->user()->preferred_locale` (cross-device default).
 *   4. `Accept-Language` request header (cold visit).
 *   5. `config('locales.default')` (hard fallback).
 *
 * Every resolved value is validated against `config('locales.available')` so
 * an attacker can never poison the session with an arbitrary locale string
 * that would later be embedded into <html lang="..."> as XSS.
 *
 * Registered in App\Http\Kernel as part of the `web` group AFTER StartSession
 * so the session is hydrated before we read it.
 *
 * Historical note: this middleware used to "pin" the resolved locale into
 * the session on the very first visit. That pin made branches 2 and 3
 * permanently dead code — a user who later updated their preferred_locale
 * in /profile saw zero effect until they manually used the lang switcher.
 * The pin is now ONLY written when the locale comes from `?lang=...`
 * (explicit user action), so updates to user.preferred_locale take effect
 * on the next request.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys((array) config('locales.available', []));
        $default = (string) (config('locales.default') ?: config('app.locale', 'id'));

        $resolved = $this->resolve($request, $available, $default);

        // app()->setLocale() drives __() / trans() / @lang for the rest of
        // the request lifecycle. We also push Carbon so date helpers like
        // ->diffForHumans() and ->translatedFormat() respect the choice.
        app()->setLocale($resolved);
        try {
            Carbon::setLocale($resolved);
        } catch (\Throwable) {
            // Carbon fails gracefully for unknown locales — fall back to default.
            Carbon::setLocale($default);
        }

        // Persist into the session ONLY when the user explicitly switched
        // via the `?lang=XX` query param. Defaults from Accept-Language or
        // preferred_locale must NOT be pinned — otherwise the session
        // would shadow every later change to user.preferred_locale.
        $queryLang = (string) $request->query('lang', '');
        if ($queryLang !== ''
            && in_array($queryLang, $available, true)
            && $request->session()->get('locale') !== $resolved
        ) {
            $request->session()->put('locale', $resolved);
        }

        return $next($request);
    }

    /**
     * Walk the priority chain and return the first valid locale code.
     */
    protected function resolve(Request $request, array $available, string $default): string
    {
        // 1. Query param — explicit override.
        $query = (string) $request->query('lang', '');
        if ($query !== '' && in_array($query, $available, true)) {
            return $query;
        }

        // 2. Session — this-tab preference.
        $session = (string) $request->session()->get('locale', '');
        if ($session !== '' && in_array($session, $available, true)) {
            return $session;
        }

        // 3. Authenticated user preference (cross-device default).
        $user = $request->user();
        if ($user !== null) {
            // Read raw attribute so we don't trigger accessors / encryption casts.
            $userLocale = (string) ($user->getAttribute('preferred_locale') ?? '');
            if ($userLocale !== '' && in_array($userLocale, $available, true)) {
                return $userLocale;
            }
        }

        // 4. Accept-Language header. Parse with weights and pick the highest
        // available match. Falls through silently when nothing matches.
        $header = (string) $request->headers->get('Accept-Language', '');
        if ($header !== '') {
            foreach ($this->parseAcceptLanguage($header) as $tag) {
                // Try full tag first (en-US) then primary subtag (en).
                if (in_array($tag, $available, true)) {
                    return $tag;
                }
                $primary = strtolower(strtok($tag, '-'));
                if ($primary !== false && in_array($primary, $available, true)) {
                    return $primary;
                }
            }
        }

        // 5. Hard fallback.
        return in_array($default, $available, true) ? $default : ($available[0] ?? 'en');
    }

    /**
     * Parse an Accept-Language header into an ordered list of BCP-47 tags
     * (highest q-weight first). Returns lowercased tags ready for matching.
     *
     * Example input: "en-US,en;q=0.9,id;q=0.8" → ["en-us", "en", "id"]
     *
     * @return array<int, string>
     */
    protected function parseAcceptLanguage(string $header): array
    {
        $entries = [];
        foreach (explode(',', $header) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = explode(';', $chunk);
            $tag = strtolower(trim($parts[0]));
            $q = 1.0;
            foreach (array_slice($parts, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $q = (float) substr(trim($param), 2);
                }
            }
            // Filter out grossly malformed tags so they never reach the
            // available-locale check (defence-in-depth: validation still
            // happens in resolve()).
            if ($tag !== '' && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', $tag)) {
                $entries[] = ['tag' => $tag, 'q' => $q];
            }
        }

        usort($entries, fn ($a, $b) => $b['q'] <=> $a['q']);

        return array_map(fn ($e) => $e['tag'], $entries);
    }
}
