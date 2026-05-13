# Cloudflare Turnstile (CAPTCHA) — Setup Guide

FLiK uses [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
as its bot-mitigation layer for high-risk public forms. Turnstile is a free,
privacy-friendly drop-in replacement for hCaptcha / reCAPTCHA — most users
never see a challenge (it runs invisibly), and there is no third-party
tracking. Cloudflare account required (free tier is fine).

## What gets gated

| Form                | Always | Conditional                                         |
| ------------------- | :----: | --------------------------------------------------- |
| `POST /login`       |   ✅   | —                                                   |
| `POST /register`    |   ✅   | —                                                   |
| `POST /forgot-password` | ✅ | Also IP-throttled to 3/hour at the route layer.    |
| `POST /comments`    |   —    | Only when account is <24h old AND user has already posted ≥3 comments in the last hour (anti-spam heuristic). |

For all of the above, the widget renders a single `<div class="cf-turnstile">`
that injects a hidden `cf-turnstile-response` field into the surrounding
form. The server validates that token via `App\Rules\CaptchaPassed` →
`App\Services\Security\TurnstileVerifier`.

## Env-gating (FLiK standard pattern)

The integration is **off by default**. When either env var is empty:

- `<x-captcha-turnstile>` renders nothing.
- `CaptchaPassed` always returns valid.
- No JS is loaded, no API roundtrip happens.

So a fresh clone with no Cloudflare account works in dev/CI without any
extra setup. To turn the protection on, populate both keys.

## Setup steps

1. **Create a Turnstile site** in your Cloudflare dashboard:
   - Log in at <https://dash.cloudflare.com> and pick your account.
   - Side menu → **Turnstile** → **Add site**.
   - Name: `flik-prod` (or `flik-staging`, etc.).
   - Domain: the bare hostname you serve from (e.g. `flik.id`). For
     local dev add `localhost` and `127.0.0.1` as additional hostnames.
   - Widget mode: **Managed** (recommended — invisible when possible,
     interactive challenge only when Cloudflare deems it necessary).
   - Pre-clearance: leave off unless you also use Cloudflare WAF.
2. **Copy the keys** Cloudflare shows you:
   - **Site key** — public, embedded in the HTML.
   - **Secret key** — server-only, never exposed to the browser.
3. **Add them to `.env`**:
   ```env
   TURNSTILE_SITE_KEY=0x4AAAAAAA...
   TURNSTILE_SECRET_KEY=0x4AAAAAAA...
   ```
4. **Cache config** (production):
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```
5. **Verify** by loading `/login` — you should see the Turnstile widget
   below the "Ingat saya" checkbox. Submitting without solving should
   produce a bilingual error: *"Verifikasi anti-bot gagal. Silakan coba
   lagi. / CAPTCHA verification failed. Please try again."*

## Test keys (local development)

Cloudflare publishes [dummy site/secret pairs](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
that always pass or always fail. Useful for unit and end-to-end tests:

| Purpose                 | Site key                               | Secret key                               |
| ----------------------- | -------------------------------------- | ---------------------------------------- |
| Always passes (visible) | `1x00000000000000000000AA`             | `1x0000000000000000000000000000000AA`    |
| Always passes (invisible)| `2x00000000000000000000AA`            | `2x0000000000000000000000000000000AA`    |
| Always blocks           | `2x00000000000000000000AB`             | `2x0000000000000000000000000000000AA`    |
| Always fails siteverify | —                                      | `3x0000000000000000000000000000000AA`    |

Use the "always passes" pair in `.env.testing` so PHPUnit feature tests
exercise the same code path that runs in production without depending on
Cloudflare's real API.

## Behaviour details

### Fail-open vs fail-closed

`TurnstileVerifier::verify()` calls Cloudflare's `siteverify` endpoint
with a 4-second timeout. Network failures behave differently per
environment:

- **Production** (`APP_ENV=production`): fail-**closed** — return false,
  log a warning, user must retry. We'd rather inconvenience users than
  drop the wall entirely during an attack.
- **Local / dev / testing**: fail-**open** — return true, log a warning.
  Devs without internet shouldn't be locked out of seeded accounts.

### Single-use tokens

Turnstile tokens expire after ~5 minutes and are good for **one**
verification call. We do not cache responses. Submitting the same form
twice will fail the second submission unless the widget refreshes.

### Theme & accessibility

The component defaults to `data-theme="dark"` to match FLiK's OTT-premium
dark UI. Override with `<x-captcha-turnstile theme="light" />` or
`theme="auto"` (follows OS preference). Turnstile is keyboard-accessible
and announces challenge state via ARIA.

### Adding the widget to a new form

```blade
<form method="POST" action="...">
    @csrf
    {{-- existing fields --}}

    <x-captcha-turnstile action="my-action" theme="dark" />

    <button type="submit">Submit</button>
</form>
```

In the controller:

```php
use App\Rules\CaptchaPassed;

$request->validate([
    'cf-turnstile-response' => ['nullable', 'string', new CaptchaPassed],
    // ...
]);
```

The `nullable` is intentional — the field is missing when env-gated off,
and `CaptchaPassed` self-suppresses in that case.

### Action labels

The optional `action` prop is forwarded to Cloudflare as `data-action`,
which lets you slice analytics in the Turnstile dashboard by use case
(login / register / password-reset / comment / etc.). It does not change
the verification result.

## Troubleshooting

| Symptom                                                | Likely cause                                                    |
| ------------------------------------------------------ | --------------------------------------------------------------- |
| Widget never renders                                    | One or both env vars empty, or `config:cache` stale.           |
| Widget renders but submission always fails              | Domain in Cloudflare site config doesn't match request host.   |
| `error-codes: ["invalid-input-secret"]` in logs         | Wrong secret key — most often staging/prod swapped.            |
| `error-codes: ["timeout-or-duplicate"]` in logs         | Token reused or older than ~5 minutes. Refresh & resubmit.     |
| Local dev fails with "Verifikasi anti-bot gagal"        | Add `localhost` to the Cloudflare site's hostname list, or use the always-passes test keys above. |

## Related security layers

Turnstile is one part of FLiK's defence-in-depth around auth:

- **Per-IP / per-account login throttle** — `App\Services\Security\LoginThrottle`
  (lockout + progressive delay).
- **Strong password + breach check** — `App\Rules\StrongPassword` +
  `App\Rules\NotBreached` (HIBP k-anonymity).
- **2FA** — TOTP via `App\Services\Security\TwoFactorService`.
- **Session purge on password reset** — see `PasswordResetController::purgeSessions()`.
- **Audit log** — every auth-significant action lands in `audit_logs` via
  `App\Services\Audit\AuditLogger`.

See [docs/security/threat-model.md](./threat-model.md) for the full picture.
