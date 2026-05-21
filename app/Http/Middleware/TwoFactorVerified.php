<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate auth-protected routes behind a successful 2FA challenge.
 *
 * Pass-through cases (no bounce):
 *   - User is not logged in            → let `auth` middleware handle it.
 *   - User has no 2FA configured       → trivially passes.
 *   - Session has `2fa.passed = true`  → already cleared the challenge.
 *
 * Bounce case:
 *   - User is logged in *and* has 2FA enabled *and* the session has not
 *     been marked as having passed the challenge → 302 → /2fa/challenge.
 *
 * This handles the edge case where Auth::login() was called somewhere
 * other than SessionsController (e.g. Socialite callback, impersonation
 * tooling) — we still demand TOTP before sensitive routes load.
 */
class TwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Defensive: hasTwoFactorEnabled() may not exist OR the
        // two_factor_* columns may be missing if the 2FA migration hasn't
        // run yet. Catch every failure mode so admin routes never 500
        // just because 2FA infrastructure is half-deployed.
        $hasTwoFactor = false;
        try {
            if (method_exists($user, 'hasTwoFactorEnabled')) {
                $hasTwoFactor = (bool) $user->hasTwoFactorEnabled();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TwoFactorVerified: hasTwoFactorEnabled() failed — passing through', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return $next($request);
        }

        if (!$hasTwoFactor) {
            return $next($request);
        }

        if ($request->session()->get('2fa.passed') === true) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '2FA verification required',
                'redirect' => route('2fa.challenge'),
            ], 423);
        }

        return redirect()->route('2fa.challenge');
    }
}
