<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\CaptchaPassed;
use App\Services\Audit\AuditLogger;
use App\Services\Security\LoginThrottle;
use App\Support\SecurityEvents;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SessionsController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Authenticate the user.
     *
     * Brute-force protection layers (order matters — all run BEFORE
     * validation so we don't leak which emails exist via timing):
     *   1. Per-account lockout      → 429 with friendly Indonesian message
     *   2. Per-IP lockout           → 429 with friendly Indonesian message
     *   3. Progressive sleep delay  → 0/1/2/4/8/16s ladder per recent fail
     *   4. validate + Hash::check   → on miss, record failure & throw
     *   5. on success → record + unlock the email
     *
     * Outside this controller:
     *   - Named 'login' RateLimiter (RouteServiceProvider) caps requests
     *     per IP per minute as a coarse outer guard.
     *
     * 2FA interplay: a correct-password-but-pending-2FA result counts as a
     * SUCCESS for throttle purposes (records success, clears the failure
     * counter). The 2FA challenge has its own rate limiting elsewhere.
     *
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, LoginThrottle $throttle)
    {
        // Pull email + ip + ua up-front so we can run lockout checks even
        // when validation would otherwise reject a malformed POST. Email
        // may be missing on a malformed request — fall back to empty
        // string so the throttle helpers don't blow up.
        $email = (string) $request->input('email', '');
        $ip    = (string) $request->ip();
        $ua    = $request->userAgent();

        // ── Layer 1 + 2: lockout checks ────────────────────────────────
        // IP check first because it's cheaper AND it protects when the
        // attacker rotates email addresses (credential stuffing).
        $lockoutMinutes = (int) config('security.throttle.lockout_minutes', 15);

        if ($throttle->isIpLockedOut($ip)) {
            $this->safeAudit(static fn (AuditLogger $a) => $a->security(
                SecurityEvents::LOGIN_LOCKED_OUT,
                meta: ['email' => $email, 'scope' => 'ip', 'ip' => $ip],
            ));

            throw ValidationException::withMessages([
                'throttle' => "Terlalu banyak percobaan login gagal dari jaringan ini. Coba lagi dalam {$lockoutMinutes} menit.",
            ])->status(429);
        }

        if ($email !== '' && $throttle->isLockedOut($email)) {
            $this->safeAudit(static fn (AuditLogger $a) => $a->security(
                SecurityEvents::LOGIN_LOCKED_OUT,
                meta: ['email' => $email, 'scope' => 'account'],
            ));

            throw ValidationException::withMessages([
                'throttle' => "Akun ini terkunci sementara karena terlalu banyak percobaan login gagal. Coba lagi dalam {$lockoutMinutes} menit atau hubungi support.",
            ])->status(429);
        }

        // ── Layer 3: progressive delay ─────────────────────────────────
        // Cheap on the first miss, painful on the fifth. Skipped when no
        // email was supplied (avoids penalising e.g. a CSRF retry).
        if ($email !== '') {
            $delay = $throttle->progressiveDelaySeconds($email);
            if ($delay > 0) {
                sleep($delay);
            }
        }

        // ── Validate ───────────────────────────────────────────────────
        // Cloudflare Turnstile CAPTCHA fronts the form when keys are set.
        // CaptchaPassed is a graceful no-op when keys are absent so dev
        // login keeps working without Cloudflare configured.
        $request->validate([
            'cf-turnstile-response' => ['nullable', 'string', new CaptchaPassed],
        ]);

        $attributes = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Look up the user without logging them in yet — we need to know
        // whether 2FA is required *before* establishing the auth session.
        /** @var User|null $user */
        $user = User::where('email', $attributes['email'])->first();

        if (!$user || !Hash::check($attributes['password'], (string) $user->password)) {
            // Record failure then bail with the same generic error message
            // (don't leak whether the email exists).
            $throttle->record($attributes['email'], $ip, $ua, success: false);

            $userForAudit = $user;
            $emailForAudit = $attributes['email'];
            $this->safeAudit(static fn (AuditLogger $a) => $a->security(
                SecurityEvents::LOGIN_FAILED,
                subject: $userForAudit, // null when email is unknown — that's fine
                meta: [
                    'email'  => $emailForAudit,
                    'reason' => $userForAudit ? 'bad_password' : 'unknown_email',
                ],
                user: $userForAudit,
            ));

            throw ValidationException::withMessages([
                'email' => 'Your provieded credentials could not be verified.',
            ]);
        }

        // Credentials passed → password-stage success.
        // Record + clear the failure counter so a legit user who fat-
        // fingered a couple of times isn't carrying penalty into 2FA.
        $throttle->record($attributes['email'], $ip, $ua, success: true);
        $throttle->unlock($attributes['email']);

        // ── 2FA branch ──────────────────────────────────────────────────
        // Pin the user id to the session and bounce to the challenge.
        // We deliberately do NOT call auth()->login() here so a stolen
        // password alone cannot make API/admin requests succeed.
        if ($user->hasTwoFactorEnabled()) {
            session()->put('2fa.pending_user_id', $user->id);
            session()->put('2fa.remember', (bool) request()->boolean('remember'));
            // Don't regenerate yet — we want the pending flag to survive.
            return redirect()->route('2fa.challenge');
        }

        // ── No-2FA branch (unchanged behaviour) ─────────────────────────
        auth()->login($user, (bool) request()->boolean('remember'));
        session()->regenerate();
        // Mark this session as "passed 2FA" so the TwoFactorVerified
        // middleware lets the request through (users without 2FA pass
        // trivially — the flag's only job is to gate users WITH 2FA who
        // somehow ended up logged-in without completing the challenge).
        session()->put('2fa.passed', true);

        $loggedInUser = $user;
        $this->safeAudit(static fn (AuditLogger $a) => $a->security(
            SecurityEvents::LOGIN_SUCCESS,
            subject: $loggedInUser,
            meta: ['email' => $loggedInUser->email, 'two_factor' => false],
            user: $loggedInUser,
        ));

        $destination = $user->isStaff() ? $user->adminDashboardUrl() : '/movies';

        return redirect()->intended($destination)->with('success', 'Welcome ' . $user->name);
    }

    /**
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function destroy()
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $loggedOutUser = $user;
            $this->safeAudit(static fn (AuditLogger $a) => $a->security(
                SecurityEvents::LOGOUT,
                subject: $loggedOutUser,
                meta: ['email' => $loggedOutUser->email],
                user: $loggedOutUser,
            ));
        }

        auth()->logout();
        session()->forget(['2fa.passed', '2fa.pending_user_id', '2fa.remember']);

        return redirect('/')->with('success', 'you\'re out');
    }

    /**
     * Resolve AuditLogger from the container and run $cb against it,
     * swallowing any exception so audit failures can never break the
     * auth flow. Container-resolved (not constructor-injected) because
     * this controller predates AuditLogger and we don't want to break
     * existing tests that build it via `new`.
     */
    private function safeAudit(Closure $cb): void
    {
        try {
            $cb(app(AuditLogger::class));
        } catch (Throwable $e) {
            Log::warning('SessionsController: audit write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
