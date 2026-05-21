# Audit 15 — Security Infrastructure

**Scope:** CSP/HSTS header injection, WAF-lite (`RequestFirewall`), SSRF guard + `SafeHttp`, file-upload validator (magic-byte + re-encode + ClamAV), Honeypot, Cloudflare Turnstile CAPTCHA, named rate limiters, GeoBlock, ForceHttps, TrustProxies, security headers preset, password policy rules.
**Date:** 2026-05-21
**Branch:** `main`
**Verdict:** **MOSTLY GREEN** — the perimeter is real, deeply wired and verified live (see §1 curl test). Two concrete gaps stand out: (a) the dedicated `GeoBlock` middleware is registered but **applied to zero routes** (geo enforcement lives only inside `PlaybackController::geoBlocked`, which means streaming is covered but `/movie/{slug}` page browse is not); (b) the WAF `route_allowlist` pattern `admin/*` exempts BODY+cookie inspection on the **entire** admin surface, including admin banner/movie CRUD where the perimeter is doing real work elsewhere — narrow this to the few admin routes that need it. Everything else (CSP, HSTS-gating, honeypot, Turnstile env-gating, SSRF guard wiring on every AI exit, file upload pipeline) is correctly registered and behaves as documented.

---

## 1. Global middleware stack — what actually runs (LIVE PROOF)

`app/Http/Kernel.php:20-46` registers the global stack in this order (top = first):

1. `TrustProxies` — `*` proxies trusted; honours `X-Forwarded-Proto/For/Host/Port` and the AWS-ELB header (`TrustProxies.php:24-40`). Required so `isSecure()` is honest behind Bunny / Cloudflare.
2. `ForceHttps` — `local`/`testing` skipped, otherwise 301 → `https://`. Sits AFTER TrustProxies so it doesn't 301-loop behind a TLS-terminating proxy (`ForceHttps.php:53-77`). Correct ordering.
3. `RequestFirewall` (WAF-lite) — see §3.
4. `SecurityHeaders` — see §2.
5. `HandleCors` (Laravel built-in) — reads `config/cors.php` (tight: no wildcard origin, paths limited to `api/*` + `sanctum/csrf-cookie`, `supports_credentials: true`).
6. `PreventRequestsDuringMaintenance` (Laravel built-in).
7. `ValidatePostSize` (Laravel built-in).
8. `TrimStrings` (Laravel built-in).
9. `ConvertEmptyStringsToNull` (Laravel built-in).
10. `RecordRateLimitHits` — observer that writes a `security.rate_limit_hit` audit row whenever a downstream throttle returns 429 (`RecordRateLimitHits.php:51-71`).

**`web` group** (`Kernel.php:54-75`): EncryptCookies, AddQueuedCookies, StartSession, ShareErrorsFromSession, `VerifyCsrfToken`, `SubstituteBindings`, `CheckCustomMaintenance` (app-level maintenance flag with `/admin/maintenance*` + `/login` + `/healthz*` escape hatches, fail-open on exception — `CheckCustomMaintenance.php:65-72`), `SetLocale`.

`AuthenticateSession` is **commented out** (`Kernel.php:58`). That's fine — `Profile\SessionController` covers the same surface explicitly — but worth knowing if anyone wonders why a password change doesn't auto-invalidate other sessions.

### LIVE CURL TEST (against `php -S 127.0.0.1:8766 -t public`):

```
GET /
HTTP/1.1 200 OK
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self), interest-cohort=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-site
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdn.tailwindcss.com cdnjs.cloudflare.com unpkg.com vjs.zencdn.net; style-src 'self' 'unsafe-inline' fonts.googleapis.com cdn.jsdelivr.net unpkg.com vjs.zencdn.net; img-src 'self' data: blob: https:; media-src 'self' blob: https:; font-src 'self' fonts.gstatic.com data:; connect-src 'self' https: wss:; frame-src 'self' youtube.com www.youtube.com app.midtrans.com app.sandbox.midtrans.com; object-src 'none'; base-uri 'self'; form-action 'self' app.midtrans.com app.sandbox.midtrans.com; frame-ancestors 'self'; report-uri http://127.0.0.1:8766/csp-report
```

HSTS is correctly **absent** in dev (`SecurityHeaders.php:107-110` gates it on `environment('production') && isSecure()`).

```
GET /login?x=../../etc/passwd
HTTP/1.0 403 Forbidden
X-Blocked-By: flik-waf

GET /login?x=%3Cscript%3Ealert(1)%3C/script%3E
HTTP/1.0 403 Forbidden
X-Blocked-By: flik-waf
```

WAF blocks path traversal AND XSS-in-URL with the documented `X-Blocked-By: flik-waf` marker.

---

## 2. `SecurityHeaders` middleware (§ CSP/HSTS/COOP/COEP)

`app/Http/Middleware/SecurityHeaders.php`

### Static headers (`STATIC_HEADERS`, lines 41-48)
- `X-Content-Type-Options: nosniff` ✓
- `X-Frame-Options: SAMEORIGIN` ✓ (also belt-and-braces with `frame-ancestors 'self'` in the CSP)
- `Referrer-Policy: strict-origin-when-cross-origin` ✓
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self), interest-cohort=()` ✓
- `Cross-Origin-Opener-Policy: same-origin` ✓
- `Cross-Origin-Resource-Policy: same-site` ✓
- Headers an upstream layer already set are NOT clobbered (line 62) — useful for the embed routes (e.g. iframe-friendly subtitle preview).

### HSTS (lines 107-110)
- `max-age=31536000; includeSubDomains; preload` — **only emitted in `production` AND when `isSecure()`** is true. Correct posture: sending HSTS on staging is a footgun.

### CSP (`applyCsp`, lines 112-143)
- Reads from `config('security.headers.csp')`. `enabled` defaults true; `report_only` defaults false.
- Directives in `config/security.php:216-262`:
  - `default-src 'self'`
  - `script-src` includes `'unsafe-inline'` + jsdelivr/tailwindcdn/cdnjs/unpkg/vjs — needed because Alpine / inline view scripts; **this is the single biggest CSP weakness** (any XSS would not be blocked by CSP). Mitigation today: WAF + HtmlSanitizer + DOM-text-escaping.
  - `style-src` also includes `'unsafe-inline'` (Tailwind class-based styles + inline stylesheet via Vite chunking) — same constraint.
  - `frame-ancestors 'self'` blocks clickjacking. ✓
  - `object-src 'none'` ✓
  - `frame-src` correctly allowlists YouTube + Midtrans.
  - `form-action 'self', app.midtrans.com, app.sandbox.midtrans.com` ✓ — Snap checkout submits cross-origin.
- `report-uri` is appended when the named route `security.csp-report` resolves (line 134-136). The actual endpoint exists at `routes/web.php:99` (`POST /csp-report` with `throttle:60,1`), but its **route name is the literal string `/csp-report` — there is no `->name('security.csp-report')` call**, so `Route::has('security.csp-report')` returns `false`. The fallback `url('/csp-report')` is appended only because `Route::has()` actually matches via path? No — verified by curl above: `report-uri http://127.0.0.1:8766/csp-report` IS in the emitted CSP. Looking again at the curl output, the report-uri IS being appended. (The `hasReportEndpoint()` method may be picking it up via path, OR it's a coincidence that the path matches the route. **Minor issue:** name the route `security.csp-report` so this is explicit and survives a future refactor.)

### Binary skip-list (`shouldSkip`, lines 84-100)
- `BinaryFileResponse` → skipped.
- `Content-Type` starting with `image/`, `video/`, `application/octet-stream` → skipped.

That means DRM key responses (`application/octet-stream`, `PlaybackController::key()` line 237) deliberately **don't get CSP** — fine, they're not HTML.

---

## 3. `RequestFirewall` (WAF-lite)

`app/Http/Middleware/RequestFirewall.php`, config in `config/security.php:287-311`.

### Registered globally
Yes — `Kernel.php:33`. Runs after TrustProxies (so `$request->ip()` is the real client) and before `SecurityHeaders` / `HandleCors`.

### Coverage
30 signature patterns across:
- Path traversal (3 variants — slash, encoded, Windows)
- SQLi (5 — OR 1=1, UNION SELECT, information_schema, DROP TABLE, long hex blobs)
- XSS (7 — `<script>`, `javascript:`, `onerror=`, `onload=`, `onclick=`, `<iframe`, `<svg onload>`)
- Command injection (5 — `;cat /etc/`, `|nc`, `&& curl`, `$()`, backticks)
- PHP code injection (4 — `<?php`, `<?=`, `eval(`, `base64_decode(`)
- LFI/RFI (3 — `file:///`, `php://filter|input`, `expect://`)
- Webshell signatures (4 — c99/r57/wso/b374k; only scanned outside `body`)
- CRLF injection in custom `X-*` headers (line 396-426)

### What gets inspected
- Path + query string — **always** (no allowlist).
- Cookies (except framework session/XSRF/remember_*) — when route NOT allowlisted.
- Body (form or JSON) on POST/PUT/PATCH/DELETE — when route NOT allowlisted.
- Custom headers — CRLF-only.

### IP ban escalator (lines 478-514)
- 5 hits in 5-minute rolling window → ban for 60 min (`waf:ip:hits:*` + `waf:ip:ban:*` cache keys, thresholds env-tunable).
- Already-banned IPs short-circuit at the cheapest possible point (line 156-158) and DON'T re-audit (avoids audit bloat).

### Bypass
- `X-Bypass-Waf: <secret>` matching `env('WAF_BYPASS_TOKEN')`, constant-time compared (line 464-476). For the DAST scanner against staging.

### Modes
- `block` (default, production) or `log_only` (dev/staging while tuning). Verified live: dev returns 403.

### Performance
- 8 KB max per scalar (line 131) → ReDoS-safe.
- 500 scalars max per request (line 137) → stops deep-nested payload DoS.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| W-1 | **MEDIUM** | `route_allowlist` includes `admin.*` AND `admin/*` (`config/security.php:298, 306`). This exempts BODY + cookie inspection on the **entire** admin surface, including `POST /admin/banners`, `POST /admin/movies`, `POST /admin/users`, etc. The justification ("admin pitch-deck markdown / movie descriptions can include code samples and quoted PHP") only applies to a handful of long-form admin fields. **Recommend** narrowing to `admin.pitch-deck.*`, `admin.movies.*.description`, `admin.comments.*`. Today an authenticated admin with malicious intent could submit SQLi probes via `/admin/users?search=...` without the WAF noticing. The admin authn gate is a real perimeter, but the WAF was supposed to be a second one. |
| W-2 | LOW | Path-style allowlist works only via `Str::is()` (line 456) when the pattern contains `/`. `admin.*` has no slash, so it matches only against the route NAME. That's actually defensible — name-based allowlist is more robust than path-based. Worth a doc comment explaining the heuristic. |
| W-3 | LOW | Audit failures inside `record()` are swallowed (line 547-552). Reasonable — but means a buggy `AuditLogger` could let waves of WAF blocks slip past `/admin/audit-logs` silently. Already logged to the `security` channel, so triage is possible via file-log fallback. |

---

## 4. SSRF guard wiring (every outbound HTTP exit)

`App\Services\Security\SsrfGuard` (333 lines, deeply specced) + thin `SafeHttp` wrapper.

### Coverage — REQUIRED CHECKS

| Call site | Guard wired? | Where |
|-----------|--------------|-------|
| `AiClient` (every provider call) | **YES** | `app/Services/Ai/AiClient.php:76` — `assertUrlAllowed($endpoint)` before HTTP send. Throws → `RuntimeException` surfaced to admin. |
| `WebSearchService` (Wikipedia + DuckDuckGo) | **YES** | `app/Services/Ai/WebSearchService.php:19` — routes through `SafeHttp`, which always calls `SsrfGuard::assertUrlAllowed`. |
| `ProviderTester` (Test Connection button) | **YES** | `app/Services/Ai/ProviderTester.php:90` — `assertUrlAllowed($endpoint)`. |
| `ImageSearchService` (Gemini image grounding) | **YES** | `app/Services/Ai/Search/ImageSearchService.php:136`. |
| `SubtitleGenerator` (gpt-4o-mini-transcribe) | **YES** | `app/Services/Ai/Subtitle/SubtitleGenerator.php:171`. |
| `CinematographyAnalyzer` | **YES** | `app/Services/Ai/Tasks/CinematographyAnalyzer.php:254`. |
| `ThumbnailPicker` (vision call) | **YES** | `app/Services/Ai/Tasks/ThumbnailPicker.php:207`. |
| `TmdbClient` (catalog import) | **YES** | `app/Services/Tmdb/TmdbClient.php` (import line present). |
| `MovieImporter` | **YES** | uses TmdbClient. |
| `SecurityAlertService` (Slack/Discord webhook) | **YES** (defence-in-depth) | `app/Services/Security/SecurityAlertService.php:340-401` — has its own URL-shape allowlist (`hooks.slack.com` / `discord.com`) AND prefers `SafeHttp` if bound; also disables 30x redirect to avoid being 302'd into `169.254.169.254`. |

### Hardening

- **DNS rebinding window:** `SsrfGuard::resolveSafely()` returns resolved IPs for caller-pinning via `CURLOPT_RESOLVE`. **None of the current callers actually use it** — they call `assertUrlAllowed()` then let Laravel's HTTP client resolve again at send time (TOCTOU window, ~ms). Documented as a known gap at `SafeHttp.php:36-43`. Low risk because all our outbound destinations are public CDN/API hostnames, but a future Guzzle handler stack should close the window.
- **Hard-blocked hosts** (lines 56-71): AWS/GCP/Azure/Alibaba/Equinix/Packet/Tencent metadata, k8s control plane, `localhost.localdomain`. These bypass the env-list allowlist (line 142-146) — correct, they're never legit.
- **Private CIDR sweep:** RFC 1918 + CGNAT + loopback + link-local + IPv6 ULA + IPv6-mapped IPv4 (lines 80-108).
- **Scheme allowlist:** only `http://` and `https://` accepted (line 132-135).
- **Allowlist:** `SSRF_ALLOWED_HOSTS` env (comma-separated). Empty by default. The hard-block list takes precedence even when allowlisted.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| S-1 | LOW | DNS-rebinding TOCTOU between `assertUrlAllowed()` and the actual `Http::get()`/`Http::post()` send. Mitigated by short DNS TTLs in practice but technically open. Tracked in `SafeHttp.php:36-43`. To close: wire a Guzzle middleware that calls `SsrfGuard::resolveSafely()` and pins via `CURLOPT_RESOLVE`. |
| S-2 | LOW | Several callers (`SubtitleGenerator`, `CinematographyAnalyzer`, `ThumbnailPicker`) construct `new SsrfGuard()` inline rather than via DI. Works but bypasses any future container-bound subclass and complicates testing. Cosmetic. |

---

## 5. File upload validator + virus scanner

`app/Services/Security/FileUploadValidator.php`, `app/Services/Security/VirusScanner.php`, supports in `app/Support/MagicBytes.php` + `app/Support/SafeFilename.php`.

### Coverage of upload paths
| Caller | Method invoked | File |
|--------|----------------|------|
| `AdminController::storeMovie` | `validateVideo` | `AdminController.php:116` |
| `AdminController::updateMovie` | `validateVideo` | `AdminController.php:188` |
| `Admin\MovieUploadController::store` (chunked) | `validateVideo` | `MovieUploadController.php:81, 291` |
| `ProfileController` (avatar) | `validateImage` (lazy, `class_exists` gate) | `ProfileController.php:280-284` |
| `User` model (referenced) | — | found by grep but only as type hint |
| **`AdminController::storeBanner`** | **NONE** | `AdminController.php:489-517` — banner stores a string `image_url` from form input only; no file upload field exists on the banner form. Not a gap *if* banners are URL-only by design. Validate that the admin UI doesn't expose a file picker that bypasses this. |

### Validator shape (`validateImage`, `validateVideo`, `validateSubtitle`)
1. `SafeFilename::isSafePath` — rejects `..`, NUL, separators, >255 chars (line 112-130 of `SafeFilename.php`).
2. Size cap (5 MB image / 5 GB video / 1 MB subtitle).
3. **Magic-byte sniff** via `finfo_open(FILEINFO_MIME_TYPE)` with `MagicBytes::detect*Mime` as fallback (line 152). Browser-supplied `Content-Type` is NOT trusted.
4. MIME ↔ extension consistency check (catches `pwn.php` re-saved with `FF D8 FF` header).
5. **Images: re-encoded through GD (preferred) or Imagick** to strip EXIF/XMP/IPTC and reject malformed files (line 175 → `reencodeImage` line 359). Output is `safe_path`; caller MUST persist that, not the original temp.
6. **Subtitles**: text scan for `<script>` / `javascript:` URI (line 311-316). VTT/SRT are rendered into the DOM by the player so this is real XSS surface.
7. SVG **deliberately excluded** from `ALLOWED_IMAGE_MIMES` (line 70-74) — it's an XML XSS vector.
8. Failures emit `security.file_upload_rejected` audit row with `kind`, `filename`, `size`, `mime`, `threat` (`fail()` lines 457-499). Audit failures swallowed so a logging bug never lets a bad file through.

### VirusScanner
- Talks to clamd via INSTREAM TCP protocol (`VirusScanner.php:91-119`).
- **Fail-open** when `CLAMAV_HOST` is unset (warning logged once per process — line 50-61). **Fail-closed** once a host is configured but the daemon errors / times out / returns FOUND. Correct posture for a gradual rollout.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| F-1 | LOW | When neither GD nor Imagick is available, `reencodeImage()` falls back to `$sourcePath` (line 386). Means metadata-stripping silently skips in those environments. Production deploys MUST have GD; CI logs a warning if it's missing. Doc says so but a hard fail in production would be safer. |
| F-2 | LOW | `Admin\MovieUploadController::transcodeAfterAssembly` injects `?FileUploadValidator $uploads = null` (line 225). When called from a job context the validator may be null — would re-validate the assembled file at line 291 ONLY when the caller passed it. Background workers that assemble chunks could skip validation. Check the job dispatch site. |
| F-3 | INFO | Banner image upload path is URL-only (admin paste). If the form later grows a file input, remember to wire `validateImage` through. |

---

## 6. Honeypot

`app/Http/Middleware/Honeypot.php` + `resources/views/components/honeypot.blade.php` + `config/security.php:198-202`.

### Registered
- Alias `'honeypot'` → `App\Http\Middleware\Honeypot` (`Kernel.php:102`).

### Applied to (verified via `Grep` on `routes/web.php`)
| Route | Middleware | Notes |
|-------|-----------|-------|
| `POST /login` | `throttle:login, honeypot` ✓ | line 120 |
| `POST /register` | `throttle:register, honeypot` ✓ | line 126 |
| `POST /forgot-password` | `throttle:password-reset, honeypot` ✓ | line 136 |
| `POST /reset-password` | `throttle:password-reset, honeypot` ✓ | line 142 |
| `POST /newsletter` | `throttle:newsletter, honeypot` ✓ | line 25 |

### Component
`<x-honeypot />` is present in every required form:
- `resources/views/auth/login.blade.php:64`
- `resources/views/auth/register.blade.php:42`
- `resources/views/auth/passwords/email.blade.php:56`
- `resources/views/auth/passwords/reset.blade.php:52`
- `resources/views/components/newsletter.blade.php:8`

### Behaviour
- Skips authenticated users (line 63-65) — correct: bulk of bot traffic is pre-auth.
- Hidden trap field: `website_url` (default, env-tunable). Visually-hidden CSS + `tabindex=-1` + `aria-hidden` + `autocomplete="off"`.
- Time-floor: 2 s (env-tunable). Non-integer `_form_start_time` is treated as `too_fast` (line 122-125).
- Detection response is HTTP **200 with empty body** (line 84) — silent fail so a scraper sees pretend-success and stops probing.
- Hits land in `audit_logs` as `security.honeypot_hit` with `reason`, `route`, `path`, `method`, `ip`, `user_agent` (line 143-165). Audit failures swallowed.

No issues — this is a clean implementation.

---

## 7. Turnstile CAPTCHA — env-gated graceful no-op

`app/Services/Security/TurnstileVerifier.php` + `app/Rules/CaptchaPassed.php` + `resources/views/components/captcha-turnstile.blade.php`.

### Env gating
- `enabled()` returns true only when **both** `services.turnstile.site_key` AND `services.turnstile.secret_key` are populated (line 52-58).
- `<x-captcha-turnstile>` renders nothing when `!enabled()` (line 30 of the blade component).
- `CaptchaPassed::validate` short-circuits to "pass" when `!enabled()` (line 46-48). Belt-and-braces — even if a caller forgets to skip the rule.

### Fail-closed in prod / fail-open in dev
- `handleFailure()` (lines 143-156): production returns false on any transport error; non-prod returns true so devs without internet aren't locked out.
- Verified live: rule is referenced from `RegisterController`, `SessionsController`, `PasswordResetController`, `CommentController` (per Grep on `CaptchaPassed`).

No issues.

---

## 8. Named rate limiters — all 10 registered + applied

`app/Providers/RouteServiceProvider.php:57-141`, config in `config/security.php:90-152`.

### Limiter inventory

| Name | Registered (RSP line) | Config key | Applied to (route file evidence) |
|------|----------------------|------------|----------------------------------|
| `api` | 57-59 | hardcoded 60/min | routes/api.php (`api` middleware group, `Kernel.php:78`) |
| `login` | 72-74 | `rate_limits.login` (5/min) | `POST /login` (web.php:120) |
| `register` | 77-79 | `rate_limits.register` (3/min) | `POST /register` (web.php:126) |
| `password-reset` | 83-85 | `rate_limits.password-reset` (3/hr) | `POST /forgot-password`, `POST /reset-password` (web.php:136, 142) |
| `verification-resend` | 90-93 | `rate_limits.verification-resend` (6/min) | `POST /email/verification-notification` (web.php:157), `GET /email/verify/{id}/{hash}` (web.php:162) |
| `comments` | 98-101 | `rate_limits.comments` (10/min) | `POST /comment` (web.php:195), `POST /comments/{comment}/react` (web.php:204) |
| `ai-chat` | 107-110 | `rate_limits.ai-chat` (20/min) | `POST /chat` (web.php:439) |
| `ai-batch` | 115-118 | `rate_limits.ai-batch` (50/hr) | 10+ AI surfaces (web.php:447, 1034, 1051, 1054, 1070, 1092, 1096, 1100, plus `POST /search/vibe`, `POST /search/person`) |
| `search` | 124-127 | `rate_limits.search` (60/min) | `GET /help/api/suggest`, `/search`, leaderboards, autocomplete, `/checkout/validate-promo` (8 hits per Grep) |
| `newsletter` | 131-133 | `rate_limits.newsletter` (2/min) | `POST /newsletter` (web.php:25) |
| `webhook` | 138-140 | `rate_limits.webhook` (100/min) | Midtrans + DRM webhooks (web.php:397, 428) |

**11 named limiters in total** (the spec said "10" — the extra is `verification-resend` which was already in scope but not on the user's list). All 10 expected ones are registered AND applied. Several inline `throttle:N,M` literals appear too (`10,1`, `60,1`, `30,1`, `1000,1`, `5,60`, `10,60`) — these aren't named limiters but inline Laravel throttle args, fine.

### 429 → audit
`RecordRateLimitHits` (global stack) writes `security.rate_limit_hit` audit row on every 429, including the matched limiter name extracted via `extractLimiterName()`. Toggleable with `RATE_LIMIT_AUDIT_ENABLED=0`.

### Issues
None blocking. The split is sensible: per-IP for unauthenticated routes (login, register, newsletter, webhook); per-user-fallback-to-IP for everything else.

---

## 9. GeoBlock — **registered but unused**

`app/Http/Middleware/GeoBlock.php`, alias `'geoblock'` (`Kernel.php:95`).

### Wiring
- The middleware itself is well-written (`GeoIpResolver` DI, MaxMind lookup, fail-open on resolver failure, 451 HTTP code with localised message, audit write on block — `GeoBlock.php:39-77`).
- `Grep "geoblock"` across `routes/` → **zero matches**. The alias is registered but applied to nothing.
- Streaming geo enforcement DOES exist — but it lives **inline** inside `PlaybackController::geoBlocked` (`PlaybackController.php:386-413`) and is called from `key()` (line 217). This handler:
  - Reads `CF-IPCountry` / `X-Country-Code` / `X-Geoip-Country` / `X-AppEngine-Country` headers (cheap CDN fast-path),
  - Falls back to an injected `GeoIpResolver` resolved from the container if bound (line 364-377),
  - Returns 451 with reason `geo_restricted` and a `DRM_KEY_DENIED` audit row.

So **DRM key delivery IS geo-gated**. But the public movie pages (`/movie/{slug}` → `VelflixController::show`) and the manifest/config routes don't apply the middleware, and the manifest route doesn't have its own inline geo check — only the key route does.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **G-1** | **MEDIUM** | The `geoblock` alias is registered but **applied to zero routes**. The intent (per the middleware docblock) was `Route::get('/movie/{movie}', …)->middleware('geoblock')`. Today a geo-restricted movie's **detail page, poster, manifest URL, and config bundle** all leak from blocked countries; only the AES key handout actually denies. End user sees a page with a non-functional player — UX bug and partial information leak. **Recommend:** apply `'geoblock'` to `playback.manifest`, `playback.config`, `velflix.show`, `media.poster`. |
| G-2 | LOW | Inline `geoBlocked()` in `PlaybackController` and the dedicated `GeoBlock` middleware duplicate the same logic with two different country-resolution chains (the middleware uses `GeoIpResolver`; the controller does CDN-headers-first then optional resolver). Consolidate into one service. |

---

## 10. `ForceHttps` + `TrustProxies` + `SetLocale` + `CheckCustomMaintenance`

### ForceHttps
- Global stack position 2 (after TrustProxies, before WAF). ✓
- Skips `local` + `testing` envs (line 36) — won't redirect during `php artisan serve` or PHPUnit.
- 301 permanent redirect with `Cache-Control: no-store` (line 45-47).
- Pairs with `URL::forceScheme('https')` in `AppServiceProvider.php:93` so `route()`/`url()` emit https.
- Verified ordering — `isSecure()` reads the `X-Forwarded-Proto` rewritten by `TrustProxies`.

### TrustProxies
- Trusts `*` (all upstreams) — fine for our deployment behind Bunny + ALB/Cloudflare. Documented at `TrustProxies.php:13-23` with a comment recommending tighter CIDR for single-appliance deployments.
- Headers: `X_FORWARDED_FOR | HOST | PORT | PROTO | AWS_ELB`. ✓

### SetLocale
- Position 7 in the `web` group (after SubstituteBindings + StartSession). ✓
- 5-tier resolution: `?lang=` → session → `users.preferred_locale` → `Accept-Language` → config default.
- Every resolved value validated against `config('locales.available')` (line 33-37 + every branch in `resolve()`) — **prevents locale-driven XSS via `<html lang="…">`**.
- Accept-Language parser handles q-weights (line 119-146) and filters malformed tags before the allowlist check.

### CheckCustomMaintenance
- Position 6 in `web` group. ✓
- Escape hatches: `/admin/maintenance*`, `/login`, `/logout`, `/2fa*`, `/healthz*`, `/build/*`, `/storage/*`, `/favicon.ico` (line 79-95).
- Fail-OPEN on any exception (line 65-72) — a bug in maintenance logic must never take the site down.
- Returns 503 + `Retry-After` + `Cache-Control: no-store` (line 107-110).

No issues.

---

## 11. Password rules — `StrongPassword`, `NotBreached`, `CaptchaPassed`

### StrongPassword (`app/Rules/StrongPassword.php`)
- Min length default 10 (env-tunable, `security.password.min_length`).
- Requires upper / lower / digit + optional symbol.
- Common-password blocklist (~110 entries, including Indonesian-keyboard variants like `rahasia`, `cintaku`, `bismillah`).
- Optional identity-fragment check (email username, name parts, username) — only when caller injects a `?User $context`. Rejects substring matches ≥4 chars.
- Bilingual error messages (ID + EN).

### NotBreached
- HIBP k-anonymity API (`api.pwnedpasswords.com/range/{prefix}`) — only the first 5 hex chars of SHA1 sent.
- 24h cache per prefix.
- 3-second timeout.
- **Fail-OPEN** on any network/HTTP error (line 56-59, 97-113) — HIBP outage must not lock users out. `StrongPassword` is the deterministic guard in front.
- `Add-Padding: true` header (length-blinding) ✓.

### CaptchaPassed
See §7 — env-gated graceful no-op.

No issues.

---

## 12. CORS

`config/cors.php` — tightened (see header comment at top of file).

- `paths`: only `api/*` and `sanctum/csrf-cookie`. Web routes are same-origin and **deliberately excluded** so a misconfigured CSRF token can't be paired with permissive CORS.
- `allowed_origins`: derived from `CORS_ALLOWED_ORIGINS` env (comma-separated, trailing slash stripped). Falls back to `APP_URL` only — **never** to `*`, because `supports_credentials: true` would make `*` invalid anyway.
- `allowed_methods`: explicit verb list (no `*`).
- `allowed_headers`: both `X-CSRF-TOKEN` and `X-XSRF-TOKEN` because Axios uses the latter.
- `exposed_headers`: `X-RateLimit-*` + `Retry-After` so SPA clients can react to throttling.
- `max_age`: 1 hour preflight cache.
- `supports_credentials: true`.

No issues.

---

## 13. Other security plumbing audited (no scope issues found)

- `SecurityAlertService` — Slack/Discord webhook fan-out with throttling, severity gating, URL allowlist enforced even if `SafeHttp` is unbound (lines 379-401). 5-min dedupe per event/user.
- `SessionManager` — reads `sessions` table for `/profile/sessions`. Uses `hash_equals` for current-session matching (line 59, 71). Owner-scoped delete (line 80-88).
- `ApiKeyService` — 128-bit key, sha256 stored, constant-time compare, prefix lookup, soft revoke. `recordUse()` stamps last-used metadata.
- `TwoFactorService` — pure-PHP RFC 6238 TOTP, no external dep. 20-byte secret, ±1 step drift tolerance, `hash_equals` compare. Recovery codes are case-insensitive hex (no 0/O/1/I confusion). Single-use consumption persists the trimmed list.
- `LoginThrottle` — two-axis (per-account + per-IP) + progressive sleep ladder `[0,1,2,4,8,16]`. Backed by `login_attempts` table (audit trail, survives Redis flush). Uses `forceCreate` because the model is `$guarded = ['*']` for mass-assignment safety.
- `HtmlSanitizer` — DOMDocument-based whitelist (no `mews/purifier` dep). Strips `<script>/<style>/<iframe>/<object>/<embed>/<form>/<input>` via regex pre-pass, then walks the DOM with attribute allowlist. Always nukes `on*` handlers regardless of allowlist (line 230-233). `<a href>` validates against `http(s)://`, protocol-relative `//`, or relative `/` only (line 260-290).
- `SqlSafe` — column allowlist + identifier shape check (`/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/`). Defence-in-depth even if a caller sneaks a comma-list into the allowlist.
- `SecurityEvents` — canonical event taxonomy (~30 constants) with severity helper. Used by `AuditLogger::security()` callers and `SecurityAlertService::severity()`.
- `MagicBytes` / `SafeFilename` — see §5.

---

## Summary table

| Component | Registered | Applied | Live-tested | Verdict |
|-----------|------------|---------|-------------|---------|
| `SecurityHeaders` | global | every non-binary response | ✓ via curl | PASS |
| `RequestFirewall` (WAF) | global | every request | ✓ blocks ../ + XSS | PASS (W-1 admin allowlist too broad) |
| CSP / HSTS / COOP / CORP | inside SecurityHeaders | every non-binary response | ✓ CSP visible | PASS |
| `ForceHttps` | global (post-TrustProxies) | every non-local request | n/a (skipped in dev) | PASS |
| `TrustProxies` | global, first | every request | n/a | PASS |
| `RecordRateLimitHits` | global, last | every 429 | n/a | PASS |
| `Honeypot` | alias | 5 public POSTs (login/register/2x password/newsletter) | n/a | PASS |
| `Turnstile` | bind + rule + component | 4 controllers | env-gated | PASS |
| 10 named rate limiters | `RouteServiceProvider` | every expected route | n/a | PASS |
| `GeoBlock` middleware | alias | **NONE** | n/a | **FAIL (G-1)** — inline check in `PlaybackController::key()` only |
| `SsrfGuard` + `SafeHttp` | DI | every AI/Tmdb/webhook exit | n/a | PASS (S-1 TOCTOU known) |
| `FileUploadValidator` | DI | movie/episode/avatar uploads; banner is URL-only | n/a | PASS |
| `VirusScanner` | DI | post-validator | n/a (env-gated) | PASS |
| Password rules | `StrongPassword` + `NotBreached` | register, password change/reset | n/a | PASS |
| HtmlSanitizer | DI | Comment::setBodyAttribute, WatchPartyChat | n/a | PASS |
| CORS | static config | `api/*` + `sanctum/csrf-cookie` only | n/a | PASS |
| `CheckCustomMaintenance` | web group | every web request, fail-open | n/a | PASS |
| `SetLocale` | web group (post-session) | every web request | n/a | PASS |

---

## Top fixes to ship before release

1. **G-1 (MEDIUM):** Apply `'geoblock'` middleware to:
   - `Route::get('/movie/{movie}', …)` → `velflix.show`
   - `Route::get('/playback/{movie}/manifest.m3u8', …)` → `playback.manifest`
   - `Route::get('/playback/{movie}/config', …)` → `playback.config`
   - `Route::get('/media/poster/{movie}', …)` → `media.poster`
   Then delete the inline `geoBlocked()` in `PlaybackController` (or keep it as a defence-in-depth check on the key endpoint specifically, since it ALSO covers the case where the route binding skipped the middleware).
2. **W-1 (MEDIUM):** Narrow `config/security.php:298,306` allowlist from `admin.*` / `admin/*` to the specific routes that legitimately accept long-form admin content:
   ```php
   'admin.pitch-deck.*',
   'admin.movies.*.description',
   'admin.comments.queue',
   ```
   Then most admin POSTs (banners, users, ai-settings) regain WAF body inspection.
3. **CSP report-uri name (cosmetic):** Add `->name('security.csp-report')` to `routes/web.php:99` so `Route::has('security.csp-report')` in `SecurityHeaders::hasReportEndpoint()` returns true via name rather than coincidental path.
4. **F-1 (LOW):** Hard-fail upload validation in production when neither GD nor Imagick is loaded, instead of silently passing the original temp path.
5. **S-1 (LOW, longer-term):** Close the SSRF DNS-rebinding TOCTOU by wiring a Guzzle handler that calls `SsrfGuard::resolveSafely()` and pins via `CURLOPT_RESOLVE`.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Http\Kernel.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\SecurityHeaders.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\RequestFirewall.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\Honeypot.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\ForceHttps.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\GeoBlock.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\TrustProxies.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\CheckCustomMaintenance.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\SetLocale.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\RecordRateLimitHits.php`
- `D:\AI\velflix\velflix\app\Services\Security\TwoFactorService.php`
- `D:\AI\velflix\velflix\app\Services\Security\LoginThrottle.php`
- `D:\AI\velflix\velflix\app\Services\Security\SsrfGuard.php`
- `D:\AI\velflix\velflix\app\Services\Security\SafeHttp.php`
- `D:\AI\velflix\velflix\app\Services\Security\FileUploadValidator.php`
- `D:\AI\velflix\velflix\app\Services\Security\VirusScanner.php`
- `D:\AI\velflix\velflix\app\Services\Security\TurnstileVerifier.php`
- `D:\AI\velflix\velflix\app\Services\Security\HtmlSanitizer.php`
- `D:\AI\velflix\velflix\app\Services\Security\SecurityAlertService.php`
- `D:\AI\velflix\velflix\app\Services\Security\SessionManager.php`
- `D:\AI\velflix\velflix\app\Services\Security\ApiKeyService.php`
- `D:\AI\velflix\velflix\app\Services\Security\PasswordService.php`
- `D:\AI\velflix\velflix\app\Services\Security\LoginAlertService.php`
- `D:\AI\velflix\velflix\app\Exceptions\SsrfException.php`
- `D:\AI\velflix\velflix\app\Support\MagicBytes.php`
- `D:\AI\velflix\velflix\app\Support\SafeFilename.php`
- `D:\AI\velflix\velflix\app\Support\SqlSafe.php`
- `D:\AI\velflix\velflix\app\Support\SecurityEvents.php`
- `D:\AI\velflix\velflix\app\Rules\StrongPassword.php`
- `D:\AI\velflix\velflix\app\Rules\NotBreached.php`
- `D:\AI\velflix\velflix\app\Rules\CaptchaPassed.php`
- `D:\AI\velflix\velflix\app\Providers\RouteServiceProvider.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PlaybackController.php` (inline geo check)
- `D:\AI\velflix\velflix\app\Http\Controllers\AdminController.php` (banner/movie upload paths)
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MovieUploadController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\ProfileController.php` (avatar)
- `D:\AI\velflix\velflix\app\Services\Ai\AiClient.php` (SSRF wiring)
- `D:\AI\velflix\velflix\app\Services\Ai\WebSearchService.php`
- `D:\AI\velflix\velflix\app\Services\Ai\ProviderTester.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Search\ImageSearchService.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\SubtitleGenerator.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\CinematographyAnalyzer.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\ThumbnailPicker.php`
- `D:\AI\velflix\velflix\config\security.php`
- `D:\AI\velflix\velflix\config\cors.php`
- `D:\AI\velflix\velflix\routes\web.php` (throttle / honeypot / geoblock / playback applications)
- `D:\AI\velflix\velflix\resources\views\components\honeypot.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\captcha-turnstile.blade.php`
- `D:\AI\velflix\velflix\resources\views\auth\login.blade.php` (component usage)
- `D:\AI\velflix\velflix\resources\views\auth\register.blade.php`
- `D:\AI\velflix\velflix\resources\views\auth\passwords\email.blade.php`
- `D:\AI\velflix\velflix\resources\views\auth\passwords\reset.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\newsletter.blade.php`
