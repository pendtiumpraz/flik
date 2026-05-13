# Honeypot anti-bot

Cheap, friction-free behavioural defence against naive form-flooders on FLiK's public POST endpoints. Sits alongside the named rate limiters as a second layer: rate limiters catch *volume*, honeypot catches *signal*.

- **Middleware**: [`app/Http/Middleware/Honeypot.php`](../../app/Http/Middleware/Honeypot.php)
- **Blade component**: [`resources/views/components/honeypot.blade.php`](../../resources/views/components/honeypot.blade.php)
- **Config**: [`config/security.php`](../../config/security.php) → `honeypot`
- **Audit action**: `security.honeypot_hit` in `audit_logs`

## What it catches

Two complementary signals — a request is rejected if **either** fires.

| Signal | What it checks | Why a human leaves it alone |
| ------ | -------------- | --------------------------- |
| Hidden trap field | The POST body contains a non-empty value for the configured field name (default `website_url`). | The field is rendered with `position:absolute;left:-9999px`, opacity 0, `tabindex=-1`, `aria-hidden=true`, `autocomplete=off`. Real users can't see it, can't tab to it, password managers won't fill it. |
| Form fill timer | The `_form_start_time` hidden input was stamped less than `min_seconds` (default 2 s) ago. | Even the shortest auth form (login: email + password) takes a human well over 2 seconds. A drive-by POST that follows the GET in the same TCP window is automated. |

Both signals are emitted by a single `<x-honeypot />` component, which is rendered inside every protected `<form>`.

## Coverage

Applied to public POST routes (anywhere a logged-out user can post a body). All entries below carry the `honeypot` middleware alias plus the existing `throttle:*` limiter.

| Route | Form view | Threat |
| ----- | --------- | ------ |
| `POST /login` | `resources/views/auth/login.blade.php` | Credential stuffing setup, account enumeration |
| `POST /register` | `resources/views/auth/register.blade.php` | Mass fake-account creation, spam comment infrastructure |
| `POST /forgot-password` | `resources/views/auth/passwords/email.blade.php` | Reset-link flooding (mailbox bombing of victims), enumeration |
| `POST /reset-password` | `resources/views/auth/passwords/reset.blade.php` | Token-grinding and password-policy probing |
| `POST /newsletter` | `resources/views/components/newsletter.blade.php` | Marketing list pollution / list bombing |

Authenticated routes (comment posting, watchlist, ratings, watch-party, etc.) are intentionally **not** in scope — they sit behind `auth` middleware which already requires a verified session.

## How a hit is handled

When a signal fires, the middleware:

1. Writes a row to `audit_logs` via `App\Services\Audit\AuditLogger::log()`:

   | Column | Value |
   | ------ | ----- |
   | `action` | `security.honeypot_hit` |
   | `user_id` | null (always — auth'd users are skipped earlier) |
   | `client_ip` | request IP |
   | `user_agent` | request UA, truncated to 255 chars |
   | `meta.reason` | `hidden_field` or `too_fast` |
   | `meta.route` | named route, falling back to path |
   | `meta.path` | full request path |
   | `meta.method` | HTTP verb (always `POST` in current coverage) |

   We deliberately use `log()` (not `security()`) so routine bot traffic doesn't pager-bomb. Trend dashboards filter on `action='security.honeypot_hit'`.

2. Returns a generic empty `HTTP 200` response.

   The silence is the point. A scraper looking for a redirect-to-login or a flash error sees neither — the request looks like it succeeded, so the bot stops retrying / probing for the failure signal. Any visible response (`403`, `429`, `419`, redirect with flash) would teach an adversary what to defeat.

## Why authenticated users are skipped

Three reasons:

- The bulk of bot traffic is pre-auth (signup spam, credential stuffing). Logged-in attackers go through different controls (per-user rate limits, 2FA, ownership checks, CSRF).
- A logged-in customer who happens to trip a check (browser autofill picking up a same-named field on an unrelated page, a slow page that re-submits on resume) experiences a silent 200 — a confusing bug-report path.
- It keeps the middleware cheap: zero work for the busiest segment of traffic.

## Configuration

All knobs live in `config/security.php` under `honeypot` and are env-driven:

```php
'honeypot' => [
    'enabled' => (bool) env('HONEYPOT_ENABLED', true),
    'field' => (string) env('HONEYPOT_FIELD', 'website_url'),
    'min_seconds' => (int) env('HONEYPOT_MIN_SECONDS', 2),
],
```

| Env var | Default | Notes |
| ------- | ------- | ----- |
| `HONEYPOT_ENABLED` | `true` | Master kill switch — also short-circuits the Blade component so the markup disappears too. |
| `HONEYPOT_FIELD` | `website_url` | Name the trap input is rendered under. Pick something that **isn't** a recognised browser autofill key (don't use `email`, `name`, `phone`, etc.). |
| `HONEYPOT_MIN_SECONDS` | `2` | Floor on form fill time. Don't raise above ~5 s without testing on a slow connection — paste-and-submit users on prefilled forms can dip below 3 s. |

## Adding the component to a new form

```blade
<form method="POST" action="{{ route('your.public.action') }}">
    @csrf
    <x-honeypot />

    {{-- ...real form fields... --}}
</form>
```

Then on the route:

```php
Route::post('/your-action', [Controller::class, 'method'])
    ->middleware(['throttle:your-limiter', 'honeypot'])
    ->name('your.public.action');
```

The component is a no-op when `HONEYPOT_ENABLED=false`, so it's safe to drop in even if you're rolling out gradually.

## Operating the audit trail

```sql
-- Hits per day, last 14 days
SELECT date(created_at) AS day, count(*) AS hits
FROM audit_logs
WHERE action = 'security.honeypot_hit'
  AND created_at >= now() - interval 14 day
GROUP BY day
ORDER BY day;

-- Worst offending IPs in the last hour
SELECT client_ip, count(*) AS hits, max(meta->'$.reason') AS last_reason
FROM audit_logs
WHERE action = 'security.honeypot_hit'
  AND created_at >= now() - interval 1 hour
GROUP BY client_ip
ORDER BY hits DESC
LIMIT 20;

-- Breakdown by signal
SELECT meta->'$.reason' AS reason, count(*) AS hits
FROM audit_logs
WHERE action = 'security.honeypot_hit'
  AND created_at >= now() - interval 7 day
GROUP BY reason;
```

A burst of `too_fast` hits with realistic UAs may indicate bot traffic that recognises the trap field but ignores the timer — bump `HONEYPOT_MIN_SECONDS` to 3 if needed. A burst of `hidden_field` hits from a single IP/UA is a candidate for IP-level blocking via the WAF-lite layer (see [`waf-lite.md`](./waf-lite.md)).

## Related controls

- [`captcha.md`](./captcha.md) — Cloudflare Turnstile (escalation when honeypot bypass is observed at scale)
- [`waf-lite.md`](./waf-lite.md) — IP / ASN / UA deny-list (next layer up)
- [`alerting.md`](./alerting.md) — pager wiring (we deliberately don't page on routine honeypot hits; configure a daily-digest threshold instead)
- `App\Http\Middleware\RecordRateLimitHits` — companion middleware that records every `429` to the same audit table

## Limitations

- Sophisticated bots that render the form headlessly (Puppeteer / Playwright) can ignore the visually-hidden field and wait the timer out. Honeypot is the cheap first line; CAPTCHA and WAF-lite are the next two layers.
- The timer compares wall-clock seconds — there's no nonce or signature. A bot that scrapes the timestamp and waits 2 s before posting will pass. The defence is "the cost of waiting per-form is higher than the benefit of compromising one form" — true at scale, false for targeted attacks.
- Authenticated bot traffic (compromised sessions, malicious legit accounts) is **not** in scope — see comment-rate-limit + 2FA + audit alerting instead.

## Changelog

| Date | Change |
| ---- | ------ |
| 2026-05-13 | Initial honeypot deployment — middleware + Blade component + 5 routes (login, register, forgot/reset password, newsletter). |
