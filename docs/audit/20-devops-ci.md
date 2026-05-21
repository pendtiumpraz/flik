# Audit 20 — DevOps / CI / Testing / Feature Flags / Maintenance

**Scope:** PHPStan/Psalm configs + baselines, GitHub Actions workflows (dep-security, codeql, dast, static-analysis, php-lint), Dependabot, security.txt, `flik:doctor` command + Health dashboard, Maintenance mode (DB-backed kill switch), Feature flags (7 strategies), Settings registry (typed key/value), Test suite coverage, Pre-commit secret scanner.
**Date:** 2026-05-21
**Branch:** `main`
**Verdict:** PARTIAL — the engines are well-built (HealthChecker with 13 categories, FeatureFlag with all 7 strategies, MaintenanceState with super-admin guarantees, layered CI workflows) but **two release-blocking shipping gaps** sink the Feature Flags and Settings admin: their controllers + models + helpers + seeders all exist and the sidebar links to them, but **(1) no routes are registered** for either, and **(2) no Blade views exist** for either. Hitting `/admin/feature-flags` or `/admin/settings` will 404. Both sidebar entries silently disappear because the layout uses `Route::has()`. Several other landmines: PHPStan level 8 + Psalm errorLevel 2 are enabled with **no baselines**, the Health dashboard's "scheduler heartbeat" check is broken-by-design (Doctor is never scheduled so the cache key is always stale), the legacy `php-lint.yml` workflow duplicates the newer `static-analysis.yml` and pins an Ubuntu 20.04 runner that's deprecated, and the Feature test suite is **9 files total** — laughable for an app this size.

---

## Sidebar reachability check

`config/admin_menu.php` declares four entries in the operational system block: Health (`admin.health.index`), Maintenance (`admin.maintenance.index`), Feature Flags (`admin.feature-flags.index`), Settings (`admin.settings.index`). The layout in `resources/views/components/admin/layout.blade.php:201` filters every nav item through `Route::has($name)` before rendering. Consequence:

- Health: route exists (`routes/web.php:953`) — sidebar entry renders, link resolves. **PASS**
- Maintenance: route exists (`routes/web.php:940`) — sidebar entry renders, link resolves. **PASS**
- Feature Flags: **NO route registered** anywhere in `routes/web.php`. Sidebar entry is silently hidden — operators looking for the feature have no UI affordance. **FAIL**
- Settings: **NO route registered** anywhere in `routes/web.php`. Sidebar entry silently hidden. **FAIL**

**Reachability verdict:** 2/4 PASS.

---

## 1. Static analysis: PHPStan & Psalm

### Configs
- `phpstan.neon`: **level 8** (max strictness), `paths: app`, larastan extension wired, with three soft-tunings (`checkMissingIterableValueType=false`, `checkGenericClassInNonGenericObjectType=false`, `reportUnmatchedIgnoredErrors=false`) and an empty `ignoreErrors` block. Excludes only `app/Console/Commands/Inspire.php`. (`phpstan.neon`)
- `psalm.xml`: `errorLevel="2"` (also strict — Psalm scales 1=strictest → 8=loose). `findUnusedCode=false`. Project scan over `app/`, ignoring `vendor/`. Laravel plugin registered. (`psalm.xml`)

### Baselines
- **No `phpstan-baseline.neon`** in the repo root.
- **No `psalm-baseline.xml`** in the repo root.
- The `ignoreErrors:` block at `phpstan.neon:13` is empty.

Implication: every PR is expected to be 100 % clean at PHPStan **level 8** and Psalm **errorLevel 2**. That's extremely aggressive for a codebase of this size that mixes Eloquent magic, Blade-compiled views, and dynamic provider lookups. Either (a) CI is failing on every push (worth checking the badge), (b) there's an out-of-band baseline file gitignored or stored elsewhere, or (c) the suite actually does pass — which would be impressive but worth verifying.

### CI plumbing
- `.github/workflows/php-lint.yml` — **legacy** PHP-lint workflow. Pins `ubuntu-20.04` (deprecated by GitHub Actions in mid-2025), runs Pint --test, Psalm, and PHPStan in three separate jobs each depending on a `composer-install` job that caches `vendor/`. Uses `setup-php@master` (a moving target — should be pinned).
- `.github/workflows/static-analysis.yml` — **newer** parallel workflow, runs PHPStan + Psalm in two parallel jobs on `ubuntu-latest`, uses `--error-format=github` so findings annotate the PR diff. Runs `composer install` per job (no shared cache).
- **Both run on every push and PR** — duplicated work. The newer file is strictly better (proper annotations, current runner) but neither has been removed.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| SA-1 | HIGH | PHPStan level 8 + Psalm errorLevel 2 enabled with **no baselines**. Either CI is red on every push (verify the Actions tab) or this is dishonestly skipped. No path to gradual tightening exists. | `phpstan.neon`, `psalm.xml` |
| SA-2 | MED | Two overlapping workflows (`php-lint.yml`, `static-analysis.yml`) run the same Pint/Psalm/PHPStan checks on every push/PR — wasted CI minutes and divergent results possible. Pick one and delete the other (the newer one is better). | `.github/workflows/php-lint.yml`, `.github/workflows/static-analysis.yml` |
| SA-3 | MED | `php-lint.yml` pins `ubuntu-20.04`, which GitHub Actions deprecated. Builds will start failing without warning when the runner is removed. | `.github/workflows/php-lint.yml:11,36,58,80` |
| SA-4 | LOW | `setup-php@master` instead of a tagged version (`@v2`). A breaking change upstream silently breaks CI. | `.github/workflows/php-lint.yml:21,46,67,90` |
| SA-5 | LOW | `static-analysis.yml` doesn't cache `vendor/` — every run does a full `composer install` (~30-90s wasted per push). | `.github/workflows/static-analysis.yml:14,25` |
| SA-6 | INFO | `composer.json` `audit:mass-assignment` script greps for `$request->all()` patterns — handy but only runs locally, not in CI. Adding it as a `composer audit:mass-assignment` step in dep-security.yml would close the loop. |  |

---

## 2. Dependency & supply-chain workflows

### `.github/workflows/dep-security.yml`
Three jobs in parallel, all fail-fast (`continue-on-error: false`):
- `composer-audit` — `composer audit --format=plain --no-dev` (production deps only, sensible).
- `npm-audit` — `npm audit --omit=dev --audit-level=high` (high+ only — moderate/low waived).
- `trivy-fs` — filesystem scan, severity `CRITICAL,HIGH`, exit 1, `ignore-unfixed: true` (no false positives on un-patchable findings).

Triggers: every PR, every push to main, **weekly cron Monday 6 AM UTC**. Coverage is solid.

### `.github/workflows/codeql.yml`
- Language matrix: `javascript-typescript` only. PHP intentionally skipped (comment notes CodeQL's limited PHP support — true as of 2026, CodeQL's PHP analysis is still beta).
- Permissions correctly scoped (`security-events: write, actions: read, contents: read`).
- Triggers: PR + push to main + weekly Tuesday 6 AM UTC.

### `.github/workflows/dast.yml`
- ZAP baseline weekly Monday 8 AM UTC against the staging URL (`vars.STAGING_URL` with literal fallback `'https://staging.flik.example.com'`).
- Full active scan only on `workflow_dispatch` (correct — full scan would hammer production-like systems).
- `allow_issue_writing: true` → every new FAIL becomes a GitHub Issue with label `security/dast`.
- Rule overrides at `.zap/rules.tsv`:
  - `10015` IGNORE — Cache-Control on auth-only pages (accepted false positive).
  - `10016` IGNORE — deprecated XSS browser header.
  - `10049` WARN — Storable and Cacheable Content.
  - `10038` WARN — CSP report-only.
- `.zap/README.md` is thorough — documents local docker reproduction, severity meaning, triage workflow, and how to add suppressions. Good operator-facing doc.

### `.github/dependabot.yml`
- Three ecosystems: composer (weekly Monday 06:00 Asia/Jakarta, max 5 PRs, grouped by `laravel/*` family and `dev` deps), npm (same schedule, similar grouping), github-actions (monthly).
- Labels (`dependencies`, `php`/`js`/`ci`) align with GitHub's auto-label triggers.
- **`asia/jakarta` timezone is the project canonical** — keeps PRs landing in business hours.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| DEP-1 | LOW | `composer-audit` uses `--no-dev` (production only). Dev-only RCE in PHPUnit/Pint would slip through. Consider adding a second `composer audit` step without `--no-dev` that's `continue-on-error: true` so the dev advisory is visible in CI logs but doesn't fail the build. | `.github/workflows/dep-security.yml:16` |
| DEP-2 | LOW | `trivy-fs` doesn't upload SARIF to GitHub Security tab — findings only appear in workflow logs. Add `format: 'sarif'` + `github/codeql-action/upload-sarif` step. | `.github/workflows/dep-security.yml:30-39` |
| DEP-3 | INFO | Dependabot ignores no advisories — clean. Two groupings (`laravel`, `dev`) keep noise manageable. |  |

---

## 3. Security policy & disclosure surface

### `SECURITY.md`
- Email: `security@flik.example.com` + advisory link. **48h triage, 14d patch SLA.**
- Out-of-scope: DoS, physical, social-engineering — sensible.
- In-scope: auth bypass, SQLi/XSS/CSRF/SSRF/RCE/IDOR, DRM bypass, PII leakage.
- No bug bounty but hall-of-fame acknowledgement at `/security/hall-of-fame` (route not actually wired — no `Route::get('/security/hall-of-fame'...)` exists). **Minor: the URL referenced in `security.txt` Acknowledgments field points to a 404.**

### `public/.well-known/security.txt`
Compliant with RFC 9116:
- Two `Contact:` entries (email + form URL).
- `Expires: 2027-12-31T23:59:59.000Z` — well within the 12-month max-age recommendation (currently 19 months out as of 2026-05-21 — fine).
- `Encryption: …/pgp-key.txt` — referenced but **PGP key file is not present in `public/.well-known/`** (would 404).
- `Acknowledgments: …/security/hall-of-fame` — **route not registered** (would 404).
- `Canonical: https://flik.example.com/.well-known/security.txt` — placeholder domain; will need editing per deployment env.
- `Preferred-Languages: id, en` — Indonesian + English, matches the product.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| SEC-1 | MED | `security.txt` references `Encryption: …/pgp-key.txt` and `Acknowledgments: …/security/hall-of-fame` — neither URL is actually served by the app. Researchers following the contract will get 404s. Either remove the lines or ship the key + hall-of-fame route. | `public/.well-known/security.txt:4,5`; `SECURITY.md:13` |
| SEC-2 | LOW | `Canonical:` is a placeholder (`flik.example.com`). If served as-is the file fails RFC 9116 validation. Use config-driven templating (`@php` block) or a Blade-rendered route. | `public/.well-known/security.txt:7` |

---

## 4. `flik:doctor` console command

### Shape
- Signature: `flik:doctor {--json} {--quick} {--section=}`.
- Two-mode output: human table with ANSI colour and remediation hints, or `--json` machine-readable for monitoring.
- Exit code 1 when any check returns `fail`, 0 otherwise — pipeable into alerting.
- Delegates 100 % to `HealthChecker` — same engine as the admin dashboard. (`Doctor.php:39`)
- Stamps `doctor:scheduler_heartbeat` Cache key (TTL 10 min) on every manual run.

### `HealthChecker` — 13 categories, ~50 checks
| Category | Checks | Notes |
|----------|--------|-------|
| `system` | PHP version ≥ 8.2, 8 required extensions (`openssl, mbstring, pdo, tokenizer, json, curl, fileinfo, xml`), gd OR imagick, redis client (if configured), composer.json parses | Soft-fails when Redis not configured |
| `database` | PDO connection, pending migrations, 5 core tables present (`users, movies, ai_providers, audit_logs, maintenance_state`), `APP_KEY` non-empty | |
| `storage` | `public/storage` symlink, 4 storage subdir writability checks, 2 disk-fill probes (80% warn / 95% fail) | |
| `cache` | Roundtrip put/get/forget through the configured driver | |
| `queue` | Driver name, `jobs` table present, `failed_jobs` table present, per-queue depth (database driver only, warn>100 fail>1000), failed job count (warn>0 fail>50) | |
| `mail` | Driver, SMTP host + username when smtp, `mail.from.address` | |
| `redis` | PING when configured | |
| `disks` | Default filesystem, Bunny credentials when used, S3 bucket when used | |
| `ai` | `ai_providers` table present, ≥1 active provider, ≥1 default | |
| `security` | `APP_DEBUG` off in prod, `APP_KEY` set, `SESSION_SECURE_COOKIE` matches HTTPS APP_URL, 3 required middleware registered (`SecurityHeaders`, `RequestFirewall`, `ForceHttps`) via reflection | |
| `cron` | Scheduled command count, **scheduler heartbeat freshness** (warn if missing, fail if >5min old) | **BROKEN — see CRON-1** |
| `external` | TMDB / Mailchimp / Pusher / Midtrans credential presence (skipped network probe under `--quick`) | |
| `pwa` | `manifest.json` + `sw.js` present | |

**Total: ~50 distinct check invocations across 13 categories** — comfortably above the "25+" target.

Defensive design throughout: every `runX()` is wrapped in `try/catch` at the `runAll()` level (`HealthChecker.php:73-83`), so a bug in one category never takes down the whole report. Helpers `ok()/warn()/fail()` standardise the row shape. `humanBytes()` formats disk-fill values readably.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| DOC-1 | HIGH (always-warn) | **`flik:doctor` is never scheduled.** The Cron section checks `Cache::get('doctor:scheduler_heartbeat')` and fails if older than 5 minutes — but the only writer is the `Doctor::handle()` method itself, which only runs on manual invocation. `app/Console/Kernel.php` has zero `flik:doctor` entries. The heartbeat check will therefore **always report warn (missing) or fail (stale)** on a steady-state production install, polluting the dashboard's overall verdict. Either schedule `$schedule->command('flik:doctor --quick --json --section=system')->everyMinute()` or remove the freshness check. | `app/Console/Kernel.php` (missing); `app/Services/Doctor/HealthChecker.php:557-570` |
| DOC-2 | MED | `runSecurity()` uses reflection to read `$middleware` on `App\Http\Kernel` (`HealthChecker.php:518-522`). This assumes the legacy pre-Laravel-11 kernel layout — CLAUDE.md notes the project is on Laravel 12 with the old kernel still authoritative, so this works today, but if/when `bootstrap/app.php` is migrated to the minimal-app style the reflection will break silently (no `App\Http\Kernel` class). Add a `class_exists()` guard. | `HealthChecker.php:518-533` |
| DOC-3 | LOW | The `disks` category checks Bunny credentials by reading `env()` directly (`HealthChecker.php:442,443`). In production with config caching enabled (`php artisan config:cache`), `env()` outside `config/*.php` returns null — this check will silently report Bunny as broken on a cached deploy. Use `Config::get('services.bunny.storage_key')`. | `HealthChecker.php:442-449` |
| DOC-4 | LOW | `--section=` whitelist in HealthDashboardController (13 entries) duplicates the section list in HealthChecker — drift risk. Extract to a public `HealthChecker::SECTIONS` const. | `HealthDashboardController.php:52-55`; `HealthChecker.php:52-66` |

---

## 5. `/admin/health` dashboard

### Wiring
- Route: `Route::get('/health', [HealthDashboardController::class, 'index'])->middleware('can:system.health')` (`routes/web.php:953`). Permission seeded for both `admin` and `super_admin`.
- JSON poll route: `Route::get('/health/check/{section}', ..., 'runCheck')` constrained to `[a-z]+` (`routes/web.php:955-958`).
- View: `resources/views/admin/health/dashboard.blade.php` — full Alpine SPA-style polling.

### UX
- Big overall-status banner (green/yellow/red) keyed on `summary.overall`.
- 4 KPI cards (total / passing / warnings / failing).
- One collapsible card per section with status pill, check count, warn/fail counts in header.
- Cards default expanded when not-OK, collapsed when OK — operator-friendly.
- **Auto-refresh every 60 s** via JSON endpoint; Page Visibility API pauses polling when tab is hidden (`dashboard.blade.php:152-167`).
- "updated Ns ago" ticker on the header.
- Per-card "refresh this section" button on top of the global re-run.

### Limitation
The poller only updates the **header pill and counts** — the per-check list inside the card is NOT re-rendered (see `dashboard.blade.php:195-199` comment "leave the deep re-render for a future iteration"). So an operator watching a freshly-failed check sees the count tick up but has to click "Re-run all" to see the new row content. Acceptable shortcut, but worth knowing.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| HD-1 | LOW | The `runCheck` JSON endpoint defaults to `quick=true` (`HealthDashboardController.php:63`) — so the auto-refresh poller never re-runs the external API probes. Combined with the heartbeat bug (DOC-1), a degraded `cron` section will tick yellow forever even after the operator fixes cron. | `HealthDashboardController.php:63` |
| HD-2 | INFO | The auto-refresh code is inline Blade `@push('scripts')` — fine for now but harder to test than an extracted JS file. |  |

---

## 6. Maintenance mode (App-level kill switch)

### Architecture
- **DB-backed singleton** (`maintenance_state` table, id=1, seeded in the migration itself, `MaintenanceState.php:67-81`). Independent of Laravel's `php artisan down` file marker — both can coexist, framework's runs first in the global stack.
- Middleware: `App\Http\Middleware\CheckCustomMaintenance` (registered in `App\Http\Kernel::middlewareGroups['web']:69`, after `SubstituteBindings` + `StartSession` so `$request->user()` is hydrated for the role bypass logic).
- Controller: `App\Http\Controllers\Admin\MaintenanceController` with 4 actions (`index`, `enable`, `disable`, `update`).
- Routes: `routes/web.php:940-947`, all gated on `can:system.maintenance` (seeded **only to super_admin** — explicit by design, `RolePermissionSeeder.php:161-163`).
- Custom 503 view: `resources/views/errors/maintenance-custom.blade.php` — standalone HTML (does NOT extend the app layout — comment explains the circular-failure concern), with FLiK branding, live countdown when `scheduled_until` is set, auto-reloads when target passes.

### Bypass logic
`MaintenanceState::userCanBypass($user, $ip)` returns true if ANY:
1. IP in `allow_ips` array.
2. User has `isSuperAdmin()` (defence in depth).
3. User has any role in `allow_roles` array.

The `enable()` and `updateConfig()` methods **forcibly inject `super_admin` into `allow_roles`** if the admin omitted it (`MaintenanceState.php:116-118, 177-179`) — bulletproof against the "admin locks themselves out" footgun.

The middleware short-circuits on `/admin/maintenance*`, `/login`, `/logout`, `/2fa*`, `/healthz*`, `/build/*`, `/storage/*`, `/favicon.ico` (`CheckCustomMaintenance.php:80-95`) — even a non-super-admin admin can always reach the toggle.

**Fail-open posture:** if `maintenance_state` table is missing or any exception fires inside the middleware, the request proceeds (`CheckCustomMaintenance.php:65-72`). Explicitly documented: "A bug in this middleware MUST NOT take the site down." Correct.

### Admin UI
- `resources/views/admin/maintenance/index.blade.php`:
  - Big red/green banner showing current state + "enabled by X / Y ago" + scheduled-until countdown.
  - Two-column layout: form (left) + activation history sidebar (right).
  - Form fields: message (textarea, 1000 char max), `scheduled_until` (datetime-local), `allow_ips` (textarea, one per line, shows operator's own IP), role checkbox grid (with `super_admin` disabled+checked+forced via hidden input).
  - Distinct "Save Settings Only (don't enable)" button when site is live — lets you pre-stage an outage notice.
  - Activation history sidebar shows last 30 `maintenance.*` audit log rows.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| MT-1 | LOW | `CheckCustomMaintenance` does not short-circuit `/admin/login` (only `/login`). The auth split (`SessionsController` for email/password) does live at `/login`, so this is fine — but if a future migration mounts admin login under `/admin/login` the lockout window opens. | `CheckCustomMaintenance.php:84` |
| MT-2 | LOW | `allow_ips` doesn't accept CIDR ranges (documented in the view at `index.blade.php:99`). Office-IP allow-lists with rotating /24 ranges have to be expanded by hand. Low priority but worth noting. | `MaintenanceController::validated()` |
| MT-3 | INFO | `scheduled_until` is stored but the middleware doesn't auto-disable past that point — the countdown on the 503 page hits 00:00:00 and reloads, but the operator still has to manually disable. The model's docblock at `2026_05_10_210001_*.php:54` calls this an "optional future enhancement". |  |

---

## 7. Feature flags

### Models, services, helpers — all present
- **Model**: `App\Models\FeatureFlag` (250 lines). Implements all **7 strategies**: `off`, `on`, `role`, `percentage`, `users`, `authed`, `guests`. Constant `FeatureFlag::STRATEGIES` is canonical.
  - `evaluate(?User $user)` — master switch + strategy dispatcher. **Per-(key, user) cache with TTL 300 s**. Invalidation via `static::booted()` incrementing a version sentinel (`feature_flag.version.<key>`) on save/delete, so re-evaluation cost on a flag flip is O(1) without enumerating user-keyed entries.
  - `evaluatePercentage()` — deterministic bucket via `crc32($key . ':' . $userIdentifier) % 100`. **Hashes on flag-key+user** (not user alone) so two independent 25% flags don't roll out to the same 25% of users. Correct.
  - `guestBucketId()` — falls back to session id; sentinel `'no-session'` for CLI/queue contexts. Acceptable.
  - All evaluation errors caught and logged as `Log::warning`, returning `false` (fail-closed — a broken flag never accidentally enables a feature).
- **Service**: `App\Services\Features\FeatureManager` — singleton bound in `AppServiceProvider:77`. Static `::active()` accessor, instance `enabled()`, `allFor()`, `enable()`, `disable()`.
- **Global helper**: `feature('key', ?$user)` from `app/Helpers/features.php`, autoloaded via `composer.json` `autoload.files`. **Triple-defensive**: catches container-resolution failure, catches evaluator exceptions, falls back to `false`.
- **Blade directive**: `@feature('key') ... @endfeature` registered in `AppServiceProvider:178-180` (uses `Blade::if`, compiles to plain if/endif).
- **Migration**: `database/migrations/2026_05_10_200001_create_feature_flags_table.php` — `key` (unique 80), `name`, `description`, `is_enabled` bool, `strategy` string(16), `strategy_config` JSON, `rollout_started_at` timestamp, timestamps. Indexed on `key` and `(is_enabled, strategy)`. Safe re-run via `Schema::hasTable` guard.
- **Seeder**: `database/seeders/FeatureFlagSeeder` — 4 starter flags (`tv_series_section`, `new_homepage_layout` at 20%, `experimental_ai_chat` role-gated, `christmas_theme` off). Idempotent, won't reset operator changes. Wired into `DatabaseSeeder.php:69`.
- **Controller**: `App\Http\Controllers\Admin\FeatureFlagController` — 246 lines, full CRUD with strategy-config normalisation (drops stale fields when strategy changes — clean), audit logging via `AuditLogger::security()` on every mutation, role checkbox grid powered by the `Role` catalog with `class_exists` guard for pre-RBAC installs.
- **Permission seed**: `system.feature_flags` granted to `admin` and `super_admin` (`RolePermissionSeeder.php:102, 159`).

### What's missing — **release blockers**
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| FF-1 | **CRITICAL** | **No routes registered.** `routes/web.php` has zero references to `FeatureFlagController`, `admin.feature-flags.*`, or `/admin/feature-flags`. Hitting the sidebar entry (which is silently hidden by the `Route::has` filter) or the URL directly returns 404. The controller is dead code in shipped form. | `routes/web.php` (missing) |
| FF-2 | **CRITICAL** | **No Blade views exist.** Controller renders `admin.feature-flags.index` and `admin.feature-flags.edit` (`FeatureFlagController.php:41, 102`). Directory `resources/views/admin/feature-flags/` does not exist. Even if routes were wired, both index and edit would throw `View [admin.feature-flags.index] not found`. | `resources/views/admin/feature-flags/` (missing) |
| FF-3 | LOW | `FeatureManager::enabled()` calls `auth()->user()` directly when no user is passed (line 42). In a queue worker / CLI context with no auth guard, this returns null gracefully (so `authed` strategy correctly returns false) — fine, but worth documenting. | `FeatureManager.php:42` |
| FF-4 | INFO | `FeatureFlagController::store()` doesn't seed a `rollout_started_at` because the new flag is always created with `strategy='off'` (line 70). Correct — `rollout_started_at` should only stamp when the flag actually goes live. |  |

---

## 8. Settings registry

### Models, helpers — all present
- **Model**: `App\Models\Setting` (336 lines). Single text column serves 6 types (`string`, `int`, `float`, `bool`, `json`, `array`) via `getValueAttribute()` / `setValueAttribute()` mutators routing to `castOut()` / `castIn()`. **Lossless** roundtrip on bool (handles `1`/`0`/`true`/`false`/`"true"`/`"on"`/etc.) and int.
  - `get($key, $default)` — 1-hour Redis-friendly cache (`setting.value.<key>`), missing-row marker `['__missing'=>true]` distinguishes "no row" from "value is null".
  - `set($key, $value)` — upsert with auto-type inference if creating.
  - `group($name)` — grouped collection, 1-hour cache per group.
  - `publicMap()` — returns only `is_public=true` rows for shipping to the frontend.
  - `booted()` busts cache on save/delete + handles `group` rename so the old tab's bucket cache evicts.
  - `tableExists()` swallows boot-time DB-not-ready exceptions so the `setting()` helper is safe to call before migrations run.
- **Global helper**: `setting('key', $default)` from `app/Helpers/settings.php`, autoloaded via `composer.json` `autoload.files`. Defensive — any exception falls back to `$default`.
- **Blade directive**: `@setting('site.name', 'FLiK')` registered in `AppServiceProvider:185-187`. Compiles to `<?php echo e(setting(...)); ?>` — auto-escapes like `{{ }}`. Good safety contract.
- **Migration**: `2026_05_10_200002_create_settings_table.php` — `key` (unique 120), `value` text nullable, `type` string(12), `group` string(40), `description`, `is_public` bool, `is_secret` bool, `validation_rules` string(200), timestamps. Indexed on `group` and `is_public`.
- **Seeder**: `database/seeders/SettingSeeder` — **18 seeded keys** across branding/social/limits/ai groups. Idempotent: re-run overwrites only metadata (description/group/type/flags), preserves operator-edited `value`. Public `restoreDefaults()` static method does the destructive reset for the "Restore defaults" admin button.
- **Controller**: `App\Http\Controllers\Admin\SettingsController` — bulk-update endpoint with per-row validation from `validation_rules` column, transaction-wrapped, audit logging via `AuditLogger::security()` with secret-value redaction, "no-op" detection (skips save + audit if value unchanged).
- **Permission**: `system.settings` granted to `admin` + `super_admin`.

### Same release blockers as Feature Flags
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| ST-1 | **CRITICAL** | **No routes registered.** `routes/web.php` has zero references to `SettingsController`, `admin.settings.*`, or `/admin/settings`. (Note: there IS an unrelated `admin.ai.index` for AI provider settings — these are different.) | `routes/web.php` (missing) |
| ST-2 | **CRITICAL** | **No Blade views exist.** Controller renders `admin.settings.index` (`SettingsController.php:45`). Directory `resources/views/admin/settings/` does not exist. Will throw `View [admin.settings.index] not found` the moment routes are wired. | `resources/views/admin/settings/` (missing) |
| ST-3 | LOW | `Setting::castIn()` for `json`/`array` types accepts already-encoded JSON strings and preserves them (`Setting.php:269-273`). The admin form likely posts strings (textareas), so this is correct — but `is_string($value) && self::isValidJson($value)` will pass for `"42"` (a numeric literal IS valid JSON), so an admin pasting `42` into a `json`-typed field stores `42` (not `"42"`). Probably what they want, but counter-intuitive. | `Setting.php:269` |
| ST-4 | INFO | `publicMap()` caches under `setting.public-map` but the cache-bust hook (`booted()`) only forgets `setting.value.<key>` and `setting.group.<group>` — **NOT** `setting.public-map`. An admin flipping a setting's `is_public` from true→false will continue exposing it in the public map for up to 1 hour. | `Setting.php:55-71, 218` |

---

## 9. Test suite coverage

### Inventory
```
tests/
├── CreatesApplication.php
├── ExampleTest.php
├── Pest.php
├── TestCase.php
├── Feature/                                              (9 files)
│   ├── Admin/MovieBulkActionTest.php                     (1)
│   ├── AuthenticationTest.php                            (1)
│   ├── Authorization/                                    (4)
│   │   ├── AdminRouteGuardTest.php
│   │   ├── ProfilePermissionsViewTest.php
│   │   ├── RoleAssignmentTest.php
│   │   └── RoleCrudTest.php
│   ├── Gamification/StreakServiceTest.php                (1)
│   ├── RegistrationTest.php                              (1)
│   └── Security/RequestFirewallTest.php                  (1)
└── Unit/                                                 (3 files)
    ├── ExampleTest.php
    ├── HtmlSanitizerTest.php
    └── Security/SsrfGuardTest.php
```

**Totals: 16 PHP files in tests/, of which 9 are Feature tests and 3 are Unit tests** (plus boilerplate `Pest.php`, `TestCase.php`, `CreatesApplication.php`, two `ExampleTest.php`).

### Coverage by domain
| Domain | Tests | Verdict |
|--------|-------|---------|
| Authentication (login, register) | 2 | Minimal but present |
| Authorization (RBAC, role CRUD) | 4 | Decent — the RBAC redesign got real coverage |
| Admin operations | 1 (MovieBulkAction) | Wildly thin |
| Gamification | 1 (StreakService) | Single service covered |
| Security | 2 (RequestFirewall feature + SsrfGuard unit) | Two of the 30 security swarms have tests |
| **AI tasks (15+ services)** | **0** | None |
| **DRM / playback** | **0** | None |
| **Subtitle pipeline** | **0** | None |
| **Recommendations engine** | **0** | None |
| **Payments / Midtrans** | **0** | None |
| **Feature flags** | **0** | Despite shipping the model + service + helper + Blade directive |
| **Settings registry** | **0** | Same — no tests for type coercion, cache busting, or the helper |
| **Health checker / Doctor** | **0** | None |
| **Maintenance kill switch** | **0** | None |

### Configuration
- `phpunit.xml`:
  - Two suites: `Unit` (`./tests/Unit`) and `Feature` (`./tests/Feature`).
  - Coverage scope: `./app` (`processUncoveredFiles="true"`).
  - In-memory test env defaults: `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`.
  - **SQLite in-memory connection is commented out** (`phpunit.xml:24,25`) — meaning Feature tests run against whatever `DB_CONNECTION` resolves at runtime. In CI without a `DB_CONNECTION=sqlite` env override, this would attempt to hit a real MySQL. Risky.
- `composer.json` has Pest autoloader allowed (`pestphp/pest-plugin: true`) but PHPUnit 11 is the actual test runner; `tests/Pest.php` is present but appears unused.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| T-1 | HIGH | **Test coverage is laughably thin for a codebase this size.** 9 Feature tests + 3 Unit tests covers <5% of the controller surface area. Critical paths (AI tasks, DRM, payments, subtitles, feature flags, settings) have zero tests. The CI workflows run them but they can't catch much. | `tests/` |
| T-2 | HIGH | `phpunit.xml` has the SQLite in-memory `DB_CONNECTION` lines commented out. Feature tests with `RefreshDatabase` will hit whatever DB is configured in the environment — in local dev that's MySQL with real data. Uncomment those two lines. | `phpunit.xml:24-25` |
| T-3 | MED | `tests/ExampleTest.php` + `tests/Unit/ExampleTest.php` are unmodified Laravel scaffolding. Delete or replace. | `tests/ExampleTest.php`, `tests/Unit/ExampleTest.php` |
| T-4 | LOW | `tests/Pest.php` exists but `composer.json` only has the Pest plugin allowed, not `pestphp/pest` itself in require-dev. Either install Pest or remove the file. | `tests/Pest.php`, `composer.json:81` |

---

## 10. Pre-commit secret scanner (`.githooks/pre-commit`)

86 lines of bash, designed as a developer-installable hook (`git config core.hooksPath .githooks`). NOT installed automatically — explicitly opt-in per dev. Bypass via `git commit --no-verify`.

### What it catches
1. **Any staged `.env*` file** other than `.env.example` — rejects committing `.env`, `.env.production`, `path/to/.env.local`, etc.
2. **Pattern-match on newly-added lines only** (`git diff --cached -U0`, lines starting with `+` but not `+++`):
   - `sk-…` (OpenAI / OpenRouter style, 20+ chars)
   - `sk_live_…` / `sk_test_…` / `pk_live_…` (Stripe)
   - `AIza[35 chars]` (Google API)
   - `ghp_[36 chars]` (GitHub PAT)
   - `xox[baprs]-…` (Slack)
   - JWT triple-segment `eyJ…\.eyJ…\.…`
3. **Generic plaintext secret assignments** — `(password|api_key|secret) = '…value of 8+ chars…'`, with a whitelist for obvious defaults (`null`, `secret`, `password`, `changeme`, `example`, `YOUR_…`, `<…>`).

Defensive: only inspects newly-added lines (skips diff context), uses POSIX `grep -E` (portable across Linux/macOS), exits 1 with a coloured "blocked" message including the first 80 chars of the offending line.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| PCH-1 | MED | Hook is **opt-in per developer** (requires manual `git config core.hooksPath .githooks`). The README doesn't mention it; new contributors won't enable it. Consider adding a `composer post-install-cmd` that runs `git config core.hooksPath .githooks` for the project. | Project setup |
| PCH-2 | LOW | The generic secret regex `(password\|api[_-]?key\|secret)` is case-insensitive but the value-anchor `[A-Za-z0-9/_+=-]{8,}` allows base64 padding (`=`) — fine — but it does not catch `PRIVATE_KEY=-----BEGIN…` PEM-format keys. Add a literal `-----BEGIN ` check. | `.githooks/pre-commit:55` |
| PCH-3 | INFO | The hook uses `printf '%s'` rather than `echo` for portability — correct call. ANSI colour codes also work on the common Win-bash setups. |  |

---

## 11. Cross-cutting findings

### Sidebar drift
The admin sidebar (`config/admin_menu.php`) is the single source of truth for navigation. **Two sidebar entries silently point at nonexistent routes** (Feature Flags + Settings — see §7, §8). The `Route::has()` filter in `layout.blade.php:201` hides them gracefully (no 500), but **also hides the symptom**: an operator looking for Feature Flags simply won't see the menu item and will assume the feature isn't built. The shipping gap is invisible until you read the audit code.

A defensive complement would be a **CI test** that walks every `admin_menu.php` route reference and asserts `Route::has()` for each one — would have caught this immediately.

### Feature gating pattern compliance
- Feature flags: `feature('key')` follows the documented "graceful degrade on missing" rule (triple-defensive in the helper). 
- Settings: `setting('key', $default)` same pattern. 
- Health checker: per-section `try/catch`, so a buggy check never takes down the dashboard. 
- Maintenance middleware: fail-open on any exception. 

All four engines follow the project's "ENV-driven feature gating" convention from CLAUDE.md — **the engines are top-shelf; only the shipping surface is broken.**

### Audit-log integration
Every mutating action in the operational stack writes to `audit_logs` via `AuditLogger::security()` or `AuditLogger::log()`:
- `MaintenanceController::enable/disable/update` → `maintenance.enabled` / `maintenance.disabled` / `maintenance.updated` (and the Maintenance index view renders the history sidebar from those rows).
- `FeatureFlagController::store/update/destroy` → `admin.feature_flag.created` / `.updated` / `.deleted`, with full before/after diff in `meta`.
- `SettingsController::update/seed` → `admin.setting.updated` (per-key, with secret values redacted to `***`) / `admin.setting.restore_defaults`.

Trail is clean — Audit 17 (Analytics) findings show the `audit_logs` dashboard renders these.

---

## 12. Verdict summary

| Subsystem | Engine | Surface (routes/views/UX) | Tests | Overall |
|-----------|--------|---------------------------|-------|---------|
| PHPStan + Psalm static analysis | level 8 / errorLevel 2 — strict, no baseline | CI wired (twice — duplicate workflows) | n/a | **PASS with concerns** (no baselines, duplicated workflows, deprecated runner pin) |
| Dependency security (composer/npm/trivy/codeql/dependabot/dast) | Comprehensive — 5 workflows, weekly + on-push triggers, Dependabot Jakarta-time grouped | n/a | n/a | **PASS** |
| `security.txt` + SECURITY.md | Files present, RFC 9116 compliant | References URLs (PGP key, hall-of-fame) that don't exist | n/a | **PARTIAL** (broken links) |
| Pre-commit secret scanner | 86 lines, all 7 key formats + generic + .env block | Opt-in per dev (not auto-installed) | n/a | **PARTIAL** |
| `flik:doctor` CLI + HealthChecker | 13 categories, ~50 checks, defensive | CLI works, JSON output works | 0 | **PARTIAL** (heartbeat check broken-by-design, env() in cached config issue) |
| `/admin/health` dashboard | Polling, Page Visibility API, per-section refresh | View ships, route wired, permission seeded | 0 | **PASS** |
| Maintenance mode (kill switch) | DB singleton, super_admin auto-bypass, fail-open middleware | Routes wired, view ships, custom 503 page ships | 0 | **PASS** |
| Feature flags | 7 strategies, cached eval with version-bump invalidation, audit-logged controller | **Routes missing, views missing** — controller is dead code | 0 | **FAIL (shipping gap)** |
| Settings registry | Typed key/value, 6 types, cached, audit-logged bulk updates, restore-defaults | **Routes missing, views missing** — controller is dead code | 0 | **FAIL (shipping gap)** |
| Test suite | n/a | n/a | 9 Feature + 3 Unit, ~5% coverage | **FAIL** |

**Headline:** the project's operational/CI engines are mature and the security posture (workflows, SAST, DAST, Dependabot, hook) is well thought through, **but two flagship admin dashboards (Feature Flags, Settings) ship as dead code** — the controllers, models, services, helpers, Blade directives, permissions, migrations, and seeders are all present and tested-by-eye, but **no routes are registered and no views exist**. The sidebar's `Route::has()` filter hides this, making it easy to miss in code review.

**Fix order (rough effort):**
1. **(FF-1, FF-2, ST-1, ST-2) — CRITICAL** — add 4 routes and ~4 views to ship Feature Flags + Settings admins. ~half a day. Single biggest impact in this audit.
2. **(DOC-1) — HIGH** — either schedule `flik:doctor --quick --json` every minute or remove the heartbeat check. ~5 minutes.
3. **(SA-1) — HIGH** — generate `phpstan-baseline.neon` and `psalm-baseline.xml` so PRs aren't blocked while we backfill cleanups. ~10 minutes + a CI re-run.
4. **(T-1, T-2) — HIGH** — uncomment SQLite test connection, add 8-12 Feature tests for the most critical paths (DRM token, AI client, payment webhook, feature flag percentage). ~2 days.
5. **(SEC-1, SEC-2) — MED** — fix or remove the broken URLs in `security.txt` and `SECURITY.md`. ~30 minutes.
6. Cleanup: remove `php-lint.yml`, fix `setup-php@master` pins, add CI test asserting every `admin_menu.php` route resolves. ~1 hour.
