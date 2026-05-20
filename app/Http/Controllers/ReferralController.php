<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReferralConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReferralController — user-facing surface for the refer-a-friend program.
 *
 *   GET /referrals       → dashboard (auth required)
 *   GET /r/{code}        → public capture, anonymous OK
 *
 * The /r/{code} endpoint is intentionally lightweight — it just stashes
 * the code in a 30-day cookie + session value and bounces the visitor
 * to register/home. The actual attribution write happens inside
 * RegisterController::store after the new User row exists (see the
 * patch in that controller).
 */
class ReferralController extends Controller
{
    /**
     * Cookie name for the referral capture. Mirrored in
     * RegisterController::store; if you rename it here, rename it
     * there too.
     */
    public const COOKIE_KEY = 'flik_ref';

    /**
     * Cookie / session TTL. 30 days is long enough to cover the
     * "evaluate the platform, then sign up" gap without ever
     * outliving a typical browser session reset.
     */
    public const COOKIE_TTL_MINUTES = 60 * 24 * 30;

    /**
     * Logged-in user's referral dashboard.
     *
     * Shows their code + share link + a list of every conversion the
     * ledger holds for them. Aggregates (counts, coins earned) are
     * read from the denormalised users.referrals_* columns to avoid
     * COUNT() over a potentially-large ledger on every page load.
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();

        // Lazy-bootstrap a referral code for legacy users who registered
        // before the column existed. The trait helper is idempotent.
        if (empty($user->referral_code)) {
            $user->generateReferralCode();
            $user->refresh();
        }

        $conversions = ReferralConversion::query()
            ->with(['referred:id,name,username,email'])
            ->where('referrer_user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get();

        $stats = [
            'total'      => $conversions->count(),
            'pending'    => $conversions->where('status', ReferralConversion::STATUS_PENDING)->count(),
            'qualified'  => $conversions->where('status', ReferralConversion::STATUS_QUALIFIED)->count(),
            'rewarded'   => $conversions->where('status', ReferralConversion::STATUS_REWARDED)->count(),
            'coins_earned' => (int) ($user->referrals_rewards_earned ?? 0),
        ];

        return view('referrals.dashboard', [
            'user'        => $user,
            'shareLink'   => $user->referralsLink(),
            'code'        => $user->referral_code,
            'conversions' => $conversions,
            'stats'       => $stats,
        ]);
    }

    /**
     * Public capture endpoint hit by anyone clicking /r/{code}.
     *
     * Stores the code in a queued cookie (30-day TTL) and in the
     * session so RegisterController::store can pick it up after the
     * new User row commits. Authenticated visitors get a no-op
     * redirect to the home page so the link is still shareable in
     * the "look at this" sense.
     *
     * The code constraint at the route level pins the format to
     * 12 chars of the trait's alphabet, so an invalid code can't
     * even reach this method — we still defensively skip writing
     * the cookie when somehow it does.
     */
    public function redirect(Request $request, string $code): RedirectResponse
    {
        $normalised = strtoupper(trim($code));

        // Don't burn a cookie on a malformed code.
        if (preg_match('/^[A-Z0-9]{6,16}$/', $normalised) === 1) {
            // Session + cookie both — session covers the "register in
            // same tab" path, cookie covers "browse first, sign up
            // tomorrow" path. RegisterController checks both.
            session([self::COOKIE_KEY => $normalised]);

            cookie()->queue(cookie(
                name: self::COOKIE_KEY,
                value: $normalised,
                minutes: self::COOKIE_TTL_MINUTES,
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            ));
        }

        // Already authenticated? Send them home — they can't be
        // referred again, but the link should still feel valid.
        if ($request->user() !== null) {
            return redirect()->intended('/movies');
        }

        return redirect()->route('register')
            ->with('success', 'Daftar sekarang untuk dapat bonus referral!');
    }
}
