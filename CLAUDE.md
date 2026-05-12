# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project identity (read this first)

The project has three names floating around — they all refer to the same app:
- **Brand**: "FLiK — Rumah Sinema Indonesia" (Indonesian streaming platform). Used in README, views, and `APP_NAME`.
- **Composer package**: `flik/flik`.
- **Code namespace / class names**: still use `Velflix` (e.g., `VelflixController`, `SearchVelflix` Livewire component, `velflix.index` route name, `resources/views/components/velflix-card.blade.php`). Working directory is also `velflix/`.

When adding code, follow the existing convention in the area you're editing — don't rename `Velflix*` symbols just for consistency. The `velflix` → `flik` rename has not been completed and a half-rename will break references.

## Stack & version reality

The README lists Laravel 9.x / PHP 8.0+. **That is stale.** Actual requirements (from `composer.json`):
- **PHP `^8.2`**
- **Laravel `^12.0`** (recently upgraded — see commits `2a9da15`, `ceeab7d`, `34305c2`)
- Livewire `^3.5`
- NativePHP Mobile `^3.0` (Android builds)

Note that the `bootstrap/app.php` still uses the **pre-Laravel 11 layout** (binding kernels manually). The framework was upgraded in-place but the minimal-app refactor was not done. `app/Http/Kernel.php`, `app/Console/Kernel.php`, and the classic provider files in `app/Providers/` are still authoritative.

The CI workflow (`.github/workflows/php-lint.yml`) pins PHP 8.1, which is **inconsistent with composer's 8.2 requirement**. Be aware that local installs will fail on PHP 8.1.

## Common commands

```bash
# Setup
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link

# Dev
php artisan serve            # PHP server on :8000
npm run dev                  # Vite dev server (HMR)
npm run build                # Production assets

# Tests
./vendor/bin/phpunit                                   # full suite
./vendor/bin/phpunit --filter=AuthenticationTest       # single test class
./vendor/bin/phpunit tests/Feature/AuthenticationTest.php
npx cypress open                                       # E2E (config: cypress.config.js)

# Lint & static analysis (same checks CI runs)
./vendor/bin/pint --test --preset laravel              # formatting check (drop --test to fix)
./vendor/bin/psalm
./vendor/bin/phpstan analyse

# Mobile (NativePHP Android)
./native <command>           # wraps `php artisan native:<command>`
```

**Default seeded accounts**: `admin@gmail.com` / `password` (admin) and `user@gmail.com` / `password`.

## Architecture

### Request flow
Single-file routing in `routes/web.php` — public routes, `auth` group, and an `auth + can:admin` group prefixed `/admin/` are all defined there. The `admin` Gate is registered in `app/Providers/AuthServiceProvider.php` as `(bool) $user->is_admin`. There is no `routes/api.php` usage worth speaking of; the project is server-rendered Blade + Livewire + Alpine, with a couple of `/api/*` POST endpoints (e.g. plot-explain, recommendations) declared inline in `routes/web.php`.

### Controllers & domains
- `VelflixController` — public catalog (`/movies`, `/movie/{id}`). Eager-loads genres/cast and shapes data into arrays for views.
- `AdminController` — single fat controller for the legacy admin CRUD (movies, genres, casts, users, banners, AI providers, pitch deck). Most new admin features live under `App\Http\Controllers\Admin\` instead (see below).
- **`App\Http\Controllers\Admin\*`** (swarm 20+) — one focused controller per dashboard:
  - `AiUsageController` (`/admin/ai-usage`), `AuditLogController`, `SentimentDashboardController`, `AiReviewController`, `CommentModerationController`, `MarketingAiController`, `SubtitleController`, `AiProviderTestController`.
- **User-facing AI controllers** (top-level `App\Http\Controllers`): `ChatController` (chatbot), `OnboardingController` (cold-start quiz), `MoodDiscoveryController`, `RecommendationController`, `PlotExplainController`, `MovieComparisonController`, `SmartWatchlistController`, `YearInReviewController`, `HealthController`.
- `PaymentController` — Midtrans Snap integration. The `isEnabled()` static gate (`config('services.midtrans.server_key')` non-empty) is the canonical check used across the app.
- Auth split into `LoginController` (Google OAuth via Socialite), `SessionsController` (email/password), `RegisterController`.
- Engagement: `WatchlistController`, `RatingController`, `CommentController`, `WatchHistoryController` (resume playback), `RewardsController` (gamification), `NotificationController`, `ProfileController`, `NewsletterController` (Mailchimp).

### Service layer
The bulk of post-Phase-0 logic lives under `app/Services/` organised by capability:

- **`App\Services\Ai\`** — multi-provider AI core
  - `AiClient` — provider abstraction (OpenAI-compatible chat completion + tool calling) used by every task.
  - `FilmKnowledgeService` — keyword-based RAG against the movie catalog + closest-match resolver (anti-hallucination link guard).
  - `WebSearchService` — Wikipedia + DuckDuckGo, no API key, 1h cache. Used as a tool for the chatbot and for grounding services like `DirectorAuteurAnalyzer` / `BehindScenesGenerator` / `TriviaGenerator`.
  - `UsageTracker` — every `AiClient` call writes to `ai_usage_logs` (provider, task, tokens, cost, success). Backs `/admin/ai-usage`.
  - `ProviderTester` — admin "Test connection" button per provider.
- **`App\Services\Ai\Tasks\*`** — 15+ task services, each a single-responsibility class consuming `AiClient` + (optionally) `FilmKnowledgeService` / `WebSearchService`. Examples: `MovieTagger`, `SynopsisGenerator`, `MovieSummaryGenerator`, `ThumbnailPicker`, `TrailerSuggester`, `QuoteExtractor`, `TriviaGenerator`, `MovieReviewer`, `CommentModerator`, `CommentSentimentAnalyzer`, `SeoMetaGenerator`, `PromoBannerGenerator`, `SocialMediaPostGenerator`, `EmailPersonalizer`, `DailyAdminReportGenerator`, `ColdStartRecommender`, `MoodDiscoveryService`, `PlotExplainer`, `MovieComparator`, `DirectorAuteurAnalyzer`, `SmartWatchlistPrioritizer`, `YearInReviewGenerator`, `SoundtrackAnalyzer`, `IntroOutroDetector`, `RecapDetector`, `HighlightReelGenerator`, `BehindScenesGenerator`, `CastBiographyEnricher`, `ChurnPredictor`.
- **`App\Services\Ai\Recommendations\RecommendationEngine`** — batch nightly per-user, Redis cache 6h, popularity fallback for cold-start.
- **`App\Services\Ai\Subtitle\*`** — `SubtitleGenerator` (gpt-4o-mini-transcribe), `SubtitleTranslator` (69 BCP-47 languages via `LanguageCatalog`), `ArabicHarakatService` (tashkeel), `WebVttHelper`, `DialectTranslator` (Jawa/Sunda/Batak/Minang → BI), `ProfanityFilter` (kid-safe).
- **`App\Services\Drm\*`** — DIY DRM stack: `DrmKeyService` (AES-128 generate/rotate), `DrmTokenService` (JWT issue/validate), `HlsEncryptor` (FFmpeg encrypt segments), `PlaybackManifestGenerator` (dynamic m3u8 + signed URLs), `DeviceFingerprinter`, `ConcurrentStreamLimiter` (Redis counter), `ForensicWatermarker`, `DrmAuditEvent`.
- **`App\Services\Transcoding\*`** — `TranscodingPipeline` orchestrator + `FfmpegTranscoder`, `AbrLadderBuilder` (360/480/720/1080p), `HlsSegmenter`, `MediaInfo` / `RenditionSpec` value objects.
- **`App\Services\Storage\*`** — `BunnyStorageService`, `S3StorageService`. Both implement `App\Contracts\Storage\CdnStorageContract`.
- **`App\Services\Geo\GeoIpResolver`** — MaxMind GeoLite2 lookup. Paired with `App\Http\Middleware\GeoBlock`.
- **`App\Services\Audit\AuditLogger`** — writes to `audit_logs` (action, subject_type/id, meta JSON, user_id, IP, UA). Backs `/admin/audit-logs`.

### Contracts (microservices prep)
`App\Contracts\Ai\*` (`AiClientContract`, `WebSearchContract`, `KnowledgeSourceContract`) and `App\Contracts\Storage\CdnStorageContract` are the interfaces concrete services bind against. They're the seam if/when we ever split workers into separate processes — bind a different implementation in the service provider.

### Schema & domain map
The schema spans 51 migrations under `database/migrations/`. Key clusters:

**Phase 0 baseline (`2026_03_07_*` + `2014/2019` Laravel default)**:
- **Catalog**: `Movie` ↔ `Genre` (`genre_movie` pivot), `Movie` ↔ `Cast` (`cast_movie` pivot with `character`, `order`).
- **Engagement**: `Watchlist`, `WatchHistory`, `Rating`, `Comment` (nested via `topLevel()` scope + `replies` relation).
- **Subscriptions/Payments**: `SubscriptionPlan`, `Subscription` (with `active()` scope and Midtrans payment columns).
- **Gamification**: `UserLevel` (XP/level), `Coin` (ledger; balance via `Coin::balanceFor($userId)`), `Achievement` + `user_achievements` pivot.
- **System**: `Banner`, `Notification`.

**Pre-swarm bonuses (`2026_05_09_*` + `2026_05_10_0000*`)**:
- `ai_providers` (encrypted API keys), `movie_assets` (multi-thumbnail rotation), `movies.slug` + `movies.slider_path`, `users.role`, `movie_subtitles` (multi-language WebVTT pointers).

**Swarm 20 (`2026_05_10_010*`)** — AI catalog + ops:
- `ai_usage_logs` (per-call audit), `audit_logs` (admin actions), `movie_ai_reviews` (4-perspective), `movie_trivia`, `movie_quotes`, `movie_trailer_suggestions`, `user_preferences` (cold-start quiz), `user_recommendations` (batch cache).
- Column adds: `movies.ai_tags` JSON, `movies.ai_synopsis`, `movies.ai_short_summary`, `movies.seo_meta` JSON, `comments.moderation_status` + `moderation_reason`, `comments.sentiment` + `sentiment_score`.

**Swarm 25 (`2026_05_10_020*`)** — Distribution + Wave 2/3 AI:
- Distribution: `movies` columns for `encoding_status`, `encoding_renditions`, `master_file_path/disk`, `drm_strategy`/`drm_config`, `hls/dash_manifest_path`, `cdn_disk`, `geo_allow`, `intro_*_seconds`, `outro_*_seconds`, `recap_end_seconds`, `duration_seconds`. New tables: `encoding_jobs`, `drm_sessions`, `playback_concurrent_locks`.
- AI features: `director_analyses` (one per director name), `year_in_reviews` (per user × year), `movie_highlight_reels`, `movie_behind_scenes` (6 sections per film), `movie_scene_actors` (X-Ray hotspots), `churn_predictions` (one snapshot per user via `updateOrCreate`). `casts` extended with `bio`, `wikipedia_url`, `birth_date`, `nationality`, `tmdb_id`, `bio_generated_at`.

`Movie::castMembers()` is the relation name (not `casts()` — that name conflicts with Eloquent's `$casts` property in Laravel 12; renamed in commit `34305c2`). Don't reintroduce `casts()`.

### Frontend
- Blade views in `resources/views/`, with shared layout components in `resources/views/components/`.
- Livewire 3 for interactivity (currently just `SearchVelflix` for live movie search).
- Alpine.js + Tailwind for the OTT-premium dark theme (gold `#C5A55A` on `#0a0a0a`).
- Vite entry points: `resources/css/app.css` and `resources/js/app.js` (see `vite.config.js`).
- Video.js powers playback today; Shaka Player is planned for the DRM/HLS swap once `PlaybackController` lands.
- PWA: `public/manifest.json` and `public/sw.js` (network-first service worker).
- Admin uses the shared `<x-admin.layout>` component (gold theme + Alpine + csrf-token meta + x-cloak). New admin views go under `resources/views/admin/<feature>/`.

### ENV-driven feature gating (important pattern)
Several features auto-disable when env vars are unset rather than hard-failing. **Preserve this pattern** when adding integrations:
- **Midtrans**: empty `MIDTRANS_SERVER_KEY` → checkout button shows "Coming Soon", webhook still mounted.
- **Storage backends**: `FILESYSTEM_DISK` selects between `public`/`s3`/`azure`/`alibaba`. `Movie::getVideoFullUrlAttribute()` branches on `video_disk` per row, and poster/backdrop accessors handle both URL strings and storage paths transparently.
- **Bunny CDN**: `config/services.php` reads `BUNNY_STORAGE_ZONE`/`BUNNY_STORAGE_KEY`/`BUNNY_PULL_ZONE_URL`/`BUNNY_TOKEN_KEY`/`BUNNY_STREAM_LIBRARY_ID`/`BUNNY_STREAM_API_KEY`. `BunnyStorageService` no-ops gracefully when keys are missing.
- **Google OAuth, TMDB, Mailchimp, Pusher**: similar — features degrade gracefully when keys are missing.
- **AI providers**: `AiClient` looks up the active default provider in `ai_providers`. Tasks log + return graceful fallbacks (empty array, default copy, "tidak tersedia" notices) rather than throwing.

Note: `config/filesystems.php` reads `FILESYSTEM_DRIVER`, but `.env.example` and the README document `FILESYSTEM_DISK`. When in doubt, set both.

### Public assets are tracked in git
Per commit `77c15a6`, `public/build/` is committed (it was removed from `.gitignore`). Don't re-add it to `.gitignore` without checking why — the previous fix was deliberate.

### Queue convention (microservices-ready)
`config/queue.php` defines four logical queues. Dispatch with `->onQueue('name')`; run workers with `--queue=name`:
- `default` — misc, low priority
- `transcoding` — heavy CPU (FFmpeg) — dedicated worker recommended (swarm-25 jobs: `TranscodeMovie`, `EncryptHlsSegments`, `UploadToBunny`)
- `ai-realtime` — low-latency AI (chat, on-demand subtitle, plot explain)
- `ai-batch` — bulk AI (nightly recommendations, weekly digest, swarm-20 batch jobs)

## Conventions worth knowing

- `User::setPasswordAttribute` auto-bcrypts — never call `bcrypt()` again before assigning.
- Ratings/comments/watchlist all assume the route-model-bound `auth()->user()` and won't be reachable unauthenticated (route group middleware).
- The `MASTER_PLAN.md`, `RANCANGAN.md`, and `PROGRESS_REPORT.md` files at the repo root are product/planning docs, not runtime config — safe to ignore for code work unless the user references them.

### AI task conventions
All services under `App\Services\Ai\Tasks\` follow the same shape — match it when adding a new feature:
- **DI** an `AiClient` (and optionally `FilmKnowledgeService` / `WebSearchService`) via constructor promotion.
- **Return a strict array** with a documented shape (see each class's docblock `@return`). Never return a raw string when callers will need structured fields.
- **Errors are logged via `Log::warning/error`, not thrown** — the view/controller layer expects a graceful fallback (e.g. `"tidak tersedia"` Indonesian notice, empty array, null). The chatbot is the one exception (it throws into a try/catch in `ChatController`).
- **Strict-JSON prompts** are standard: every task that wants structured output uses a system prompt that says "Output WAJIB strict JSON tanpa markdown fence" + a literal schema. Decode with `json_decode($content, true)` and validate keys before persisting.
- **Idempotency**: tasks that persist (e.g. `BehindScenesGenerator`, `CastBiographyEnricher`, `DirectorAuteurAnalyzer`) either stamp a `*_generated_at` column and skip when set, or `delete()` + bulk insert in a transaction. Don't write append-style.
- **Cost & audit**: every `AiClient::chat()` call automatically writes a row to `ai_usage_logs` with provider, model, task type, tokens, success — pass a meaningful `$taskType` (e.g. `"chat.plot_explain"`) so `/admin/ai-usage` can group by it.
- **AI feature gating**: every AI task lives behind the active provider in `/admin/ai-settings` — switching the default provider in admin re-routes all tasks. No hardcoded `OpenAI::` calls anywhere.

### Migration timestamp conventions
- `2026_03_07_*` — Phase 0 baseline schema.
- `2026_05_09_*` — `ai_providers` table.
- `2026_05_10_0000*` — pre-swarm bonus tracks (slug, role, multi-asset, subtitles).
- `2026_05_10_010***` — **swarm 20** (AI catalog enrichment + admin ops).
- `2026_05_10_020***` — **swarm 25** (Phase 1 distribution + Wave 2/3 AI).

Match the existing block when adding a new migration so the prefix communicates intent.

## Known feature highlights

Major dashboards & user-facing features now live in the app — keep this list in sync when adding more:

**User-facing**:
- `/` home (9anime-hub layout, hero slider, Top 10, quick filter, A-Z, hover preview cards)
- `/movies`, `/movie/{slug}` (catalog + detail)
- `/my-list` watchlist
- `/discover/mood` mood-driven discovery
- `/onboarding` 3-question cold-start quiz
- `/api/recommendations` personalized batch recommendations
- `/api/movies/{movie}/plot-explain` AI spoiler-laden ending explanation (10/hr/user)
- AI chatbot floating widget (RAG + agentic tool calling, scope-guarded)
- Plans page, Profile, Notifications, Rewards, Subscription checkout

**Admin panel** (`/admin/*`, gated by `can:admin`):
- Dashboard, CRUD: movies, genres, casts, users, banners
- `/admin/ai-settings` — multi-provider with Test Connection button
- `/admin/ai-usage` — spend/tokens/calls by provider+task, 14-day sparkline
- `/admin/audit-logs` — filter + CSV export (10K rows)
- `/admin/comments/queue` — moderation review (approve/reject/rerun)
- `/admin/sentiment` — per-film comment sentiment dashboard
- `/admin/movies/{movie}/subtitles` — generate / translate (69 langs) / set default
- `/admin/movies/{movie}/ai-reviews` — 4-perspective AI reviewer
- `/admin/movies/{movie}/marketing-ai/banner` + `/social` — generated promo copy
- `/admin/director-analyses` — cached auteur breakdowns
- `/admin/pitch-deck` — Markdown-rendered pitch deck

**Console** (`php artisan flik:*`):
- `flik:ai:tag-all-movies`, `flik:ai:generate-all-synopsis`, `flik:ai:generate-short-summary`, `flik:ai:pick-thumbnails`, `flik:ai:suggest-trailers`, `flik:ai:generate-trivia`, `flik:ai:extract-quotes`, `flik:ai:generate-seo`, `flik:ai:weekly-digest`
- `flik:report:daily` (AI narrative email to super_admins)
- `flik:recommendations:recompute` (batch nightly)
- `flik:transcode:movie`, `flik:geoip:update`

**Health/ops**:
- `/healthz`, `/healthz/ready`, `/healthz/detailed` for load balancer probes.
