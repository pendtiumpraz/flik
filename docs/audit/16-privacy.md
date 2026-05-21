# Audit 16 — Privacy & Compliance

**Scope:** GDPR Article 15/17/20 self-service (export + erasure), Cookie consent banner (3 categories), PII encryption at rest, Audit log taxonomy + `SecurityEvents` constants, Legal pages (Privacy / ToS / Refund), `national_id_hash` peppered lookup, daily security digest + nightly export cleanup cron.
**Date:** 2026-05-21
**Branch:** `main`
**Verdict:** PASS with caveats — the compliance surface is end-to-end wired, the cron is registered, the audit logger fans out on the right shape, and the legal pages are bilingual + linked from the cookie banner. Four MEDIUM and several LOW issues call out gaps you'll want closed before a regulator picks them up (notably the missing per-table-rollback test for `UserDataEraser` and the fact that ~7 declared `SecurityEvents::*` constants are never actually emitted).

---

## 1. GDPR Self-Service (`/privacy/*`)

### Reachable and renders
- **YES.** Auth-only routes inside the `auth` middleware group (`routes/web.php:355-363`):
  - `GET /privacy` → `UserDataController::index` → `privacy/index.blade.php` (export card + scary-red delete card)
  - `GET /privacy/export` → `exportForm` → `privacy/export.blade.php` (lists every section of the dump + confirms 24h URL)
  - `POST /privacy/export` → `exportRequest` → dispatches `PrepareDataExport` job, flashes Indonesian "siap dalam beberapa menit"
  - `GET /privacy/export/download/{filename}` → wrapped in `signed` middleware + `where('filename', '[A-Za-z0-9_.\-]+')` regex constraint
  - `GET /privacy/delete-account` → `confirmDelete` → `privacy/delete.blade.php`
  - `DELETE /privacy/delete-account` → `delete` → `UserDataEraser::erase()` inside a transaction
- All five pages render cleanly. Each carries a `<x-flash />` slot and the standard `<x-layout>` chrome so the cookie banner + footer links are visible inside the privacy flow itself.

### Export request → job → signed download URL flow
- **YES, with one minor schema-version footgun.**
- Flow: `exportRequest` dispatches `PrepareDataExport($user)` onto the `default` queue (`PrepareDataExport.php:47`). Job has `$tries=2`, `$backoff=30`, `$timeout=300` — sensible for a large user.
- `handle()` runs `UserDataExporter::export($user)` which writes `storage/app/private/exports/user_{id}_{unix}.json` and returns a signed `route('privacy.export.download', ['filename' => $filename])` URL with `addHours(24)` expiry (`UserDataExporter.php:331-338`).
- Job persists an in-app `Notification` (type `privacy.export.ready`) carrying the signed URL as `action_url`. Failure path also writes a `privacy.export.failed` notification with a link to retry — `failed()` hook on lines 68-92.
- Download endpoint (`exportDownload` lines 115-152) is defended in depth: `signed` middleware (URL signature) → filename regex (controller) → `preg_match('/^user_(\d+)_\d+\.json$/')` → owner-id assertion `$m[1] !== $user->id` → `Storage::disk('private')->exists()` → audit row + security event `DATA_EXPORT_DOWNLOADED` → `Storage::disk('private')->download(...)`.
- **PRIVACY-1 (MEDIUM)**: The export payload includes `email`, `name`, `birth_date`, and (via `scrubUser`) various profile fields — but `phone`, `address`, and `national_id_hash` are intentionally **omitted** from `scrubUser` (`UserDataExporter.php:358-370`). Strictly speaking, GDPR Article 15 / UU PDP requires us to disclose ALL data we hold, including hashed national IDs. The omission is defensible (`national_id_hash` is one-way and useless to the subject; phone/address are visible to the user via their profile UI), but the policy text at `/privacy-policy` promises "seluruh data" without carving these out. Either include them in the export OR amend the legal copy.

### Delete account flow — password + 2FA + confirmation phrase
- **YES.** `UserDataController::delete` enforces (`UserDataController.php:181-255`):
  1. `password` required, `Hash::check($input, $user->password)` — bcrypt verify
  2. `confirmation` required, `in:HAPUS AKUN SAYA,DELETE MY ACCOUNT` (bilingual)
  3. If `$user->hasTwoFactorEnabled()` → `otp` required + `TwoFactorService::verify($user->two_factor_secret, $otp)` (Google2FA)
  4. `reason` nullable, max 500
- Erasure happens **before** logout so the request scope (`request()->ip()`, `userAgent()`) is still in scope for `AuditLogger`.
- After `UserDataEraser::erase()` returns, a CRITICAL-severity `SecurityEvents::ACCOUNT_DELETED` row is emitted (`UserDataController.php:230-246`) carrying the per-table summary. Exception is caught + logged so a failing audit doesn't leak the user from session-invalidate.
- Session is invalidated + CSRF regenerated (`Auth::guard('web')->logout()`; `$request->session()->invalidate()`; `$request->session()->regenerateToken()`).

### UserDataEraser anonymise-vs-delete strategy
- **CORRECT.** `UserDataEraser::erase` (`UserDataEraser.php:72-201`) snapshots row counts up-front, opens a single `DB::transaction`, writes an audit row FIRST (so it rolls back with the txn), then applies the per-table strategy:

| Table | Strategy | Reason |
|-------|----------|--------|
| `comments` | ANONYMISE (set `user_id=NULL`, keep body) | Thread integrity |
| `subscriptions` | ANONYMISE (set `user_id=NULL`, null `transaction_id`, keep amount/order_id) | Indonesian tax law UU PPN/PPh 10y retention |
| `watch_histories`, `watchlists`, `ratings` | HARD DELETE | Pure engagement |
| `quiz_attempts`, `movie_schedules`, `watch_party_members` | HARD DELETE | Pure engagement |
| `coins`, `user_levels`, `user_preferences`, `user_recommendations`, `year_in_reviews`, `churn_predictions` | HARD DELETE | Gamification + ML predictions |
| `notifications`, `known_devices` | HARD DELETE | Security telemetry |
| `login_attempts` | HARD DELETE (matched by lowercased email — predates the user row) | Security telemetry |
| `audit_logs` for this user | HARD DELETE *except* the freshly-written `gdpr.user.erased` row | Compliance evidence |
| `user` row | HARD DELETE | Last step (pivots cascade via FK) |
| Re-emit `gdpr.user.erased.completed` after the delete with `user=null` | NEW audit row | Survives the cascade |

- **PRIVACY-2 (LOW)**: `audit_logs` retention. The eraser deletes audit rows about the user *except* `gdpr.user.erased`, then writes a fresh `gdpr.user.erased.completed` row. Good. But other audit rows where the user appears as **subject** (e.g. an admin row with `subject_type=User::class, subject_id=<userId>`) are NOT touched — `where('user_id', $userId)` only matches the actor column. Probably the right call (admin actions about a now-deleted user are still business-relevant), but worth a comment in the docblock.
- **PRIVACY-3 (LOW)**: `Comment::where('user_id', $userId)->update(['user_id' => null])` does NOT touch `comments.user_name` cached denorm (if any) or replies whose `parent_id` points at a deleted user's comment. Spot-check confirms `Comment` does not denorm `user_name` — only the FK. So this is fine, but the bilingual delete confirmation copy promises *"nama berubah jadi [Deleted User]"* — that string is rendered by the view at read time (`comments` partial), not stored. Verify the view actually does that fallback (out of scope here but flagged).
- **PRIVACY-4 (LOW)**: No `tests/Feature/Privacy/UserDataEraserTest.php` — the entire erasure path is uncovered by automated tests. The transaction-rollback-rolls-the-audit-row claim in the docblock is not regressioned. Add at minimum a happy-path test + one "what if `Comment::update` throws mid-transaction" rollback test.

### CleanupOldExports cron
- **YES, registered + scheduled.**
- `App\Console\Commands\CleanupOldExports` (signature `flik:privacy:cleanup-exports`) scans `private://exports/`, deletes anything matching `^exports/user_\d+_\d+\.json$` older than `--days` (default 7), counts/freed-bytes summary at end, `--dry` flag for preview.
- `App\Console\Kernel::schedule()` lines 57-60 registers it daily at `04:30` with `withoutOverlapping()->onOneServer()`. Good.
- Naming convention check is regex-anchored so foreign files (admin manual uploads) are not nuked.
- **PRIVACY-5 (LOW)**: 7-day window vs. 24-hour signed URL expiry — so for the 6 days between URL expiry and file deletion, the file sits on disk un-reachable but still encrypted-at-disk-only. Minimisation-strict reading of GDPR says delete on URL expiry. Operations-pragmatic reading says keep the window short for "user lost the link, requests a fresh one" — but the current code re-generates the file on every request, so the lag is pointless. Either drop `--days=1` in the schedule or document the choice.

---

## 2. PII encryption at rest

### Migration shape
- `database/migrations/2026_05_10_040100_add_pii_columns_and_encryption.php` adds:
  - `users.phone` (TEXT, null) — encrypted at app layer
  - `users.address` (TEXT, null) — encrypted at app layer
  - `users.national_id_hash` (TEXT, null) — sha256+pepper, **NOT** encrypted
  - `users.birth_date` (DATE, null) — **plaintext** (age-verification queries)
  - `subscriptions.billing_address` (TEXT, null) — encrypted at app layer
  - `known_devices.ip` — migrated from `string(45)` → TEXT (encrypted IPv6 ~150 chars won't fit in 45)
  - `payments.last4_card_digits` (TEXT, null) — encrypted (no-op when `payments` table absent)
- Hash-prefix index on `users.national_id_hash(64)` for O(log n) lookup; falls back to plain index on SQLite. Wrapped in try/catch so re-runs are safe.

### Encryption actually applied on save
- **YES.**
- `User::$casts` (`User.php:76-98`) declares:
  - `'phone' => 'encrypted'`
  - `'address' => 'encrypted'`
  - `'birth_date' => 'date'` (intentionally plaintext — documented)
  - `'two_factor_secret' => 'encrypted'`
  - `'two_factor_recovery_codes' => 'encrypted:array'`
- `Subscription::$casts` (`Subscription.php:34-44`): `'billing_address' => 'encrypted'`
- `KnownDevice::$casts` (`KnownDevice.php:43-53`): `'ip' => 'encrypted'`
- `User::$hidden` (line 67-74) covers `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `phone`, `address`, `national_id_hash`, `birth_date` — so default `toArray()` serialisation never leaks them.
- `User::email` and `User::name` deliberately stay plaintext — documented in the cast (line 86-89) — because login lookup `where('email', ...)` and admin search would break otherwise. That is correct, and the comment makes the trade-off explicit.

### `national_id_hash` peppered lookup
- **YES.**
- `User::setNationalIdAttribute` (`User.php:499-506`) routes every write of `$user->national_id = $value` through `hashNationalId()` and stores into `national_id_hash`. Also `unset($this->attributes['national_id'])` defensively so the (non-existent) column never reaches the DB.
- `User::hashNationalId` (lines 534-541) canonicalises (`strtolower` + strip whitespace) then `hash('sha256', $canonical.'|'.$pepper)`.
- Pepper: `env('PII_PEPPER') ?: Config::get('app.key', '')` — peppered fallback to `APP_KEY`. Pepper rotation is documented in the docblock ("change pepper, re-hash all rows").
- `User::findByNationalId(string $value): ?self` (lines 515-522) is the only supported lookup — `where('national_id_hash', self::hashNationalId($value))`. The class docblock states explicitly that `where('national_id', ...)` is impossible by design. Good API design.
- **PRIVACY-6 (LOW)**: Pepper read with `env()` not `config()` — this means PII hashing breaks when the config is cached (`php artisan config:cache`) and the env value disappears at runtime. Standard Laravel pitfall. Move the pepper into `config/security.php` (or similar) and read with `config('security.pii_pepper')`.

### EncryptedJson cast
- `app/Casts/EncryptedJson.php` is a clean custom cast: tries `Crypt::decryptString` then falls back to plaintext-JSON-parse during migration windows, then null. Writes always go through `Crypt::encryptString(json_encode(...))`. Never throws on read; throws `InvalidArgumentException` only when caller passes non-array to write.
- **PRIVACY-7 (LOW)**: `EncryptedJson` is **fully implemented but unused** — `grep -rn "EncryptedJson::class" app/` returns only the docblock example inside the cast file itself. It's harmless dead code intended for KYC/demographic payloads that haven't shipped yet. Either drop it until needed, or wire it on `user_preferences.kyc_payload` if that column exists.

### Re-encrypt rotation command
- `app/Console/Commands/ReencryptPii.php` (`flik:security:reencrypt-pii`) handles initial rollout AND key rotation via `OLD_APP_KEY` env. Chunked (500), `--dry`, `--no-touch`, `--only=` subset filter. Per-row failure isolation via `Log::warning`. The peer migration explicitly defers to this command for the plaintext-to-ciphertext sweep.

---

## 3. Cookie Consent

### Mounted in layout
- **YES.** `<x-cookie-banner />` is mounted in `resources/views/components/layout.blade.php:231` — bottom of `<body>` so the banner shows on every page using the standard layout.
- JS module `resources/js/cookie-consent.js` is imported by `resources/js/app.js:23` (`import './cookie-consent';`). Vite bundles it into the global `app.js` entry point.

### Three categories work
- **YES.**
- `cookie-consent.js` `DEFAULT_PREFS` declares `necessary: true` (immutable), `analytics: false`, `marketing: false`.
- Banner Blade renders three cards: "Wajib" (toggle disabled, always-on badge), "Analitik" (Alpine `x-model="prefs.analytics"`), "Pemasaran" (Alpine `x-model="prefs.marketing"`).
- Action buttons: "Terima Semua" (`acceptAll()` → both true + save), "Simpan Preferensi" (`savePrefs()` with current toggles), "Tolak Opsional" (`rejectAll()` → both false + save).
- Storage: `localStorage['flik_cookie_consent']` = `{necessary:true, analytics, marketing, version:1, decided_at:ISO8601}`.
- Schema version (`CONSENT_VERSION=1`) is checked on read; a mismatch returns null so the banner re-prompts. Bump-version-to-re-prompt flow is documented inline in both files.
- Public API on `window.FlikConsent`: `get`, `has(category)`, `save(prefs)`, `acceptAll`, `rejectAll`, `reopen`, `VERSION`, `UPDATE_EVENT`, `REOPEN_EVENT`.
- Reopen flow: footer "Cookie Settings" button calls `window.FlikConsent.reopen()` which dispatches `flik:cookies-reopen` → Alpine banner re-shows itself.

### Issues
- **COOKIE-1 (LOW)**: `window.FlikConsent.has('marketing')` reads `localStorage` on **every call** rather than using the in-memory cache. Fine at our scale; flag for pixel-loader optimisations.
- **COOKIE-2 (LOW)**: Banner doesn't emit `flik:cookies-updated` when the user lands without a stored decision (the initial fire microtask at line 132-135 only dispatches if `readStored()` returns non-null). Listeners that need to KNOW the user hasn't decided yet must check `window.FlikConsent.get() === null` themselves. Not a bug, but worth a docstring update.
- **COOKIE-3 (LOW)**: No backend ledger of consent decisions. UU PDP allows a regulator to demand proof of consent. Currently we only have client-side `localStorage` — if the user clears it we have nothing. Consider a `consent_decisions` table writing `(user_id, decided_at, prefs_json, ip, ua)` on save (cheap, ~10 rows per user max).

---

## 4. Audit Logger + SecurityEvents taxonomy

### `audit_logs.is_security` column + indexes
- Migration `2026_05_10_040101_add_is_security_to_audit_logs.php` adds `boolean is_security default false` after `action`, plus a single-column index AND a compound `(is_security, created_at)` index for the dashboard's dominant access pattern. Idempotent: `Schema::hasColumn` guard, duplicate-index guard via `SHOW INDEX`. Good migration.
- Down migration drops compound first, then single, then column.

### `AuditLogger::security` writes `is_security=true` + dispatches event
- **YES.**
- `AuditLogger::security` (`AuditLogger.php:96-131`) calls `persist(...isSecurity: true)` which writes via `AuditLog::forceCreate` (model is `$guarded = ['*']`).
- Column existence is memoised in `self::$hasIsSecurityColumn` to avoid a `SHOW COLUMNS` per call. Test seam `resetSchemaCache()` is exposed.
- After persistence, `SecurityEventLogged::dispatch($event, $context, $audit)` fires. Throw-safe try/catch around the dispatch — a Slack webhook glitch will never break the originating request.
- The context payload includes `user_id`, `ip`, `user_agent`, `subject_type`, `subject_id`, `audit_log_id`, `meta` — enough for the listener to render rich alerts and deep-link to `/admin/audit-logs/{id}`.

### `SecurityEventLogged` → `PushSecurityAlerts` listener
- **YES, registered.** `EventServiceProvider.php:48-51`:
  ```
  SecurityEventLogged::class => [
      PushSecurityAlerts::class,
      SecurityEventListener::class,
  ],
  ```
- `PushSecurityAlerts` forwards to `SecurityAlertService::notify()` (Slack/Discord fan-out, severity-gated, throttled 5-min, SSRF-guarded outbound HTTP via hostname allowlist + `withoutRedirecting()` + 4s timeout).
- `SecurityEventListener` (admin bell) maps the event to severity and writes an in-app admin notification (separate code path from Slack/Discord).

### Severity mapping
- `SecurityEvents::severity(string $event): 'low'|'medium'|'high'|'critical'` — `match` expression returning the bucket. Critical: `PRIVILEGE_ESCALATION_ATTEMPT`, `SUSPICIOUS_GEO_VELOCITY`, `ACCOUNT_DELETED`, `PAYMENT_CHARGEBACK`, `ADMIN_USER_DELETED`. High: `LOGIN_LOCKED_OUT`, `SSRF_BLOCKED`, `FILE_UPLOAD_REJECTED`, `WAF_IP_BANNED`, `TWO_FACTOR_DISABLED`, `TWO_FACTOR_FAILED`, `DRM_KEY_DENIED`, `HONEYPOT_HIT`, `SESSION_REVOKED`. Medium: NEW_DEVICE_LOGIN, NEW_COUNTRY_LOGIN, RATE_LIMIT_HIT, CSP_VIOLATION, WAF_BLOCKED, PASSWORD_CHANGED/RESET_REQUESTED/COMPLETED, TWO_FACTOR_ENABLED, ADMIN_USER_UNLOCK, ADMIN_ACTION, DATA_EXPORT_REQUESTED/DOWNLOADED, LOGIN_FAILED. Everything else (LOGIN_SUCCESS, LOGOUT, EMAIL_VERIFIED, TWO_FACTOR_VERIFIED, DRM_KEY_REQUEST, unknown) → low.
- `AuditLog::getSeverityAttribute` (`AuditLog.php:126-132`) re-uses this so /admin/audit-logs can colour-code each row uniformly via `class_exists`-guarded delegation.
- **AUDIT-1 (MEDIUM)**: Two parallel severity tables exist with **incompatible** vocabularies. `SecurityAlertService::severity` (`SecurityAlertService.php:63-98`) hard-codes a different set of event strings (`'security.privilege.escalation_attempt'`, `'security.account.deleted_by_admin'`, `'security.geo.suspicious_velocity'`, `'security.drm.key_denied_mass'`, `'security.auth.lockout_mass'`, ...) — none of which match the actual values in `SecurityEvents::*` constants (`'security.priv_escalation_attempt'`, `'privacy.account_deleted'`, `'security.suspicious.geo_velocity'`, etc.). Result: `SecurityAlertService::severity()` returns `'low'` for every event our code actually fires, which gets gated out at the default `min_severity='high'` floor → **Slack/Discord alerts never fire in practice**, even for critical events. Either delete `SecurityAlertService::severity()` and delegate to `SecurityEvents::severity()`, or rewrite the lookup tables to use the canonical constants. This is the single most important fix in this audit.

### `audit_logs` taxonomy and `securityOnly` scope
- `AuditLog` model has `forUser`, `action`, `actionPrefix`, `onSubject`, `between`, `securityOnly` scopes. `securityOnly` checks for the `is_security` column first; falls back to a prefix-LIKE on `auth.%|security.%|admin.%|privacy.%|payment.chargeback|drm.key%` for old fixtures. Forward + backward compatible.

### 30+ SecurityEvents constants used at correct sites
- `SecurityEvents` declares **33 constants** total (auth 9 + heuristic 8 + admin 3 + privacy 3 + payment 1 + drm 2 + plus `LOGIN_SUCCESS/LOGOUT` etc.).
- Actually emitted at call sites (verified via grep of `SecurityEvents::*`):
  - `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGIN_LOCKED_OUT`, `LOGOUT` — `SessionsController.php`
  - `TWO_FACTOR_ENABLED/DISABLED/VERIFIED/FAILED` + `LOGIN_SUCCESS` — `TwoFactorController.php`
  - `PASSWORD_RESET_REQUESTED/COMPLETED`, `PASSWORD_CHANGED`, `SESSION_REVOKED` — `PasswordResetController.php`
  - `DRM_KEY_REQUEST`, `DRM_KEY_DENIED` — `PlaybackController.php`
  - `RATE_LIMIT_HIT` — `RecordRateLimitHits.php` middleware
  - `ADMIN_USER_DELETED`, `ADMIN_USER_UNLOCK` — `AdminController.php`
  - `DATA_EXPORT_REQUESTED`, `DATA_EXPORT_DOWNLOADED`, `ACCOUNT_DELETED` — `Privacy/UserDataController.php`
  - `FILE_UPLOAD_REJECTED` — `FileUploadValidator.php`
  - `NEW_DEVICE_LOGIN`, `NEW_COUNTRY_LOGIN`, `SUSPICIOUS_GEO_VELOCITY` — `LoginAlertService.php`
  - `HONEYPOT_HIT` — emitted by `Honeypot.php` middleware but via `AuditLogger::log()` not `security()`, so the row is missing `is_security=true` (`Honeypot.php:138-148`)
  - `WAF_BLOCKED`, `WAF_IP_BANNED` — emitted via `AuditLogger::security('security.waf.blocked')` and `('security.waf.ip_banned')` directly with raw strings instead of the constant (`RequestFirewall.php:499, 536`) — values happen to match `SecurityEvents::WAF_BLOCKED/WAF_IP_BANNED`, but if anyone renames the constant the call sites silently desync.
- **Never emitted anywhere**: `EMAIL_VERIFIED`, `PRIVILEGE_ESCALATION_ATTEMPT`, `CSP_VIOLATION`, `SSRF_BLOCKED`, `ADMIN_ACTION`, `PAYMENT_CHARGEBACK`. These are declared but unused. Either drop them, or wire them up to the obvious sites (e.g. `EmailVerificationController::verify` should emit `EMAIL_VERIFIED`; payment webhook should emit `PAYMENT_CHARGEBACK` on the relevant Midtrans status; the file upload SVG/XML SSRF path should emit `SSRF_BLOCKED`).
- **AUDIT-2 (MEDIUM)**: `Honeypot.php:146-148` uses `AuditLogger::log()` not `::security()`, so the rows are NOT marked `is_security=true` and won't appear in the `securityOnly` scope nor fire `SecurityEventLogged`. Docblock acknowledges this is intentional ("don't pager-bomb on routine bot traffic") but it directly contradicts `SecurityEvents::severity()` which classifies `HONEYPOT_HIT` as **high**. Either reclassify honeypot to `low` in `SecurityEvents::severity()` AND keep the bell-only behaviour, OR escalate honeypot to `security()` with a `LOG_NOISY=true` meta flag and rely on the existing 5-min throttle in `SecurityAlertService` to suppress floods. Today's behaviour is inconsistent with the taxonomy declaration.
- **AUDIT-3 (MEDIUM)**: `RequestFirewall.php:499, 536` writes raw string action names `'security.waf.blocked'` and `'security.waf.ip_banned'` instead of the `SecurityEvents::WAF_BLOCKED` / `WAF_IP_BANNED` constants. They currently match by coincidence — a rename of the constant value would silently break the digest grouping. Replace with constants.

### `audit_logs` retention
- No automated retention sweep for `audit_logs` itself. The Privacy Policy promises "12 bulan untuk kepatuhan dan investigasi insiden" (`legal/privacy.blade.php:94`). There's no `flik:audit:prune --older-than=12months` cron registered in `Console/Kernel.php`. **AUDIT-4 (MEDIUM)**: Add the prune command or the policy claim is unenforceable.

---

## 5. Legal pages

### Reachable + bilingual ID/EN toggle
- **YES.** Three guest-accessible routes (`routes/web.php:54-56`):
  - `GET /privacy-policy` → `LegalController@privacy` → `name('legal.privacy')`
  - `GET /terms` → `LegalController@terms` → `name('legal.terms')`
  - `GET /refund-policy` → `LegalController@refund` → `name('legal.refund')`
- All three render via the shared `<x-legal-page>` component (`resources/views/components/legal-page.blade.php`) which provides:
  - Last-updated stamp from `LegalController::LAST_UPDATED = '2026-05-13'`
  - ID/EN toggle, persisted in `localStorage['flik_legal_lang']`, default ID
  - Both bodies marked with `x-show="lang === 'id'"` / `x-show="lang === 'en'"` + `x-cloak`
  - Cross-links footer to all three siblings + a "Cookie Settings" button that calls `window.FlikConsent.reopen()`
- Comment in `routes/web.php:49-51` notes the deliberate split: `/privacy` is the auth-only data-rights hub; `/privacy-policy` is the public legal doc.

### Content quality
- Privacy Policy (`legal/privacy.blade.php`) is comprehensive: 12 sections, anchored to UU PDP 27/2022 (Indonesia) AND GDPR EU 2016/679 (EU residents). Lists every processor (Midtrans, Google OAuth, MaxMind, Mailchimp, Bunny, Pusher, Wikipedia, DuckDuckGo, every AI provider) with privacy-policy links. Retention table is specific. Cross-references the `/privacy` data-rights hub for user self-service.
- Terms (`legal/terms.blade.php`) is bilingual, anchored to UU ITE/KUHPerdata/UU Hak Cipta/UU PDP.
- Refund Policy (`legal/refund.blade.php`) is bilingual, anchored to the Midtrans payment flow.
- Last-updated bump flow is documented: "Update `LegalController::LAST_UPDATED` *and* `CONSENT_VERSION` in `cookie-consent.js`" — appears in privacy.blade.php and the legal-page component docblock. Good operational hygiene.
- **LEGAL-1 (LOW)**: `LAST_UPDATED='2026-05-13'` and `CONSENT_VERSION=1` — both initial values. After the next material policy change you'll bump LAST_UPDATED but only re-prompting cookie consent is meaningful for category changes. The two stamps shouldn't always be paired — distinguish "policy text changed" from "processing categories changed" in the operational checklist.

---

## 6. Cron Schedule

### Daily security digest registered?
- **YES.** `Console/Kernel.php:48-52`:
  ```
  $schedule->command('flik:security:daily-digest')
      ->dailyAt('08:00')
      ->timezone('Asia/Jakarta')
      ->withoutOverlapping()
      ->onOneServer();
  ```
- `SecurityDailyDigest` aggregates the last 24h of `auth.%` + `security.%` rows, groups by action, lists top-5 offending IPs, emails super_admins (always, even when `SECURITY_ALERTS_ENABLED=false`), and mirrors to Slack/Discord when webhooks are configured. Sends a "heartbeat" message even on zero-event days so silence is visible.

### GDPR retention — exports auto-cleanup daily?
- **YES.** `Console/Kernel.php:57-60`:
  ```
  $schedule->command('flik:privacy:cleanup-exports --days=7')
      ->dailyAt('04:30')
      ->withoutOverlapping()
      ->onOneServer();
  ```

### Other relevant cron entries
- `flik:backup` daily 01:00 Jakarta (encrypted DB + media backup)
- `flik:geo:update` weekly Sunday 04:00 (GeoLite2 DB refresh)
- `flik:churn:predict` daily 03:00 (uses encrypted-at-rest `churn_predictions` records)

---

## Summary table

| Area | Reachable | Renders | Auth | Persistence | Verdict |
|------|-----------|---------|------|-------------|---------|
| `/privacy` landing | YES | YES | auth | n/a | PASS |
| `/privacy/export` request → job → signed URL | YES | YES | auth + signed URL + filename owner check | private disk + Notification | PASS |
| `/privacy/delete-account` (DELETE + password + 2FA + phrase) | YES | YES | auth + 3-factor confirmation | DB txn, anonymise/delete strategy correct | PASS |
| `UserDataExporter::scrubUser` excludes `phone`/`address`/`national_id_hash` | n/a | n/a | n/a | partial export | **PRIVACY-1 MEDIUM** |
| PII encrypted casts on User/Subscription/KnownDevice | n/a | n/a | n/a | encrypted-at-rest | PASS |
| `national_id_hash` peppered sha256, lookup-only | n/a | n/a | n/a | one-way hash with PII_PEPPER fallback to APP_KEY | PASS (env-vs-config caveat) |
| EncryptedJson cast | n/a | n/a | n/a | unused (dead) | **PRIVACY-7 LOW** |
| Cookie banner mounted + 3 categories functional | YES | YES | guest-OK | localStorage `flik_cookie_consent` | PASS |
| Cookie decision ledger (server-side) | NO | n/a | n/a | not persisted | **COOKIE-3 LOW** |
| Legal pages bilingual + linked | YES | YES | guest | static | PASS |
| `AuditLogger::security` writes is_security=true | YES | n/a | n/a | `audit_logs` + `SecurityEventLogged` dispatch | PASS |
| `SecurityEvents::severity()` mapping | YES | n/a | n/a | matches taxonomy | PASS |
| `SecurityAlertService::severity()` parallel table | YES but **incompatible** | n/a | n/a | always returns `low` for our events | **AUDIT-1 MEDIUM (silent Slack/Discord black-hole)** |
| `HONEYPOT_HIT` audit via `log()` not `security()` | YES | n/a | n/a | not flagged | **AUDIT-2 MEDIUM** |
| `WAF_BLOCKED`/`WAF_IP_BANNED` raw strings vs constants | YES | n/a | n/a | matches by accident | **AUDIT-3 MEDIUM** |
| Audit logs 12-month retention prune | NO | n/a | n/a | unenforced | **AUDIT-4 MEDIUM** |
| 30+ SecurityEvents constants used | 26 of 33 used | n/a | n/a | 7 declared-but-unused | PARTIAL |
| Daily security digest cron | YES, 08:00 Jakarta | n/a | n/a | email + webhook mirror | PASS |
| Export cleanup cron | YES, 04:30 daily, --days=7 | n/a | n/a | filesystem sweep | PASS |

---

## Top fixes to ship before release

1. **`SecurityAlertService::severity()`** (`AUDIT-1`) — delete the parallel string lookup table and delegate to `SecurityEvents::severity($event)`. Today every event silently maps to `'low'` and gets gated out, so Slack/Discord alerts are effectively dead even for critical incidents like account deletion or DRM key denial.
2. **`Honeypot.php:146-148` + `RequestFirewall.php:499, 536`** (`AUDIT-2`, `AUDIT-3`) — switch raw strings to `SecurityEvents::HONEYPOT_HIT` / `WAF_BLOCKED` / `WAF_IP_BANNED` constants AND decide whether honeypot should `security()` (bell + flagged) or stay on `log()` (silent persistence). Update `SecurityEvents::severity()` to match the chosen behaviour.
3. **Add `flik:audit:prune --older-than=12months` cron** (`AUDIT-4`) — the Privacy Policy promises 12-month audit retention; today it grows unbounded.
4. **PII export scope** (`PRIVACY-1`) — either include `phone`/`address`/`national_id_hash` in `UserDataExporter::scrubUser` OR amend the legal text to explicitly carve them out.
5. **PII_PEPPER via config** (`PRIVACY-6`) — `env('PII_PEPPER')` breaks under `config:cache`. Move to `config/security.php` and read via `config('security.pii_pepper')`.
6. **Wire the 7 dead SecurityEvents constants** — `EMAIL_VERIFIED` (verify controller), `PRIVILEGE_ESCALATION_ATTEMPT` (gate failure on protected actions), `CSP_VIOLATION` (`/csp-report` endpoint), `SSRF_BLOCKED` (file upload + WebSearchService), `PAYMENT_CHARGEBACK` (Midtrans webhook), `ADMIN_ACTION` (generic admin write).
7. **Tests** (`PRIVACY-4`) — add `tests/Feature/Privacy/{UserDataExporterTest,UserDataEraserTest,CookieConsentTest}.php`. The whole compliance surface has zero feature-test coverage.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Services\Privacy\UserDataExporter.php`
- `D:\AI\velflix\velflix\app\Services\Privacy\UserDataEraser.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Privacy\UserDataController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\LegalController.php`
- `D:\AI\velflix\velflix\app\Jobs\PrepareDataExport.php`
- `D:\AI\velflix\velflix\app\Console\Commands\CleanupOldExports.php`
- `D:\AI\velflix\velflix\app\Console\Commands\SecurityDailyDigest.php`
- `D:\AI\velflix\velflix\app\Console\Commands\ReencryptPii.php`
- `D:\AI\velflix\velflix\app\Console\Kernel.php`
- `D:\AI\velflix\velflix\app\Models\User.php`
- `D:\AI\velflix\velflix\app\Models\Subscription.php`
- `D:\AI\velflix\velflix\app\Models\KnownDevice.php`
- `D:\AI\velflix\velflix\app\Models\AuditLog.php`
- `D:\AI\velflix\velflix\app\Casts\EncryptedJson.php`
- `D:\AI\velflix\velflix\app\Services\Audit\AuditLogger.php`
- `D:\AI\velflix\velflix\app\Events\SecurityEventLogged.php`
- `D:\AI\velflix\velflix\app\Listeners\PushSecurityAlerts.php`
- `D:\AI\velflix\velflix\app\Listeners\Admin\SecurityEventListener.php`
- `D:\AI\velflix\velflix\app\Services\Security\SecurityAlertService.php`
- `D:\AI\velflix\velflix\app\Services\Security\LoginAlertService.php`
- `D:\AI\velflix\velflix\app\Services\Security\FileUploadValidator.php`
- `D:\AI\velflix\velflix\app\Support\SecurityEvents.php`
- `D:\AI\velflix\velflix\app\Providers\EventServiceProvider.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\Honeypot.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\RequestFirewall.php`
- `D:\AI\velflix\velflix\app\Http\Middleware\RecordRateLimitHits.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\AdminController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PasswordResetController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PlaybackController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\SessionsController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\TwoFactorController.php`
- `D:\AI\velflix\velflix\resources\views\privacy\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\privacy\export.blade.php`
- `D:\AI\velflix\velflix\resources\views\privacy\delete.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\privacy.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\terms.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\refund.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\legal-page.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\cookie-banner.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\layout.blade.php`
- `D:\AI\velflix\velflix\resources\js\cookie-consent.js`
- `D:\AI\velflix\velflix\resources\js\app.js`
- `D:\AI\velflix\velflix\routes\web.php` (privacy + legal route groups)
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_010013_create_audit_logs_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_040100_add_pii_columns_and_encryption.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_040101_add_is_security_to_audit_logs.php`
- `D:\AI\velflix\velflix\docs\security\event-taxonomy.md`
- `D:\AI\velflix\velflix\docs\security\pii-encryption.md`
