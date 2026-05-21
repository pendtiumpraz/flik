<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Implements the 3 endpoints required for the Laravel email-verification flow:
 *
 *   GET  /email/verify                       → notice() view
 *   GET  /email/verify/{id}/{hash}           → verify() (signed)
 *   POST /email/verification-notification    → resend() (throttled)
 */
final class EmailVerificationController extends Controller
{
    /**
     * "Please verify your email" landing page. Used as the redirect target by
     * the `verified` middleware whenever an unverified user hits a gated route.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user !== null && $user->hasVerifiedEmail()) {
            return redirect()->intended('/movies');
        }

        return view('auth.verify');
    }

    /**
     * Handle the signed verification link clicked from the email.
     * Laravel's {@see EmailVerificationRequest} performs:
     *   - signature validation
     *   - hash equality check (sha1(email))
     *   - 401 on mismatch
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Already verified — short-circuit, idempotent.
        if ($user->hasVerifiedEmail()) {
            return $this->postVerifyRedirect($request, '/movies?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->postVerifyRedirect($request, '/movies?verified=1')
            ->with('success', 'Email kamu berhasil diverifikasi. Selamat menonton!');
    }

    /**
     * Post-verification routing — push freshly-verified users into the
     * onboarding quiz if they never completed it (gates the cold-start
     * recommender). Established users keep the historical `/movies` flow.
     */
    private function postVerifyRedirect(Request $request, string $default): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'preferences') && !$user->preferences()->exists()) {
            $request->session()->put('onboarding.pending', true);
            return redirect()->route('onboarding.quiz');
        }

        return redirect()->intended($default);
    }

    /**
     * Resend the verification email. Route-level throttling (6/min) is applied
     * in routes/web.php; we still short-circuit verified users so we don't
     * spam them on a stale tab.
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended('/movies');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('success', 'Tautan verifikasi baru telah dikirim ke email kamu.');
    }
}
