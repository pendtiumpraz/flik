<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Security\TwoFactorService;
use App\Support\SecurityEvents;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 2FA / TOTP lifecycle.
 *
 * Setup → Confirm → (login flow detects two_factor_confirmed_at) → Challenge
 * → Verify (TOTP or recovery code) → fully authenticated session.
 *
 * Session keys used:
 *   2fa.pending_user_id   set by SessionsController::store after a valid
 *                         password but before TOTP — temporary, cleared on
 *                         success/cancel.
 *   2fa.passed            true after a successful challenge — read by the
 *                         TwoFactorVerified middleware to allow access.
 *   2fa.setup_secret      pending secret while the user is in /2fa/setup
 *                         (not yet confirmed → not yet persisted as active).
 *   2fa.setup_recovery    pending recovery codes shown on /2fa/setup so we
 *                         can persist the same set the user wrote down.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $tfa,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Setup (auth required) ───────────────────────────────────────────

    /**
     * Show the QR code, the secret in plain text (for manual entry), and
     * a fresh batch of recovery codes.  The codes are pinned in session
     * until the user confirms — that way refreshing the page doesn't
     * silently rotate the secret.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect('/profile')
                ->with('info', '2FA sudah aktif. Nonaktifkan dulu jika ingin re-setup.');
        }

        $secret = (string) $request->session()->get('2fa.setup_secret', '');
        if ($secret === '') {
            $secret = $this->tfa->generateSecret();
            $request->session()->put('2fa.setup_secret', $secret);
        }

        $recoveryCodes = $request->session()->get('2fa.setup_recovery');
        if (!is_array($recoveryCodes) || $recoveryCodes === []) {
            $recoveryCodes = $this->tfa->generateRecoveryCodes();
            $request->session()->put('2fa.setup_recovery', $recoveryCodes);
        }

        return view('auth.2fa.setup', [
            'secret' => $secret,
            'qrUri' => $this->tfa->qrCodeUrl($user, $secret),
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    /**
     * User typed the 6-digit code from their authenticator. Persist the
     * (already-pending) secret + recovery codes, stamp confirmed_at, and
     * mark this session as having passed 2FA so we don't immediately
     * bounce them to the challenge screen.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $pendingSecret = (string) $request->session()->get('2fa.setup_secret', '');
        $pendingRecovery = $request->session()->get('2fa.setup_recovery', []);

        if ($pendingSecret === '' || !is_array($pendingRecovery) || $pendingRecovery === []) {
            return redirect()->route('2fa.setup')
                ->with('error', 'Sesi setup kedaluwarsa. Silakan ulangi.');
        }

        if (!$this->tfa->verify($pendingSecret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'Kode tidak valid. Pastikan jam HP kamu akurat lalu coba lagi.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => $pendingSecret,
            'two_factor_recovery_codes' => $pendingRecovery,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget(['2fa.setup_secret', '2fa.setup_recovery']);
        $request->session()->put('2fa.passed', true);

        $this->safeAudit(SecurityEvents::TWO_FACTOR_ENABLED, $user, [
            'recovery_codes_issued' => count($pendingRecovery),
        ]);

        return redirect('/profile')
            ->with('success', '2FA berhasil diaktifkan. Simpan kode pemulihan di tempat aman.');
    }

    /**
     * Disable 2FA — requires the user's current password as a second-factor
     * proof of presence (not just session hijack).
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password salah.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $request->session()->forget(['2fa.passed', '2fa.pending_user_id']);

        $this->safeAudit(SecurityEvents::TWO_FACTOR_DISABLED, $user, [
            'self_initiated' => true,
        ]);

        return redirect('/profile')->with('success', '2FA dinonaktifkan.');
    }

    // ── Challenge (no auth — session-gated by 2fa.pending_user_id) ──────

    /**
     * Shown immediately after a valid password when the user has 2FA
     * enabled.  Auth::user() is intentionally null here — we only have a
     * pending user id pinned in the session.
     */
    public function challenge(Request $request): View|RedirectResponse
    {
        $pendingId = $request->session()->get('2fa.pending_user_id');

        if (!$pendingId) {
            return redirect()->route('login');
        }

        return view('auth.2fa.challenge');
    }

    /**
     * Validates the TOTP code (or a recovery code) and finishes login.
     * Recovery codes are case-insensitive and burned on use.
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:32'],
        ]);

        $pendingId = $request->session()->get('2fa.pending_user_id');
        if (!$pendingId) {
            return redirect()->route('login')
                ->with('error', 'Sesi login kedaluwarsa. Silakan masuk lagi.');
        }

        /** @var User|null $user */
        $user = User::find($pendingId);
        if (!$user || !$user->hasTwoFactorEnabled()) {
            $request->session()->forget('2fa.pending_user_id');
            return redirect()->route('login')->with('error', 'Akun tidak valid.');
        }

        $code = trim((string) $request->input('code'));
        $isDigits = preg_match('/^\d{6,8}$/', $code) === 1;
        $passed = false;

        if ($isDigits) {
            $passed = $this->tfa->verify((string) $user->two_factor_secret, $code);
        }

        if (!$passed) {
            // Try recovery code path (case-insensitive, single-use).
            $passed = $this->tfa->consumeRecoveryCode($user, $code);
            if ($passed) {
                Log::info('2FA recovery code used', ['user_id' => $user->id]);
            }
        }

        if (!$passed) {
            $this->safeAudit(SecurityEvents::TWO_FACTOR_FAILED, $user, [
                'email' => $user->email,
                'mode'  => $isDigits ? 'totp' : 'recovery_code',
            ]);

            throw ValidationException::withMessages([
                'code' => 'Kode tidak valid atau sudah digunakan.',
            ]);
        }

        // Promote the pending session to a fully authenticated one.
        Auth::login($user, (bool) $request->session()->pull('2fa.remember', false));
        $request->session()->forget('2fa.pending_user_id');
        $request->session()->put('2fa.passed', true);
        $request->session()->regenerate();

        $this->safeAudit(SecurityEvents::TWO_FACTOR_VERIFIED, $user, [
            'email' => $user->email,
            'mode'  => $isDigits ? 'totp' : 'recovery_code',
        ]);
        // The post-2FA login is also a LOGIN_SUCCESS — emit it here so the
        // SessionsController no-2FA branch and this branch produce the same
        // login event for analytics / dashboards.
        $this->safeAudit(SecurityEvents::LOGIN_SUCCESS, $user, [
            'email'      => $user->email,
            'two_factor' => true,
        ]);

        $destination = $user->isStaff() ? $user->adminDashboardUrl() : '/movies';

        return redirect()->intended($destination)->with('success', 'Welcome ' . $user->name);
    }

    /**
     * Best-effort audit write — never break the 2FA flow on log failure.
     *
     * @param  array<string,mixed>  $meta
     */
    private function safeAudit(string $event, ?User $user, array $meta = []): void
    {
        try {
            $this->audit->security($event, $user, $meta, $user);
        } catch (Throwable $e) {
            Log::warning('TwoFactorController: audit write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
