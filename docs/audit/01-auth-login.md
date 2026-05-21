# Audit #1 — Authentication & Login

**Auditor**: Claude (swarm AUDIT)
**Date**: 2026-05-20
**Domain scope**: Login, register, 2FA (TOTP + recovery codes), password reset, email verification, lockout/brute-force, session management UI, login alerts (new device/country), Google OAuth, logout.

## Inventory

- **Email/password login** — `app/Http/Controllers/SessionsController.php` (create/store/destroy), view `resources/views/auth/login.blade.php`, routes `routes/web.php:113-120,172`.
- **Registration** — `app/Http/Controllers/RegisterController.php`, view `resources/views/auth/register.blade.php`, routes `routes/web.php:121-126`.
- **Google OAuth** — `app/Http/Controllers/LoginController.php` (`redirectToProvider`, `handleProviderCallback`), routes `routes/web.php:473-476`, config `config/services.php:52-56`.
- **2FA TOTP setup / confirm / disable** — `app/Http/Controllers/TwoFactorController.php`, service `app/Services/Security/TwoFactorService.php`, views `resources/views/auth/2fa/{setup,confirm,challenge}.blade.php`, routes `routes/web.php:166-177`, migration `database/migrations/2026_05_10_040078_add_two_factor_to_users.php`.
- **2FA challenge gate** — `app/Http/Middleware/TwoFactorVerified.php` (alias `'2fa'` in `app/Http/Kernel.php:107`).
- **Password reset** — `app/Http/Controllers/PasswordResetController.php`, views `resources/views/auth/passwords/{email,reset}.blade.php`, routes `routes/web.php:133-143`, rules `app/Rules/StrongPassword.php` + `app/Rules/NotBreached.php`, façade `app/Services/Security/PasswordService.php`.
- **Email verification** — `app/Http/Controllers/Auth/EmailVerificationController.php`, view `resources/views/auth/verify.blade.php`, routes `routes/web.php:151-163`, branded mailable `app/Notifications/Auth/VerifyEmailNotification.php` (referenced from `User::sendEmailVerificationNotification` `app/Models/User.php:51-54`).
- **Brute-force lockout** — `app/Services/Security/LoginThrottle.php` (per-account + per-IP + progressive delay), model `app/Models/LoginAttempt.php`, migration `2026_05_10_040080_create_login_attempts_table.php`, named limiter `app/Providers/RouteServiceProvider.php:72-76`.
- **Anti-bot honeypot** — `app/Http/Middleware/Honeypot.php`, blade component `resources/views/components/honeypot.blade.php`.
- **Cloudflare Turnstile CAPTCHA** — `app/Rules/CaptchaPassed.php` + `resources/views/components/captcha-turnstile.blade.php` (wired into login, register, forgot-password).
- **Session management UI** — `app/Http/Controllers/Profile/SessionController.php`, service `app/Services/Security/SessionManager.php`, view `resources/views/profile/sessions.blade.php`, routes `routes/web.php:232-238`, migration `2026_05_10_040082_create_sessions_table.php`.
- **Known device / trusted device** — model `app/Models/KnownDevice.php`, migration `2026_05_10_040083_create_known_devices_table.php`, policy `app/Policies/KnownDevicePolicy.php`.
- **Login alerts (new device / new country / geo velocity)** — `app/Services/Security/LoginAlertService.php`, event listener `app/Listeners/SendLoginAlert.php` subscribed to `Illuminate\Auth\Events\Login` in `EventServiceProvider`, mailable `app/Mail/NewDeviceLogin.php`.
- **Admin login unlock** — `AdminController::unlockLogin` at `routes/web.php:605-606`.
- **Logout** — `SessionsController::destroy`, route `routes/web.php:172`.
- **Tests** — `tests/Feature/AuthenticationTest.php`, `tests/Feature/RegistrationTest.php`.

## ✅ Working

- **Email/password login happy path** — `SessionsController::store` validates credentials, regenerates session, redirects to `/movies` (or staff dashboard). Verified end-to-end: route `routes/web.php:120` → controller line 48-172 → view `resources/views/auth/login.blade.php:62`. Test in `tests/Feature/AuthenticationTest.php:5`.
- **Brute-force lockout** — Per-account (5 fails / 15 min) and per-IP (20 fails / 15 min) ladders fire 429 with the `throttle` validation key; view renders the message at `resources/views/auth/login.blade.php:53-60`. Progressive 0/1/2/4/8/16-second sleep ladder enforced before validation (`SessionsController::store` line 89-93, `LoginThrottle::progressiveDelaySeconds`). Outer `throttle:login` (5/min/IP) named limiter at `RouteServiceProvider.php:72`.
- **Account-enumeration defence** — Both bad-email and bad-password return the same generic error (`SessionsController.php:130-132`), and the lockout check runs *before* validation, so timing is identical for unknown vs locked emails.
- **Registration with strong password policy** — `StrongPassword` rejects <10 chars, missing case/digit/symbol, 100-entry common-password blocklist, and identity-derived fragments (name/username/email-local-part). `NotBreached` queries HIBP k-anonymity, fails-open with 24h cache.
- **Email verification** — Branded mailable, `MustVerifyEmail` implemented on `User`, signed verification link, `verification.send` resend throttled (`routes/web.php:156-158`), notice page reachable from `RegisterController.php:88`. View is bilingual-ish (Indonesian copy).
- **Password reset (broker)** — Generic flash regardless of email existence, `password-reset` named limiter (3/hr/IP), single-use token enforced by Laravel broker, `StrongPassword`+`NotBreached` re-applied on update, all sessions purged on success (`PasswordResetController::purgeSessions`), Sanctum tokens revoked, audit rows emitted (`PASSWORD_RESET_COMPLETED`, `PASSWORD_CHANGED`, `SESSION_REVOKED`).
- **2FA TOTP** — Pure-PHP RFC-6238 implementation in `TwoFactorService` (sha1, 30s, 6 digits, ±1 step drift, `hash_equals` constant-time comparison). Setup → confirm → challenge → verify flow is functional. Recovery codes (8 × 10-hex-char) generated, persisted (encrypted-array cast at `User.php:82`), case-insensitive, single-use on consume. Disable requires current password.
- **TwoFactorVerified middleware** — Correctly checks `auth → 2fa.passed` flag; bounces to `2fa.challenge` for users with `hasTwoFactorEnabled()` whose session never passed.
- **Login alerts (new device / new country)** — `LoginAlertService` fingerprints (sha256 of IP + UA + Accept-Language), inserts/updates `known_devices`, compares against last 5 `auth.login` audit rows for new-country detection, runs 60-min/3-country geo-velocity heuristic. Listener `SendLoginAlert` fires on `Illuminate\Auth\Events\Login`, writes in-app notification + queues `NewDeviceLogin` mailable. Failure-isolated with try/catch at every stage.
- **Session management UI** — `/profile/sessions` (`routes/web.php:232`) lists rows from `sessions` table, blocks current-session self-revoke, allows bulk "logout other devices", shows trusted-device side panel with mark-trusted / forget actions. Reachable from profile show page at `resources/views/profile/show.blade.php:177`.
- **Logout** — Clears `2fa.*` session keys, calls `auth()->logout()`, audits the event.
- **Honeypot** — Hidden field + form fill-time floor on login, register, forgot/reset password; silent 200 on bot hits to deny scrapers a fingerprintable error.

## ⚠️ Inactive / not wired

- 🔴 **2FA has no UI entry point.** The full setup flow ships and works, but **no link to `/2fa/setup` or `/2fa/disable` exists anywhere in the app's views**. `grep -rn "2fa.setup\|2fa.disable\|two_factor" resources/views/` returns zero matches outside the 2fa/ views themselves. `resources/views/profile/show.blade.php` has a "Security" card (line 168-198) that only links to active sessions and permissions — nothing for 2FA. End users cannot discover or enable 2FA without typing the URL by hand. **Effectively dead code.**
- 🔴 **`'2fa'` middleware alias is never applied to a route.** Defined at `app/Http/Kernel.php:107` and the middleware class itself is sound, but `grep -rn "'2fa'" routes/` returns only the Kernel registration. The `auth` middleware group does not include it. Net effect: even after a user enables TOTP, the *next* successful password login still bounces through the challenge (because `SessionsController::store` checks `hasTwoFactorEnabled`), but a Socialite-callback login or any future `auth()->login()` callsite that bypasses `SessionsController` would **skip the challenge entirely** — exactly the threat-model gap the middleware was designed to close.
- 🔴 **Google OAuth bypasses 2FA.** `LoginController::handleProviderCallback` calls `auth()->login($user)` at line 42 with no `2fa.passed` flag set and no middleware to catch this. A user with TOTP enabled who signs in via Google **fully authenticates without ever proving second factor**. This is the same gap the `'2fa'` middleware was built for, and it is currently unapplied (see above).
- 🟡 **Standalone 2FA confirm view (`resources/views/auth/2fa/confirm.blade.php`) is unreachable.** Its comment at line 19-22 acknowledges it: confirm is embedded in `/2fa/setup`. Either delete it or wire a route — leaving it in place adds maintenance noise.
- 🟡 **Login-alert email auto-queueing depends on `NewDeviceLogin::ShouldQueue`.** `Mail::to(...)->send(new NewDeviceLogin(...))` at `SendLoginAlert.php:123` will block the auth response *if* the mailable does not implement `ShouldQueue`. The comment claims it does; verify in `app/Mail/NewDeviceLogin.php`. If it doesn't, login latency increases by the SMTP RTT.
- 🟡 **OAuth user gets `'name'` and `'email'` written into protected attributes via `$user->name = ...` then `forceFill` for provider_id + email_verified_at.** Because `name` is in `$fillable` this works, but the bare `new User()` assignment skips the `User::create` factory pipeline and never triggers `Registered` event — no email verification flow, no referral attribution, no welcome notification. New Google sign-ups are second-class accounts.
- 🟡 **`verified` middleware only gates `/checkout/*` and `/payment/*`** (`routes/web.php:295`). All of `/movies`, `/profile`, `/2fa/setup`, `/watch-party`, etc. accept unverified emails. If "verify before watching" was intended, it isn't wired.

## 🔧 Needs improvement

- 🔴 **OAuth account-linking conflict ignored.** `LoginController::handleProviderCallback:28` looks up by `provider_id` only. If a user registered with `john@gmail.com` + password, then later clicks "Sign in with Google" using the same Gmail address, the code falls into the `if (! $user)` branch at line 30 and **calls `new User(); $user->email = $googleUser->getEmail(); ... save()`** which violates the `users.email` unique constraint → 500. Fix: after `provider_id` miss, also lookup by `email`, and if found either link (`forceFill(['provider_id' => ..., 'email_verified_at' => $existing->email_verified_at ?? now()])`) after requiring password re-auth, OR refuse with a "please log in with password and link Google from profile" message.
- 🔴 **Google login redirects to `/movies` regardless of role.** `LoginController.php:45` hardcodes the URL while `SessionsController` correctly routes to `adminDashboardUrl()` based on role. Admins signing in via Google land in the wrong place.
- 🔴 **OAuth login fails with `Socialite::driver('google')->user()` uncaught.** No try/catch around the Socialite callback (`LoginController.php:23`). State-mismatch, denied consent, or transient OAuth errors → 500 stack trace. Wrap and bounce back to login with a flash.
- 🔴 **Typo in error message: `'Your provieded credentials could not be verified.'`** (`SessionsController.php:131`). Also breaks the bilingual-Indonesian convention used elsewhere — every other auth message is bilingual or pure Indonesian.
- 🔴 **`RegisterController::store` calls `auth()->login($user)` immediately** (line 86) but never sets `session()->put('2fa.passed', true)` or `session()->regenerate()`. After registration the user is logged in but the session retains its pre-auth ID (session fixation risk) and any future TwoFactorVerified middleware activation would trap them. Mirror what `SessionsController` does on the no-2FA branch.
- 🟡 **Lockout check is purely failure-count based — no cool-down on unlock.** A user locked out at minute 15:00 is unlocked at minute 15:00 + lockout_minutes once old rows fall outside the window. There is no admin-visible "this account is currently locked until HH:MM" — `unlockLogin` exists but no admin view links to it (only the route at `routes/web.php:605`). Add a column or simply expose remaining time to the user-facing error.
- 🟡 **`2fa.verify` throttle is per-IP only (`throttle:10,1`).** A determined attacker who guessed the password can try 10 TOTP codes per minute per IP across a 1-million keyspace — given 6 digits × ±30s drift ≈ 3 valid codes per minute window, that's a real attack surface over hours. Add per-user-id throttling and/or wipe the pending session after N failed attempts (e.g. 5).
- 🟡 **TOTP secret leaves the server via a third-party QR encoder.** `setup.blade.php:48` builds `https://api.qrserver.com/v1/create-qr-code/?...&data={otpauth-uri}`. The `otpauth://` URI **includes the shared secret in plaintext** and is sent to a third party every time someone opens 2FA setup. The view comment acknowledges this. Self-host with `endroid/qr-code` or generate inline as a data URI.
- 🟡 **2FA recovery codes are not regeneratable from the UI.** `TwoFactorController` has no `regenerateRecoveryCodes` action. Once a user consumes all 8 codes (or fears they were leaked) the only remedy is disable+re-enable, which rotates the secret and forces a re-scan on every device.
- 🟡 **Password-reset email reveals user identity to opportunistic timing attackers.** `Password::broker()->sendResetLink` performs a `where('email', ...)->first()` lookup and only sends a network call (SMTP) on hit. Response time is observably different. The CAPTCHA-and-throttle defenses help; consider also `User::factory()->create()` shaped dummy work on miss to flatten timing. Low severity given Turnstile gate.
- 🟡 **`KnownDevice.ip` is encrypted at-rest (GDPR-friendly) but `LoginAttempt.ip` is plaintext** and `LoginAttempt` rows persist forever. Add a retention policy (delete > 90 days) via a console command — otherwise the table grows unbounded and stores PII indefinitely.
- 🟡 **`LoginAlertService::fingerprint` includes IP** (`LoginAlertService.php:74`). Indonesian mobile users on CGNAT rotate IPs constantly — this guarantees an alert on virtually every login from mobile data, training users to ignore the alerts. Better: hash UA + Accept-Language only, or use a CIDR /24 truncation for IP.
- 🟡 **`SessionsController::destroy` does not call `session()->regenerate()` or `session()->invalidate()`.** After logout the same session id is preserved (with no user) — a stolen cookie could in theory be replayed to a not-yet-expired anonymous session. Laravel's `Auth::logout()` only nukes the auth user; the session ID stays. Add `request()->session()->invalidate(); request()->session()->regenerateToken();`.
- 🟡 **Honeypot middleware is **not registered in the global `web` group** but is applied per-route on login/register/password-reset.** Newsletter, comment, and other public POSTs are not protected. Either accept the per-route model and audit each new POST, or move into the web group.
- 🟢 **Tests are minimal — 2 happy-path tests total.** No coverage for: lockout, 2FA challenge, recovery code consumption, password reset flow, OAuth callback, email verification link, honeypot, geo-velocity, account-enumeration. `tests/Feature/RegistrationTest.php` even posts an 8-char all-lowercase password (`'testpassword'`) which **would fail the StrongPassword rule** if it ran (it currently passes because the test uses `RefreshDatabase` and asserts authentication after redirect — it must be using a fixture or the test is actually broken; double-check).
- 🟢 **Login view title is hardcoded "Masuk — FLiK"** with no i18n. `vite.config.js` and theming docs hint at locale switching; auth views ignore it.
- 🟢 **No "remember me" duration override.** Laravel default is 5 years — fine for some apps, surprising for a streaming service. Document or shorten.
- 🟢 **No client-side password-strength meter** on register / reset views — surfaces only after server-side rejection.
- 🟢 **`auth.verify` view's "Keluar" button posts to `route('logout')`** (`verify.blade.php:85-91`). Works but small UX nit: the user is technically authenticated-but-unverified; the bigger flow could explain why they can't continue.

## 📊 Satisfaction Score: 6/10

**Justification**: The plumbing is genuinely excellent — RFC-6238 TOTP without dependencies, brute-force defence at three layers (named limiter + per-account + per-IP + progressive delay), HIBP integration, encrypted recovery codes, audit logging on every event, branded mailables, session manager, known-device tracking, geo-velocity heuristic. But two material wires are dangling: 2FA is **completely unreachable from the UI**, and Google OAuth **bypasses 2FA entirely** because the `'2fa'` middleware isn't applied to the `auth` group. The OAuth callback also crashes on account-link collisions and ignores admin role routing. These are not "polish later" issues — they're correctness gaps in shipped code paths.

**What would push it to 10**:
- Wire `/2fa/setup` and `/2fa/disable` into `profile/show.blade.php` "Security" card with current-status badge.
- Apply the `'2fa'` middleware to the `auth` group (or at least to OAuth callbacks).
- Fix `LoginController::handleProviderCallback` to (a) lookup-by-email, (b) handle account-linking conflicts, (c) try/catch Socialite, (d) route by role like `SessionsController` does.
- Add session regeneration on registration and logout.
- Add recovery-code regeneration endpoint.
- Self-host the QR-code rendering.
- Real test coverage (lockout, 2FA challenge, OAuth, password reset, recovery codes).
- Per-user 2FA verify throttle.
- WebAuthn/passkey support (see Bonus below).

## 🎯 Top 3 Priorities

1. **Wire 2FA into the UI and gate OAuth through it.** Add a "Two-Factor Authentication" row to the Security card in `resources/views/profile/show.blade.php` showing enabled/disabled status + setup/disable links. Apply the `'2fa'` middleware to the `auth` route group (`routes/web.php:171`) so Google OAuth and any future `auth()->login()` callsite enforces the challenge. Without this, 2FA is theatre — the code exists but the feature ships unreachable, and adversaries can bypass it via Google.
2. **Harden Google OAuth.** Fix the account-linking crash (lookup by email after provider_id miss, then either link with re-auth or refuse), wrap `Socialite::driver(...)->user()` in try/catch, and route to `adminDashboardUrl()` based on role. Currently a legit user with a duplicate Gmail will get a 500.
3. **Plug the session-fixation hole after registration and the unrevoked-cookie hole after logout.** `RegisterController::store` should mirror `SessionsController::store`'s `session()->regenerate()` + `2fa.passed` flag; `SessionsController::destroy` should call `session()->invalidate()` + `regenerateToken()`. Five lines of code, real defence improvement.

## Bonus: missing-but-expected features

- **Passkey / WebAuthn login** — Netflix and Disney+ now offer it; FLiK has none of the scaffolding. Would replace TOTP for most users.
- **Magic-link email login** — popular as a 2FA-free alternative for streaming apps where the threat model is mostly account-sharing, not credential stuffing. Easy to bolt on with `Password::sendResetLink` analogue.
- **SSO via Apple, Facebook, X, Microsoft** — only Google is implemented. Apple is mandatory if you list on iOS App Store with any other social login.
- **Biometric login (PWA WebAuthn or NativePHP fingerprint)** — `NativePHP Mobile` is in the stack but the Android client cannot authenticate biometrically yet.
- **Login activity timeline page** — `/profile/sessions` shows *current* sessions; users expect a 90-day "every login attempt" log they can review (Google's "Recent activity"). Data is already in `audit_logs` and `login_attempts`.
- **"Sign out everywhere" on password change from profile** — only happens on password *reset*; routine password change via `/profile/password` does not purge other sessions.
- **Re-authentication for sensitive actions** — disabling 2FA requires current password (good) but changing email, deleting account, or changing payment method does not.
- **Account-recovery flow when both phone (TOTP) and recovery codes are lost** — currently the user is locked out forever with no self-service path. Even an email-only "prove ownership" flow would help.
- **2FA "trust this device for 30 days"** — would dramatically reduce TOTP fatigue on a streaming app. `KnownDevice.trusted` already exists; pivot the 2FA challenge on it.
- **Push notification on new login** (FCM/APNs via NativePHP) — currently only in-app + email. Real-time push is the modern standard.
- **CAPTCHA-less first-attempt UX** — Turnstile fires on every login; competitors only challenge after suspicious signal.
- **Adaptive risk scoring** — geo-velocity is logged but not enforced. Modern systems block / step-up on score.
- **Compromised-credential detection** — should run HIBP on *login* (not just registration) to alert users their saved password has since been breached.
