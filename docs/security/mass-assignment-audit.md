# Mass Assignment Audit — `app/Models/`

**Date:** 2026-05-13
**Scope:** Every Eloquent model under `app/Models/`. For each, the
`$fillable` (or `$guarded`) list was inspected against the model's full
column set in `database/migrations/` and matched against every controller
write path (`Model::create(...)`, `->fill(...)`, `->update(...)`,
`updateOrCreate(...)`).
**Result:** 9 models tightened. 0 controllers used `$request->all()` or
`Model::create($request->all())` (clean baseline). 1 sensitive controller
(`PaymentController`) hardened to use `forceCreate` / `forceFill` for
webhook-driven writes after Subscription was switched to `$guarded`.

---

## Re-running the audit

```bash
# Primary scan — any controller bulk-binding from raw input.
grep -rnE '\$request->all\(\)|::create\(\$request->all|->fill\(\$request->all|->update\(\$request->all' app/

# Secondary scan — any other path that funnels untrusted arrays into Eloquent.
grep -rnE 'request\(\)->all\(\)|Input::all\(\)' app/

# Lightweight composer alias for CI:
composer audit:mass-assignment
```

A reviewer should also re-grep the seeders under `database/seeders/` —
those files write privileged columns (`is_admin`, `role`,
`email_verified_at`) and must use `forceFill(...)` after this audit.

---

## Convention used by this audit

- **Allowlist (`$fillable`)** — preferred for end-user-writable models
  (User, Movie, Comment, …). Privileged columns are simply omitted.
- **Denylist (`$guarded`)** — used for models where almost every column
  is system-set but a couple are user-driven (Subscription).
- **Lockdown (`$guarded = ['*']`)** — used for system-only sinks
  (AuditLog, AiUsageLog, LoginAttempt, Coin, EncodingJob, DrmSession,
  PlaybackConcurrentLock, ChurnPrediction). Internal callers write
  through `forceCreate(...)` / `forceFill(...)`.
- Privileged writes inside admin controllers/jobs/services use
  `forceFill(...)` even when the model is otherwise allowlisted —
  this both works and self-documents "yes, this field is intentional".

---

## Findings

### `app/Models/User.php` — TIGHTENED

| Field                       | Before | After | Why                                                              |
|-----------------------------|--------|-------|------------------------------------------------------------------|
| `name`                      | fillable | fillable | User-editable on profile.                                     |
| `email`                     | fillable | fillable | User-editable on profile.                                     |
| `username`                  | (missing) | fillable | Was set on register but never persisted — added to fix.    |
| `password`                  | fillable | fillable | Mutator auto-bcrypts; `password_changed_at` stamp side-effect. |
| **`is_admin`**              | **fillable** | **omitted** | **Privilege escalation vector. Set via `forceFill` in seeder + AdminController::toggleAdmin.** |
| **`role`**                  | **fillable** | **omitted** | **Same as above. Set via seeder forceFill / admin tooling.** |
| `email_verified_at`         | omitted | omitted (cast) | Set via Mailable callback or LoginController OAuth forceFill. |
| `provider_id`               | omitted | omitted | Set via LoginController OAuth callback forceFill.              |
| `two_factor_secret`         | omitted | omitted (cast) | Set via TwoFactorController forceFill only.                |
| `two_factor_recovery_codes` | omitted | omitted (cast) | Same.                                                       |
| `two_factor_confirmed_at`   | omitted | omitted (cast) | Same.                                                       |
| `password_changed_at`       | omitted | omitted | Set by `setPasswordAttribute` mutator.                          |
| `phone`, `address`, `birth_date` | omitted | omitted | PII; set via dedicated profile flows. |
| `remember_token`            | omitted | omitted | Set by Laravel auth. Factories use `forceFill`.                 |

Knock-on changes:
- `database/seeders/DatabaseSeeder.php` — `User::updateOrCreate(...)`
  rewritten to `firstOrNew + forceFill` so `is_admin` / `role` /
  `email_verified_at` still bootstrap.
- `app/Http/Controllers/LoginController.php` — Google OAuth callback
  `firstOrCreate` rewritten to manual lookup + forceFill so
  `provider_id` and OAuth-vouched `email_verified_at` actually persist.
- `database/factories/UserFactory.php` — moved `email_verified_at` and
  `remember_token` into an `afterMaking` `forceFill` so tests still
  produce verified users with remember tokens.

### `app/Models/Movie.php` — DOCUMENTED (no behaviour change)

Already excluded all dangerous fields (`encoding_status`,
`encoding_renditions`, `master_file_path`, `master_file_disk`,
`drm_strategy`, `drm_config`, `hls_manifest_path`, `dash_manifest_path`,
`cdn_disk`, `geo_allow`, `intro_*_seconds`, `outro_*_seconds`,
`recap_end_seconds`, `duration_seconds`, `ai_tags`, `ai_synopsis`,
`ai_short_summary`, `seo_meta`, `ai_*_generated_at`). Added a docblock
explaining the convention so future contributors don't re-add them.
All admin / pipeline writes already used `forceFill(...)`.

### `app/Models/Subscription.php` — TIGHTENED

| Field                | Before | After | Why                                                       |
|----------------------|--------|-------|-----------------------------------------------------------|
| `user_id`            | fillable | unguarded | OK — controller fills.                                |
| `subscription_plan_id` | fillable | unguarded | OK — controller fills.                              |
| `starts_at`, `ends_at`, `billing_address` | fillable | unguarded | OK — controller fills. |
| **`status`**         | **fillable** | **guarded** | **Webhook-only state. PaymentController uses forceCreate / forceFill.** |
| **`transaction_id`** | **fillable** | **guarded** | **Set by Midtrans webhook only.** |
| **`order_id`**       | **fillable** | **guarded** | **Server-built ID, never user input.** |
| **`payment_method`** | **fillable** | **guarded** | **Set by webhook based on Midtrans response.** |
| **`paid_at`**        | **fillable** | **guarded** | **Webhook-only timestamp.** |
| **`cancelled_at`**   | **fillable** | **guarded** | **Cancellation flow only.** |
| **`amount`**         | **fillable** | **guarded** | **Server-side `$plan->price` only — guarded so no overpay/underpay forge.** |

Knock-on: `app/Http/Controllers/PaymentController.php` — every Subscription
write (3 sites: checkout, webhook, free-plan activation) switched from
`Subscription::create(...)` / `->update(...)` to `Subscription::forceCreate(...)`
/ `->forceFill(...)->save()`. Server-trusted writes only.

### `app/Models/Coin.php` — TIGHTENED

`$fillable = ['user_id', 'amount', 'type', 'description']` →
`$guarded = ['*']`. The model is a write-only ledger with two static
helpers (`Coin::earn`, `Coin::spend`) — both rewritten to use
`forceCreate(...)`. Stops any future controller bug from accidentally
turning a request body into a coin grant.

### `app/Models/Achievement.php` — UNCHANGED

Schema does not contain `points_required` or `is_secret` (those fields
mentioned in the audit brief don't exist on this codebase — gamification
uses `coin_reward`, `xp_reward`, `condition_type`, `condition_value`,
`tier`). All current fillable entries are admin-CRUD safe; admin routes
are gated by `can:admin`. No change.

### `app/Models/AuditLog.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `AuditLogger::log()` and
`AuditLogger::security()` (the only callers) write via `forceCreate(...)`.
Linter also added an `is_security` flag for the security() entrypoint —
already lives behind the same `forceCreate` path.

### `app/Models/AiUsageLog.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `App\Services\Ai\UsageTracker`
is the sole writer; switched to `forceCreate(...)`.

### `app/Models/LoginAttempt.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `App\Services\Security\LoginThrottle::record`
switched to `forceCreate(...)`. Stops any path from forging an attempt
row that could mask a brute-force lockout.

### `app/Models/EncodingJob.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. Three write call sites updated:

- `App\Jobs\TranscodeMovie` — `EncodingJob::create(...)` → `forceCreate(...)`.
- `App\Http\Controllers\Admin\MovieUploadController::startTranscode` — same.
- `App\Services\Transcoding\TranscodingPipeline` —
  `$job->update(['rendition_specs' => ...])` →
  `$job->forceFill([...])->save()`.

The model's existing `markStarted` / `markCompleted` / `markFailed` /
`updateProgress` already used `forceFill`. No behavioural change there.

### `app/Models/DrmSession.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `App\Services\Drm\DrmKeyService::createSession`
already uses per-attribute assignment + `save()` (which bypasses guards),
so no caller change needed. Stops any future code from accidentally
mass-assigning a `session_token` / `expires_at`.

### `app/Models/PlaybackConcurrentLock.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `App\Services\Drm\ConcurrentStreamLimiter`
switched to `forceCreate(...)` for the new-lock path.

### `app/Models/ChurnPrediction.php` — TIGHTENED

`$fillable = [...]` → `$guarded = ['*']`. `App\Services\Ai\Tasks\ChurnPredictor::predictForUser`
rewritten from `updateOrCreate(...)` to `firstOrNew + forceFill->save()`
(same upsert semantics; bypasses the new guard).

### `app/Models/WatchHistory.php` — TIGHTENED

`$fillable` had `'completed'` — REMOVED. The flag is server-derived
(progress >= 90% gates an XP + coin grant). Letting a crafted AJAX
payload set `completed=true` directly farmed bonuses without playback.
`WatchHistoryController::updateProgress` switched to
`firstOrNew + forceFill->save()` so the server-trusted value still lands.

### Other models reviewed — UNCHANGED

| Model | Status | Notes |
|---|---|---|
| `Genre` | safe | Admin-only CRUD; no privileged columns. |
| `Cast` | safe | Admin-only CRUD; bio fields set via AI Tasks. |
| `Watchlist`, `Rating`, `Comment`, `WatchPartyMember`, `WatchParty` | safe | Engagement models — only user-controlled foreign keys + body text in `$fillable`. Comments' moderation/sentiment fields are NOT in `$fillable` (set via `forceFill` by AI tasks + admin moderation controller). |
| `MovieSchedule`, `QuizAttempt` | safe | User-owned engagement; `user_id` is in `$fillable` but always set to `auth()->id()` server-side. |
| `Notification` | safe | Server-emitted; no user-input route hits this model. |
| `Banner`, `SubscriptionPlan` | safe | Admin-only CRUD via gated routes. |
| `AiProvider` | documented | Admin-only CRUD via gated routes; counters (`last_used_at`, `total_tokens_used`, `total_cost_usd`) updated by UsageTracker with server-built payloads. |
| `MovieAsset`, `MovieSubtitle`, `MovieAiReview`, `MovieTrivia`, `MovieQuote`, `MovieTrailerSuggestion`, `MovieBehindScenes`, `MovieHighlightReel`, `MovieSceneActor`, `MovieCinematography`, `MovieQuizQuestion`, `DirectorAnalysis`, `YearInReview`, `UserRecommendation`, `MovieScheduleReminder` | safe | Backend AI / pipeline writers only; no end-user POST surface. UserRecommendation uses `insert()` which bypasses Eloquent entirely. |
| `UserPreference` | safe | Onboarding form; controller validates with `Rule::in(...)` allowlists. |
| `UserLevel` | safe | Server-computed XP / level; updated by `addXp()` not mass-assignment. |
| `KnownDevice` | safe | LoginAlertService writes; user can only flip `trusted` flag through a dedicated controller. |
| `AbExperiment`, `AbAssignment` | safe | Admin AB-test framework + per-user sticky bucket; controller uses explicit `validated()` arrays. |

---

## Controller scan — `Model::create($request->all())` etc.

All variants searched: `$request->all()`, `request()->all()`,
`->fill($request->all())`, `::create($request->all())`,
`->update($request->all())`. **Zero matches.** The codebase consistently
builds explicit arrays from `$request->validate(...)` / `validated()`
output, which is the safe pattern.

`$request->only(...)` is used in two places:

- `ProfileController::update` — `$user->update($request->only('name', 'email'))`
  — both fields are allowlisted, validated above (with `unique` rule on
  email), and the action is gated to the auth user. Safe.

`Comment::create([...])` in `CommentController::store` — explicit,
hardcoded array. Safe.

---

## CI hook

`composer.json` gains an `audit:mass-assignment` script that grep-scans
for the unsafe call shapes. Output is informational — it currently
prints `clean` on this audit. Wire it into the lint stage of
`.github/workflows/php-lint.yml` if/when you want a hard fail.

```bash
composer audit:mass-assignment
```

---

## Files touched by this audit

```
app/Models/User.php
app/Models/Subscription.php
app/Models/Coin.php
app/Models/AuditLog.php
app/Models/AiUsageLog.php
app/Models/LoginAttempt.php
app/Models/EncodingJob.php
app/Models/DrmSession.php
app/Models/PlaybackConcurrentLock.php
app/Models/ChurnPrediction.php
app/Models/WatchHistory.php
app/Models/Movie.php                   (docblock only)
app/Models/AiProvider.php              (docblock only)

database/seeders/DatabaseSeeder.php
database/factories/UserFactory.php

app/Http/Controllers/LoginController.php
app/Http/Controllers/PaymentController.php
app/Http/Controllers/WatchHistoryController.php
app/Http/Controllers/Admin/MovieUploadController.php

app/Services/Audit/AuditLogger.php
app/Services/Ai/UsageTracker.php
app/Services/Ai/Tasks/ChurnPredictor.php
app/Services/Security/LoginThrottle.php
app/Services/Drm/ConcurrentStreamLimiter.php
app/Services/Transcoding/TranscodingPipeline.php
app/Jobs/TranscodeMovie.php

composer.json                          (audit:mass-assignment script)
```

## Models reviewer could not determine "safe" state for

None. Every model under `app/Models/` was matched against its migration
column set and every Eloquent write call site grepped under `app/`. The
two name-collisions in the brief (`Movie.featured_until`, `Movie.created_by`,
`Achievement.points_required`, `Achievement.is_secret`,
`Subscription.payment_id`) don't correspond to columns that exist in
this schema; the underlying intent (don't expose admin-only fields) is
satisfied via the existing column allowlists documented above.
