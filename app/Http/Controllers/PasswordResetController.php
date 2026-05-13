<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\CaptchaPassed;
use App\Rules\NotBreached;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use App\Support\SecurityEvents;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps Laravel's built-in PasswordBroker with FLiK security extras:
 *
 *   - Both the request- and update-side return generic messages so we never
 *     leak whether a given email is registered (account-enumeration defence).
 *   - Request endpoint is hard-throttled to 3/hour/IP via the route layer.
 *   - update() runs the password through {@see StrongPassword} + {@see NotBreached}.
 *   - Single-use token: enforced by {@see Password::reset()} (default Laravel
 *     behaviour deletes the row from `password_resets` on success).
 *   - All existing sessions for the user are invalidated after reset.
 */
final class PasswordResetController extends Controller
{
    /** Generic message returned for both success + "email not found" cases. */
    private const REQUEST_RESPONSE = 'Jika email kamu terdaftar, kami sudah mengirim tautan reset password ke inbox.';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function showRequest(): View
    {
        return view('auth.passwords.email');
    }

    /**
     * POST /forgot-password — fires Password::sendResetLink and ALWAYS returns
     * the same flash message so the response is identical for valid + invalid
     * email addresses (timing-safe enough for the threat model).
     */
    public function request(Request $request): RedirectResponse
    {
        // Cloudflare Turnstile fronts this endpoint when keys are configured.
        // The route is also IP-throttled to 3/hour, but the CAPTCHA adds a
        // human-in-the-loop step that defeats automated email-enumeration
        // sweeps trying to fingerprint registered addresses. CaptchaPassed
        // is a graceful no-op when TURNSTILE_* env vars are unset.
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'cf-turnstile-response' => ['nullable', 'string', new CaptchaPassed],
        ]);

        // Result is intentionally ignored — we don't want to differentiate
        // RESET_LINK_SENT / INVALID_USER / RESET_THROTTLED in the response.
        // Laravel still logs the broker's actual outcome internally.
        Password::broker()->sendResetLink($request->only('email'));

        // Audit the request regardless of whether the email matched a row —
        // ops/IR need to see the volume of requests so brute-force probes
        // show up in the security dashboard. Whether the email matched is
        // included in meta but the public response is identical either way.
        $email = (string) $request->input('email');
        $candidate = $email !== '' ? User::where('email', $email)->first() : null;

        $this->safeAudit(
            event: SecurityEvents::PASSWORD_RESET_REQUESTED,
            subject: $candidate,
            meta: ['email' => $email, 'matched' => $candidate !== null],
            user: $candidate,
        );

        return back()->with('status', self::REQUEST_RESPONSE);
    }

    public function showReset(string $token, Request $request): View
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * POST /reset-password — validate strong + non-breached new password, run
     * Laravel's broker (token expiry + single-use enforcement), then nuke
     * every other session for the user (Sanctum tokens too, if present).
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', new StrongPassword, new NotBreached],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                // Mutator on the User model auto-bcrypts and stamps
                // `password_changed_at` (see User::setPasswordAttribute).
                $user->password = $password;
                $user->setRememberToken(Str::random(60));
                $user->save();

                // Wipe all server-side sessions for this user. Laravel 12
                // exposes `Auth::logoutOtherDevices()` but that requires the
                // current request to be authenticated — instead we delete
                // every row from the sessions table whose `user_id` matches.
                $this->purgeSessions($user);

                // Revoke any Sanctum/personal-access tokens just in case the
                // user has API-side tokens (best-effort — silently no-ops if
                // the relation isn't set up).
                if (method_exists($user, 'tokens')) {
                    try {
                        $user->tokens()->delete();
                    } catch (\Throwable) {
                        // Sanctum migrations not installed — ignore.
                    }
                }

                event(new PasswordReset($user));

                // Three audit rows so the security dashboard shows both the
                // password lifecycle (RESET_COMPLETED) and the side-effect
                // session revocation. PASSWORD_CHANGED is the canonical
                // "the hash mutated" event used by anomaly detection.
                $this->safeAudit(SecurityEvents::PASSWORD_RESET_COMPLETED, subject: $user, meta: ['email' => $user->email], user: $user);
                $this->safeAudit(SecurityEvents::PASSWORD_CHANGED, subject: $user, meta: ['email' => $user->email, 'via' => 'reset'], user: $user);
                $this->safeAudit(SecurityEvents::SESSION_REVOKED, subject: $user, meta: ['reason' => 'password_reset', 'scope' => 'all_sessions'], user: $user);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('success', 'Password berhasil direset. Silakan login dengan password baru.');
        }

        // For PASSWORD_RESET failure (invalid token / expired / bad email), we
        // surface a single generic error string keyed to the email field so we
        // don't leak which step failed. Translation files would normally split
        // these — we collapse them deliberately.
        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __('Tautan reset tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.')]);
    }

    /**
     * Best-effort audit write — never break the password-reset flow on log failure.
     *
     * @param  array<string,mixed>  $meta
     */
    private function safeAudit(string $event, ?User $subject, array $meta = [], ?User $user = null): void
    {
        try {
            $this->audit->security($event, $subject, $meta, $user);
        } catch (Throwable $e) {
            \Log::warning('PasswordResetController: audit write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete every row from the `sessions` table that belongs to the user,
     * if the database session driver is in use. No-op on file/cookie/Redis
     * drivers since there's no central store we can target safely without
     * driver-specific code.
     */
    private function purgeSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            \DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        } catch (\Throwable $e) {
            // Sessions table missing on a fresh app — ignore.
            \Log::warning('PasswordResetController: failed to purge sessions', [
                'user_id' => $user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
