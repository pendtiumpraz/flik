<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class LoginController extends Controller
{
    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Google OAuth callback.
     *
     * Account resolution order (FIX #6 — account-linking conflict fix):
     *   1. Match by provider_id (returning Google user).
     *   2. Fall back to match by email — links Google to an existing
     *      password-based account instead of crashing on the unique-email
     *      constraint.
     *   3. Genuinely new → create + auto-verify email (OAuth identity
     *      providers vouch for the address).
     *
     * After loading $user, ALWAYS check 2FA: if the user has TOTP enabled
     * we pin them as pending and bounce to the challenge — closing the
     * "OAuth bypasses 2FA" gap from docs/audit/01-auth-login.md. The
     * `2fa` middleware applied to the admin group is the belt-and-braces
     * second layer.
     */
    public function handleProviderCallback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // State mismatch, denied consent, or transient OAuth glitch —
            // bounce back to login with a friendly flash instead of a 500.
            Log::warning('LoginController: Socialite callback failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')
                ->with('error', 'Login Google gagal. Coba lagi atau gunakan email + password.');
        }

        // SECURITY: `provider_id` and `email_verified_at` are intentionally NOT
        // in User::$fillable (mass-assignment audit). Look the user up first
        // and write provider-set fields through forceFill so they actually land.
        /** @var User|null $user */
        $user = User::where('provider_id', $googleUser->getId())->first();

        if (! $user) {
            // Account-linking branch: existing password user with the same
            // email → attach Google as a secondary identity instead of
            // creating a duplicate (and crashing on users.email unique).
            /** @var User|null $existing */
            $existing = User::where('email', $googleUser->getEmail())->first();

            if ($existing) {
                // NB: only `provider_id` exists on the users table today —
                // the `provider` column was scoped out of the v1 migration.
                // Add it via a future migration if multi-provider support
                // (Apple, Facebook, etc.) lands; for now provider_id alone
                // is enough to dedupe the OAuth identity.
                $existing->forceFill([
                    'provider_id' => $googleUser->getId(),
                ])->save();

                $user = $existing;
            } else {
                // Genuinely new — create + auto-verify email since the OAuth
                // provider already proved ownership. Password is a random
                // 40-char string so the row satisfies any NOT NULL constraint
                // without ever being usable for password login. The user can
                // reset via /forgot-password to set a real one.
                $user = User::create([
                    'name' => $googleUser->getName() ?: 'Google User',
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(40)),
                ]);

                $user->forceFill([
                    'provider_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                ])->save();
            }
        }

        // ── 2FA gate (closes OAuth bypass) ──────────────────────────────
        // If the user enabled TOTP, do NOT log them in yet. Pin them as
        // pending and bounce to the challenge — same pattern used by
        // SessionsController::store's 2FA branch.
        if ($user->hasTwoFactorEnabled()) {
            session()->put('2fa.pending_user_id', $user->id);
            session()->put('2fa.remember', true);

            return redirect()->route('2fa.challenge');
        }

        // ── No-2FA branch ───────────────────────────────────────────────
        auth()->login($user, true);
        session()->regenerate();
        // Mark the session as 2FA-cleared so the TwoFactorVerified
        // middleware lets requests through (no-op for users without 2FA,
        // load-bearing the moment they enable it).
        session()->put('2fa.passed', true);

        // Route admins to their dashboard, non-admins to /movies. Mirrors
        // SessionsController::store so the post-login destination is
        // consistent across login surfaces.
        $destination = $user->isStaff() ? $user->adminDashboardUrl() : '/movies';

        return redirect()->intended($destination)
            ->with('success', 'Welcome ' . $user->name);
    }
}
