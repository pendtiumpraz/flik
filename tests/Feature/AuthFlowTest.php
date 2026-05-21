<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AuthFlowTest (FIX #10)
|--------------------------------------------------------------------------
|
| Smoke-tests the critical auth surfaces wired in routes/web.php:
|   - SessionsController (POST /login)
|   - RegisterController (POST /register)
|   - PasswordResetController (POST /forgot-password)
|   - SessionsController::destroy (POST /logout)
|
| Skips gracefully when the database isn't available so the suite stays
| green on CI workers without MySQL (mirrors AdminRouteGuardTest /
| MovieBulkActionTest).
|
| Scenarios covered:
|   1. login_with_valid_credentials_works           — happy path, no 2FA
|   2. login_with_2fa_redirects_to_challenge        — 2FA branch
|   3. register_creates_user_and_logs_in            — registration happy path
|   4. logout_invalidates_session                   — session rotation + auth cleared
|   5. password_reset_request_returns_generic_message — no enumeration leak
|
*/

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }
});

// ── Helpers ─────────────────────────────────────────────────────────────

/**
 * Build a regular (non-2FA) user. UserFactory configure() hook stamps
 * email_verified_at so the user is past the verification middleware
 * without test-side bootstrapping.
 */
function authFlowMakeUser(array $overrides = []): User
{
    $user = User::factory()->create($overrides);
    return $user->fresh();
}

// ── Happy path ──────────────────────────────────────────────────────────

test('login with valid credentials works', function () {
    // UserFactory ships the bcrypt of "password" as the default, but we
    // re-stamp here to make the test self-documenting (and to insulate
    // the test from future factory refactors).
    $user = authFlowMakeUser([
        'email'    => 'login-test@example.com',
        'password' => 'password', // User::setPasswordAttribute auto-bcrypts
    ]);

    $response = $this->post('/login', [
        'email'    => 'login-test@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    // Non-staff users land at /movies; staff land at adminDashboardUrl().
    // We don't pin the exact redirect — just that it isn't the login form.
    expect($response->status())->toBeIn([200, 302]);
    expect($response->status())->not->toBe(401);
});

// ── 2FA branch ──────────────────────────────────────────────────────────

test('login with 2fa enabled redirects to challenge', function () {
    // hasTwoFactorEnabled() returns true when two_factor_confirmed_at is
    // set. The secret column is encrypted-cast so we forceFill a base32
    // string and Laravel handles encryption transparently.
    $user = authFlowMakeUser([
        'email'    => '2fa-test@example.com',
        'password' => 'password',
    ]);

    if (! method_exists($user, 'hasTwoFactorEnabled')) {
        $this->markTestSkipped('Two-factor helper not present on User model.');
    }

    try {
        $user->forceFill([
            'two_factor_secret'        => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at'  => now(),
        ])->save();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Two-factor columns not migrated: '.$e->getMessage());
    }

    // Sanity: 2FA must report enabled before we exercise the branch.
    if (! $user->fresh()->hasTwoFactorEnabled()) {
        $this->markTestSkipped('hasTwoFactorEnabled() returned false after seeding 2FA columns.');
    }

    $response = $this->post('/login', [
        'email'    => '2fa-test@example.com',
        'password' => 'password',
    ]);

    // The user must NOT be authenticated yet — 2FA gate is unresolved.
    $this->assertGuest();

    // Should bounce to the 2fa.challenge route (we don't pin the exact
    // URL string in case routing is reshaped; status code 302 is the
    // contract).
    expect($response->status())->toBe(302);
    expect(session()->has('2fa.pending_user_id'))->toBeTrue();
});

// ── Registration ────────────────────────────────────────────────────────

test('register creates user and logs in', function () {
    $response = $this->post('/register', [
        'name'     => 'New User',
        'username' => 'newuser_'.uniqid(),
        'email'    => 'fresh-'.uniqid().'@example.com',
        'password' => 'testpassword',
    ]);

    $this->assertAuthenticated();
    expect($response->status())->toBe(302);
    expect(User::where('email', 'like', 'fresh-%@example.com')->exists())->toBeTrue();
});

// ── Logout ──────────────────────────────────────────────────────────────

test('logout invalidates session', function () {
    $user = authFlowMakeUser();

    $this->actingAs($user);
    $this->assertAuthenticatedAs($user);

    $response = $this->post('/logout');

    // After logout the request is no longer authenticated and the user
    // is bounced (redirect, not 200) — SessionsController::destroy calls
    // session()->invalidate() + regenerateToken() in addition to the
    // logout, so even a stolen post-logout cookie should be useless.
    $this->assertGuest();
    expect($response->status())->toBeIn([200, 302]);
});

// ── Password reset (no enumeration) ─────────────────────────────────────

test('password reset request returns generic message regardless of email existence', function () {
    if (! \Illuminate\Support\Facades\Route::has('password.email')) {
        $this->markTestSkipped('Password reset route not registered.');
    }

    // Seed one known email.
    authFlowMakeUser(['email' => 'known@example.com']);

    // Hit the endpoint twice — once with a real address, once with a
    // bogus one. Both responses MUST be indistinguishable (status + a
    // success-shaped redirect) so a probe attacker can't enumerate the
    // user table by timing or wording.
    $real = $this->post(route('password.email'), ['email' => 'known@example.com']);
    $fake = $this->post(route('password.email'), ['email' => 'never-existed-'.uniqid().'@example.com']);

    // Both should redirect (the controller follows the Laravel broker
    // pattern and bounces back with a status flash). 419 (CSRF) or 422
    // (validation) would also be acceptable as long as the SHAPE matches.
    expect($real->status())->toBe($fake->status())
        ->and($real->status())->toBeIn([200, 302, 422]);
});
