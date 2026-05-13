# FLiK Threat Model (STRIDE)

**Owner:** Security WG
**Last reviewed:** 2026-05-13
**Scope:** FLiK (codename Velflix) — Laravel 12 streaming platform, web + Android (NativePHP), Indonesia region.
**Methodology:** [STRIDE](https://learn.microsoft.com/en-us/azure/security/develop/threat-modeling-tool-threats) per asset, with explicit trust boundaries.

---

## 1. System diagram

```
                          .---------------------------------.
                          |       UNTRUSTED INTERNET        |
                          '---------------------------------'
                                          |
                          +-----------------------------------+
                          |  Browser / Android NativePHP app  |
                          |  (Video.js → Shaka planned)       |
                          +-----------------------------------+
                                          |  HTTPS (TLS 1.3)
============= TRUST BOUNDARY 1: edge =====|=====================
                                          v
                          +-----------------------------------+
                          |  CloudFlare (DNS / WAF / DDoS)    |
                          |  + Bunny CDN pull zone            |
                          +-----------------------------------+
                                          |
============= TRUST BOUNDARY 2: app DMZ ==|=====================
                                          v
                          +-----------------------------------+
                          |  Laravel 12 monolith (PHP 8.2)    |
                          |  - Web (nginx + php-fpm)          |
                          |  - Queue workers (Horizon)        |
                          |  - Schedule runner (cron)         |
                          |  - Livewire / Blade SSR           |
                          +-----------------------------------+
                          |             |            |       |
                          v             v            v       v
============= TRUST BOUNDARY 3: data ====|============|=======|==
                          |             |            |       |
                +------------------+ +-------+ +--------+ +-------+
                |  MySQL (primary  | | Redis | | Local  | | Mail  |
                |  + read replica) | |(cache,| | disk   | | SES   |
                |                  | | queue,| | (tmp,  | |       |
                |                  | | sess.)| | hls)   | |       |
                +------------------+ +-------+ +--------+ +-------+
                                          |
============= TRUST BOUNDARY 4: 3rd-party ====================
                                          v
   +----------------------------------------------------------+
   |  OUTBOUND INTEGRATIONS (each a separate trust domain)    |
   +----------------------------------------------------------+
   |  AI providers  | OpenAI / Anthropic / OpenRouter / etc.  |
   |  Bunny Storage | object storage + Stream HLS             |
   |  AWS S3        | optional alt storage                    |
   |  Midtrans      | payment gateway (Snap + webhook)        |
   |  Pusher        | realtime (watch party, presence)        |
   |  Mailchimp     | newsletter                              |
   |  Google OAuth  | social login (Socialite)                |
   |  TMDB          | metadata enrichment                     |
   |  MaxMind       | GeoLite2 IP db download                 |
   +----------------------------------------------------------+
```

### Trust boundary legend

| # | Boundary | Crossing | Controls |
|---|----------|----------|----------|
| 1 | Internet → Edge | TLS handshake | HSTS, TLS 1.3 only, CF WAF, CF Bot Fight |
| 2 | Edge → App | Reverse-proxied HTTP | App firewall (planned `waf-lite.md`), real-IP header, rate limits |
| 3 | App → Data | Internal network | DB credentials in env, Redis password, network ACL |
| 4 | App → 3rd party | Outbound HTTPS | Per-provider API key, signed webhooks, SSRF guard, allow-list |

---

## 2. Asset inventory

| ID | Asset | Sensitivity | Owner | Storage |
|----|-------|-------------|-------|---------|
| A1 | User Account (email, hash, role) | High | App | `users` table |
| A2 | Subscription State (plan, billing) | High | App | `subscriptions`, `subscription_plans` |
| A3 | Movie Catalog (metadata + media) | Medium | App / Bunny | `movies` + Bunny zone |
| A4 | DRM Keys & Tokens | **Critical** | Distribution | `drm_sessions`, on-disk key files (planned) |
| A5 | Admin Panel & Privileges | **Critical** | App | `users.is_admin`, `users.role`, Gate |
| A6 | Webhooks (Midtrans, AI callbacks) | High | App | inbound `/payment/notification` |
| A7 | Audit Log integrity | High | SecOps | `audit_logs`, `ai_usage_logs` |
| A8 | Comments / UGC | Medium | Trust & Safety | `comments` |
| A9 | PII / Watch history | High (GDPR + UU PDP) | Privacy | `watch_history`, `user_preferences`, `geo_*` |
| A10 | Encryption keys (`APP_KEY`, `BACKUP_KEY`, `DRM_KEY_*`) | **Critical** | SecOps | `.env` / KMS |

---

## 3. STRIDE per asset

### A1 — User Account

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** Spoofing | Stolen session cookie, OAuth account takeover by linking attacker email to victim's Google ID | Full account access | `SESSION_SECURE_COOKIE=true`, `SameSite=Lax`, OAuth state token + nonce, email verification before linking, login alert email on new device/IP, 2FA (TOTP — planned) | Partial |
| **T** Tampering | MITM session id swap on TLS-stripped network | Hijack | HSTS preload, secure + httpOnly cookie, `SESSION_DOMAIN` pinned | Done |
| **R** Repudiation | "I never logged in / never changed password" | Disputes | `audit_logs` records `auth.login`, `auth.logout`, `user.password_changed` with IP + UA | Partial — audit table exists, coverage incomplete |
| **I** Information disclosure | Username enumeration via login error message, password reset timing | Targeted attacks | Generic "credentials invalid" message, constant-time password reset response | Done |
| **D** DoS | Login form flooded, password reset spam | Lockout, mail bill | `throttle:login`, captcha (planned `captcha.md`), per-email reset throttle 1/15min | Partial |
| **E** Elevation of privilege | Pivot from user → admin via mass assignment of `is_admin` | Full system compromise | `$guarded = ['is_admin', 'role']` on `User`, Gate `admin` in `AuthServiceProvider`, audit on role change | **Verify** (`mass-assignment-audit.md`) |

### A2 — Subscription State

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** | Forged Midtrans webhook → mark unpaid sub as active | Revenue loss | Signature verification with server key + amount + order_id hash | Done (`PaymentController::notification`) |
| **T** | Modify `subscription.expires_at` via IDOR | Free access | `Policy@update`, server-only state transitions | Partial |
| **R** | "I never subscribed" | Dispute | Persist raw webhook payload in `audit_logs` with `webhook.midtrans.received` | Done |
| **I** | Leak of plan history | Privacy | Eloquent Policy gates `index`/`show` to owner + admin | Done |
| **D** | Replay old webhook to extend subscription | Free access | Idempotency key on `order_id`, reject if `payment_status='settled'` already | Done |
| **E** | Free-tier user grants self admin via subscription metadata | Compromise | Webhook only writes `subscription.*` columns, never touches `users.is_admin` | Done |

### A3 — Movie Catalog

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** | Forged "video upload complete" webhook from Bunny | Inject malicious file | HMAC on Bunny callback (planned), uploads only via authenticated admin | Partial |
| **T** | Edit `movies.video_disk` to point to attacker bucket | Phishing / malware | `$guarded` on `Movie`, admin Policy, audit on any mutation | Done |
| **R** | Admin edits catalog without log | Insider risk | `AuditLogger::log('movie.updated', $movie, …)` on every CRUD | Partial |
| **I** | Pre-release movie leaked via direct CDN URL | Embargo break | Signed Bunny token (`BUNNY_TOKEN_KEY`), DRM HLS, `published_at` gate | Done |
| **D** | Catalog scraper hammers `/movies?page=…` | Outage | `throttle:api`, CF caching for unauth list pages | Partial |
| **E** | Public consumer triggers transcoding job | Cost amplification | `TranscodeMovie` job only dispatched from admin or `flik:transcode:movie` console | Done |

### A4 — DRM Keys & Tokens

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** | Reuse leaked HLS bearer token across devices | Account sharing | `DeviceFingerprinter` + `ConcurrentStreamLimiter` (Redis counter) | Done |
| **T** | Edit JWT claim `movie_id` to unlock paid title on free plan | Free access | `DrmTokenService::validate` checks signature + entitlement + IP geo + exp | Done |
| **R** | "I never streamed Movie X" | Royalties | `drm_audit_events` (planned) row per key fetch with IP/UA/fingerprint | Partial |
| **I** | Key file readable by web user | Mass piracy | Keys stored outside webroot, served only via key-delivery endpoint behind auth | Done |
| **D** | Flood key-delivery endpoint | Stream outage | `throttle:drm`, per-IP + per-user limits | Partial |
| **E** | DRM session crafted with admin claim → bypass concurrency limit | Mass sharing | Token signing key separate from `APP_KEY`, claims allow-list validated | Done |

### A5 — Admin Panel

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** | Phished admin credentials | Total takeover | 2FA required for `is_admin=true` (planned), IP allow-list (planned), session 4h idle timeout | Pending |
| **T** | CSRF on `/admin/*` POST | Account creation | Laravel `VerifyCsrfToken` middleware + SameSite cookie | Done |
| **R** | Admin disables audit logging | Cover-up | `audit_logs` writes are inside DB transaction of action; deletion requires raw SQL | Partial |
| **I** | Admin search exposes other admins' actions | Insider | `/admin/audit-logs` only visible to `super_admin` role | Done |
| **D** | Bulk export endpoint exhausts memory | Outage | Streamed CSV (`AuditLogController::export` uses chunked cursor, 10K cap) | Done |
| **E** | Regular user opens `/admin/*` directly | Compromise | Route group `auth + can:admin`, Gate from `is_admin` boolean, navigation hides links | Done |

### A6 — Webhooks

| Threat | Vector | Impact | Mitigation | Status |
|--------|--------|--------|------------|--------|
| **S** | Spoofed webhook from attacker IP | Fraudulent state change | Per-provider signature check (Midtrans HMAC, Pusher signature) | Done |
| **T** | Replay old webhook | Subscription extension | Idempotency on provider tx id, reject duplicates within 24h | Done |
| **R** | Provider claims "we sent it, you ignored it" | Disputes | Raw body persisted in `audit_logs` with provider headers | Done |
| **I** | Webhook URL discovered → enumeration of order ids | Probing | Webhook endpoints don't leak existence of orders ("204 No Content" for unknown) | Done |
| **D** | Webhook flood | Queue saturation | Webhook handlers dispatch to `ai-batch` queue, sync response < 200ms | Partial |
| **E** | Webhook payload triggers admin task | Compromise | Webhook controllers never call admin-only services; all writes pass through Policies | Done |

---

## 4. Cross-cutting threats

### Mass-assignment / privilege escalation (E)
Every Eloquent model that has a privilege column (`users.is_admin`, `users.role`, `subscriptions.expires_at`, `movies.published_at`, `comments.moderation_status`) MUST list those fields in `$guarded`. Tracked in `mass-assignment-audit.md`.

### Watch-progress tampering (T) — XP/Coin farming
Client posts `progress_seconds` and is rewarded XP/Coins via `WatchHistoryController`. Without server validation a user could PATCH `/watch-history` with fake progress to farm rewards.
- **Mitigation**: server clamps `progress_seconds` to `min(client_value, movie.duration_seconds)`, requires monotonic increase, awards XP only when `progress >= 0.9 * duration` AND a DRM session existed (`drm_sessions` row) for that movie in the same hour. `Coin::award()` is wrapped in DB transaction with a row-level lock on `user_id`.

### AI cost amplification (D)
Unauthenticated or low-friction routes (`/api/movies/{movie}/plot-explain`, chatbot) call paid AI providers.
- **Mitigation**: `throttle:ai-realtime,10,60` (10/hr/user), authenticated routes only, `UsageTracker` hard-caps daily cost per user, `AiClient` short-circuits when monthly budget exceeded.

### Comment flood / spam (D, I)
- **Mitigation**: `throttle:comment,5,60` per user, honeypot field (planned `honeypot.md`), `CommentModerator` AI gate to `moderation_status='pending'` for new accounts (< 7 days old).

### SSRF (I/E)
`WebSearchService` and `CastBiographyEnricher` fetch arbitrary URLs from AI tool calls.
- **Mitigation**: outbound allow-list (Wikipedia, DuckDuckGo, TMDB only), block RFC1918 + 169.254/16 + localhost + metadata IPs, `MAX_REDIRECTS=2`, 5s timeout.

---

## 5. Threat matrix (heatmap)

Score = Likelihood × Impact (1–5 each, max 25).

| Asset | S | T | R | I | D | E | Worst |
|-------|---|---|---|---|---|---|-------|
| A1 User Account | **20** | 6 | 9 | 9 | 9 | **20** | 20 |
| A2 Subscription | 12 | 9 | 6 | 6 | 9 | 6 | 12 |
| A3 Catalog | 6 | 9 | 4 | 12 | 9 | 4 | 12 |
| A4 DRM Keys | **15** | 12 | 9 | **20** | 9 | 12 | **20** |
| A5 Admin Panel | **20** | 6 | 9 | 9 | 6 | **25** | **25** |
| A6 Webhooks | 12 | 9 | 4 | 4 | 9 | 6 | 12 |
| A7 Audit Log | 4 | 12 | 12 | 6 | 4 | 9 | 12 |
| A8 Comments | 4 | 4 | 4 | 9 | 12 | 4 | 12 |
| A9 PII | 6 | 4 | 4 | **20** | 4 | 6 | **20** |
| A10 Crypto keys | 9 | 4 | 4 | **25** | 4 | **20** | **25** |

**Top 5 risks (≥ 20):**
1. A5/E — Privilege escalation to admin (`mass-assignment-audit.md`, MFA)
2. A10/I — Encryption key leak (secrets scanning, KMS migration)
3. A10/E — `APP_KEY` rotation procedure (see `incident-response.md` §6.2)
4. A1/S — Stolen session / OAuth takeover (2FA roadmap)
5. A4/I — DRM key disclosure (`backup-restore.md`, key rotation cron)

---

## 6. Out of scope (for this revision)

- Native Android tamper-resistance (Frida hooks, root detection) — handled in mobile threat model.
- Physical security of the data center (Bunny / AWS responsibility).
- Insider threat modelling for AI provider employees (covered by DPAs).

---

## 7. Review cadence

- Quarterly review by Security WG.
- Ad-hoc review on any of: new payment integration, new AI provider, new admin role, schema change to `users` or `subscriptions`.
- Link findings into `pentest-checklist.md` and run a manual pass.
