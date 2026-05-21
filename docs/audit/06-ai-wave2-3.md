# Audit 06 — AI Wave 2/3 Features

**Date:** 2026-05-20
**Scope:** PlotExplainer (O8), MovieComparator (O11), DirectorAuteurAnalyzer (O12),
SoundtrackAnalyzer (O14), YearInReviewGenerator (I7), SmartWatchlistPrioritizer (I8),
FamilyMovieNightRecommender (N4), X-Ray actor overlay (J1), IntroOutroDetector +
RecapDetector + auto-skip (J2/J3), HighlightReelGenerator (J10), BehindScenesGenerator
(J11), ChurnPredictor (D4), ContentGapAnalyzer (D15), PricingOptimizer (D5),
CinematographyAnalyzer (O13), CustomerSupportReplyDrafter, TikTokClipSuggester,
TitleAlternativeGenerator, EmailSubjectAbTester.
**Mode:** Read-only.
**Verdict:** Mostly healthy. Two **HIGH** integration gaps (X-Ray table has no writer;
SoundtrackAnalyzer is dead code), one **HIGH** ops gap (heavy generators have no
scheduler — admin must run artisan manually), plus a handful of MEDIUM/LOW items
around double-throttling, duplicated mapMovie helpers, race-y rate limit hits,
and an unused `recap_end_seconds` data path.

---

## 1. Files inspected

| Layer | File | Status |
|---|---|---|
| Service (task) | `app/Services/Ai/Tasks/PlotExplainer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/MovieComparator.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/DirectorAuteurAnalyzer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/SoundtrackAnalyzer.php` | Orphaned |
| Service (task) | `app/Services/Ai/Tasks/YearInReviewGenerator.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/SmartWatchlistPrioritizer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/FamilyMovieNightRecommender.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/IntroOutroDetector.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/RecapDetector.php` | OK (consumed for episodes only) |
| Service (task) | `app/Services/Ai/Tasks/HighlightReelGenerator.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/BehindScenesGenerator.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/ChurnPredictor.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/ContentGapAnalyzer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/PricingOptimizer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/CinematographyAnalyzer.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/CustomerSupportReplyDrafter.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/TikTokClipSuggester.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/TitleAlternativeGenerator.php` | OK |
| Service (task) | `app/Services/Ai/Tasks/EmailSubjectAbTester.php` | OK |
| Service (search) | `app/Services/Ai/Search/ImageSearchService.php` | OK |
| Service (search) | `app/Services/Ai/Search/DecadeStyleSearchService.php` | OK |
| Service (search) | `app/Services/Ai/Search/DirectorActorSearchService.php` | OK |
| Service (search) | `app/Services/Ai/Search/IntentClassifier.php` | OK |
| Controller (user) | `app/Http/Controllers/PlotExplainController.php` | OK |
| Controller (user) | `app/Http/Controllers/MovieComparisonController.php` | OK |
| Controller (user) | `app/Http/Controllers/YearInReviewController.php` | OK |
| Controller (user) | `app/Http/Controllers/SmartWatchlistController.php` | OK |
| Controller (user) | `app/Http/Controllers/FamilyNightController.php` | OK |
| Controller (user) | `app/Http/Controllers/HighlightReelController.php` | OK |
| Controller (user) | `app/Http/Controllers/XrayController.php` | OK (reader only) |
| Controller (user) | `app/Http/Controllers/AdvancedSearchController.php` | OK |
| Controller (admin) | `app/Http/Controllers/Admin/DirectorAnalysisController.php` | OK |
| Controller (admin) | `app/Http/Controllers/Admin/ChurnDashboardController.php` | OK |
| Controller (admin) | `app/Http/Controllers/Admin/AiInsightsController.php` | OK |
| Controller (admin) | `app/Http/Controllers/Admin/MarketingOpsController.php` | OK |
| Job | `app/Jobs/GenerateBehindScenes.php` | OK |
| Job | `app/Jobs/GenerateHighlightReel.php` | OK |
| Job | `app/Jobs/AnalyzeCinematography.php` | OK |
| Job | `app/Jobs/DetectIntroOutro.php` | OK |
| Console | `app/Console/Commands/DetectIntroOutroAll.php` | OK (scheduled weekly) |
| Console | `app/Console/Commands/AiGenerateBts.php` | OK (manual) |
| Console | `app/Console/Commands/AiGenerateHighlights.php` | OK (manual) |
| Console | `app/Console/Commands/AiCinematography.php` | OK (manual) |
| Console | `app/Console/Commands/PredictChurn.php` | OK (scheduled daily 03:00) |
| Console | `app/Console/Kernel.php` (schedule) | OK |
| Model | `app/Models/ChurnPrediction.php` | OK |
| Model | `app/Models/DirectorAnalysis.php` | OK |
| Model | `app/Models/MovieBehindScenes.php` | OK |
| Model | `app/Models/MovieHighlightReel.php` | OK |
| Model | `app/Models/MovieSceneActor.php` | OK (no writer — see F-1) |
| Model | `app/Models/YearInReview.php` | OK |
| Model | `app/Models/MovieCinematography.php` | OK |
| View (user) | `resources/views/components/movies/plot-explain-modal.blade.php` | OK |
| View (user) | `resources/views/components/movies/show.blade.php` (mount site) | OK |
| View (user) | `resources/views/components/movies/highlight-reel.blade.php` | OK |
| View (user) | `resources/views/components/movies/behind-scenes.blade.php` | OK |
| View (user) | `resources/views/components/movies/cinematography.blade.php` | OK |
| View (user) | `resources/views/components/movies/ai-reviews-tabs.blade.php` | OK |
| View (user) | `resources/views/comparison/{form,result}.blade.php` | OK |
| View (user) | `resources/views/family-night/{form,result,partials/form}.blade.php` | OK |
| View (user) | `resources/views/year-in-review/show.blade.php` | OK |
| View (user) | `resources/views/watchlist/smart.blade.php` | OK |
| View (user) | `resources/views/search/{image,vibe,person,smart}.blade.php` | OK |
| View (admin) | `resources/views/admin/director-analyses/{index,show}.blade.php` | OK |
| View (admin) | `resources/views/admin/churn/dashboard.blade.php` | OK |
| View (admin) | `resources/views/admin/insights/{content-gap,pricing}.blade.php` | OK |
| View (admin) | `resources/views/admin/marketing-ops/{tiktok-clips,title-alternatives,email-subjects,cs-reply-drafter}.blade.php` | OK |
| Player JS | `resources/js/player/xray-overlay.js` | OK |
| Player JS | `resources/js/player/auto-skip.js` | OK |
| Player JS | `resources/js/app.js` (mounts xray + auto-skip globals) | OK |
| Routes | `routes/web.php` (lines 446–448, 762–826, 1049–1101) | OK |
| Routes | `routes/api.php` (X-Ray endpoint, line 24) | OK |
| Sidebar config | `config/admin_menu.php` | Partial — see F-7 |
| Header nav | `resources/views/components/header.blade.php` (lines 43–67, 97, 260–300) | OK |

---

## 2. Critical-checks scorecard

| Check | Status | Evidence |
|---|---|---|
| PlotExplainer: Service + Controller + View + Route | YES | `PlotExplainer` (179 LOC) → `PlotExplainController::explain` (10/hr/user) → `<x-movies.plot-explain-modal>` → route `movies.plot-explain` (`web.php:446`). |
| Plot explain modal mounted on movie detail | YES | `resources/views/components/movies/show.blade.php:213` mounts `<x-movies.plot-explain-modal :movie="$movieModel" />` inside the action-button row. |
| MovieComparator: full stack | YES | `MovieComparator` → `MovieComparisonController::{form,compare,compareApi}` → `comparison/{form,result}.blade.php` → routes `compare.form`, `compare.run`, `compare.api` (`web.php:1049-1055`). |
| Compare entry on detail page | YES | `show.blade.php:216` "Bandingkan Film Ini" → `compare.form?movie_a={id}` (pre-fills slot A). |
| DirectorAuteurAnalyzer: admin dashboard reachable | YES | `Admin\DirectorAnalysisController` (index/show/analyze/refresh/destroy) registered at `web.php:763-772`; sidebar entry "Director Analyses" in `config/admin_menu.php:243-249` under Intelligence. |
| YearInReviewGenerator: reachable from header | YES | `header.blade.php:47` (desktop) + `:288` (mobile) → `year-in-review.show`. |
| Year-in-review generated on first visit | YES | `YearInReviewController::show()` calls `generator->generate()` then `updateOrCreate` (race-safe). No scheduled batch — purely lazy. |
| Family Night form wired | YES | Routes `family-night.form` (GET) + `family-night.recommend` (POST) (`web.php:1068-1071`); view `family-night/form.blade.php` posts via `family-night/partials/form.blade.php:12`. Header link `:43`. |
| SmartWatchlistPrioritizer: surfaced to user | YES | `/watchlist/smart` controller renders `watchlist/smart.blade.php`, result cached 1h keyed on `Watchlist::max(updated_at) + count`. Header link `:97`. |
| X-Ray overlay actually appears on player | PARTIAL | `xray-overlay.js` is imported in `resources/js/app.js:14` and instantiated in `show.blade.php:67-72` against the Shaka player. **But:** `MovieSceneActor` has zero writers — see F-1. |
| Auto-skip intro/outro/recap on player | YES | `auto-skip.js` instantiated `show.blade.php:61-66`; `movies.intro_start_seconds` / `intro_end_seconds` / `outro_start_seconds` / `recap_end_seconds` rendered as `data-*` attributes (`:42-45`). Scheduled weekly (`Kernel.php:43`). |
| Recap auto-skip data ever populated | PARTIAL | `RecapDetector` only fires for titles matching `Episode` / `S\d+E\d+` — i.e. it's series-only. `DetectIntroOutro` job dispatches it, but the data attribute (`recap_end_seconds`) ships to every movie's player template. Non-series movies always get a null marker (harmless, just always-disabled). |
| Highlight reel actually plays | YES | `HighlightReelController::show` resolves the latest `ready` reel, falls back to friendly empty-state. `highlight-reel.blade.php:32-42` mounts a Video.js player with the reel URL. CTA card on detail (`show.blade.php:300-344`) only renders when at least one `status='ready'` reel exists. |
| BehindScenesGenerator: rendered on detail | YES | `show.blade.php:294` → `<x-movies.behind-scenes :sections="$movieModel->behindScenes" />`. Component self-hides on empty collection. |
| Cinematography component rendered on detail | YES | `show.blade.php:297` → `<x-movies.cinematography :data="$movieModel->cinematography" />`. Self-hides when neither analysis nor keyframes exist. |
| ChurnPredictor: dashboard accurate | YES | `Admin\ChurnDashboardController` reads-only from `churn_predictions`; predictor runs daily 03:00 (`Kernel.php:29`). Stats are direct SUM/AVG queries — no caching layer to drift. CSV export caps at 5000 rows. Sidebar entry under Analytics. |
| ContentGapAnalyzer + PricingOptimizer surfaced | YES | `Admin\AiInsightsController::{contentGap,pricing}` (`web.php:779-782`), cached 24h, `?refresh=1` bypass. Sidebar entries in Marketing section (`admin_menu.php:258-268`). |
| CustomerSupportReplyDrafter / TikTokClipSuggester / TitleAlternativeGenerator / EmailSubjectAbTester: admin UI | YES | All four wired in `MarketingOpsController`; per-movie actions also linked from `admin/movies/index.blade.php:296-305` row dropdown. Sidebar surfaces "Email A/B Subjects" + "CS Reply Drafter" (`admin_menu.php:269-298`). |
| AdvancedSearch (image/vibe/person) reachable | YES | Routes `search.image.form/image`, `search.vibe.form/vibe`, `search.person.form/person` (`web.php:1090-1101`), header items `:58-67` (desktop) + `:294-300` (mobile). |
| SoundtrackAnalyzer wired anywhere | **NO** | Grep across `app/` finds zero callers. Service is ~250 LOC of dead code. See F-2. |
| X-Ray table populated by anything | **NO** | `MovieSceneActor` has no writer (no seeder, no job, no admin tool). Migration's own docblock admits "data structure for either manual annotation or future ML-based population". See F-1. |
| AI tasks degrade gracefully when provider missing | YES | Spot-checked: `MovieComparator::fallbackPayload()` returns Indonesian "tidak tersedia" strings; `ChurnPredictor::fallbackAction()` returns deterministic templates; `YearInReviewGenerator::fallbackNarrative()` exists; `SmartWatchlistPrioritizer` / `FamilyMovieNightRecommender` aiRerank failures fall back to local score. PlotExplainer is the exception — it throws into ControllerAware try/catch (returns 503 + Indonesian error). |

---

## 3. Architecture map

```
                                  USER-FACING (web group + auth)
┌───────────────────────────────────────────────────────────────────────────────┐
│                                                                               │
│  POST /api/movies/{movie}/plot-explain                                        │
│  PlotExplainController ──► PlotExplainer ──► AiClient + FilmKnowledgeService  │
│  (route: throttle:ai-batch outer + RateLimiter 10/hr inner) ──► JSON          │
│                                                                               │
│  GET  /compare          MovieComparisonController::form     ──► comparison/   │
│  POST /compare          MovieComparisonController::compare  ──► MovieComp..   │
│  POST /api/compare      MovieComparisonController::compareApi  (throttle:ai-batch) │
│                                                                               │
│  GET  /year-in-review[/{year}]                                                │
│  GET  /year-in-review/{year}                                                  │
│  POST /year-in-review/{id}/share                                              │
│  YearInReviewController::show ──► YearInReviewGenerator (lazy)                │
│                                  │                                            │
│                                  ├── computeStats() (1 batched query)         │
│                                  └── writeNarrative() ──► AiClient            │
│                                  → year_in_reviews (updateOrCreate)           │
│                                                                               │
│  GET  /watchlist/smart                                                        │
│  SmartWatchlistController::prioritized ──► SmartWatchlistPrioritizer          │
│                                            (Cache::remember 1h, sig key)      │
│                                                                               │
│  GET  /family-night                                                           │
│  POST /family-night     (throttle:ai-batch + in-ctrl 10/min)                  │
│  FamilyNightController::recommend ──► FamilyMovieNightRecommender             │
│                                                                               │
│  GET  /movie/{movie}/highlight              HighlightReelController::show     │
│  GET  /movie/{movie}/highlight/download     HighlightReelController::download │
│  ──► MovieHighlightReel (latest 'ready'); .mp4 streamed from storage disk     │
│                                                                               │
│  GET  /api/xray/{movie}?t={sec}     (routes/api.php, middleware:web)          │
│  XrayController::forMovie ──► MovieSceneActor::activeAt($sec)                 │
│  └── consumed by resources/js/player/xray-overlay.js (5s poll loop)           │
│      mounted in resources/views/components/movies/show.blade.php:67-72        │
│      *** READER ONLY — NO WRITER FOR movie_scene_actors ***                   │
│                                                                               │
│  GET  /search/{image,vibe,person} + POST counterparts (throttle:ai-batch)     │
│  AdvancedSearchController ──► Search\{Image,DecadeStyle,DirectorActor}Service │
│                                                                               │
│  Player overlay layer (always-on; degrades silently with null markers):       │
│  resources/views/components/movies/show.blade.php:47-101                      │
│  ├── FlikPlayer.initialize  (Shaka)                                           │
│  ├── initAutoSkip { intro/outro/recap data-* on the <video>}                  │
│  └── initXrayOverlay { movieSlug, polls /api/xray/{slug}?t=… }                │
│                                                                               │
└───────────────────────────────────────────────────────────────────────────────┘

                                  ADMIN (web + auth + can:admin)
┌───────────────────────────────────────────────────────────────────────────────┐
│                                                                               │
│  /admin/director-analyses[*]            Admin\DirectorAnalysisController      │
│   ──► DirectorAuteurAnalyzer                                                  │
│                                                                               │
│  /admin/churn                           Admin\ChurnDashboardController        │
│   reader of churn_predictions (written by ChurnPredictor; CLI: flik:churn:    │
│   predict daily 03:00)                                                        │
│                                                                               │
│  /admin/insights/content-gap            Admin\AiInsightsController::contentGap│
│  /admin/insights/pricing                Admin\AiInsightsController::pricing   │
│   ──► ContentGapAnalyzer / PricingOptimizer (Cache::remember 24h, ?refresh=1) │
│                                                                               │
│  /admin/movies/{movie}/marketing-ops/{tiktok-clips,title-alternatives}        │
│  /admin/marketing-ops/{email-subjects,cs-reply}                               │
│   Admin\MarketingOpsController ──►                                            │
│     TikTokClipSuggester / TitleAlternativeGenerator /                         │
│     EmailSubjectAbTester / CustomerSupportReplyDrafter                        │
│                                                                               │
└───────────────────────────────────────────────────────────────────────────────┘

                                  BATCH / CLI
┌───────────────────────────────────────────────────────────────────────────────┐
│                                                                               │
│  Kernel.php:29  flik:churn:predict           daily 03:00  (scheduled)         │
│  Kernel.php:43  flik:detect:intro-outro      weekly Sat 03:00 (--limit=50)    │
│                                                                               │
│  Manual-only (no schedule entry):                                             │
│    flik:ai:bts          → GenerateBehindScenes job → BehindScenesGenerator    │
│    flik:ai:highlights   → GenerateHighlightReel job → HighlightReelGenerator  │
│    flik:ai:cinematography → AnalyzeCinematography job → CinematographyAnalyzer│
│                                                                               │
└───────────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Findings

### F-1 — HIGH — X-Ray overlay table has no writer; player polls produce empty hotspots forever

**Files**: `app/Models/MovieSceneActor.php`, `app/Http/Controllers/XrayController.php`,
`database/migrations/2026_05_10_020015_create_movie_scene_actors_table.php`,
`resources/js/player/xray-overlay.js`, `resources/views/components/movies/show.blade.php`

`MovieSceneActor` is the storage for the X-Ray overlay's "who's on screen now?" hotspots.
The reader path is complete and wired:

- `routes/api.php:24` registers `GET /api/xray/{movie}?t={sec}`.
- `XrayController::forMovie` looks up presences via `MovieSceneActor::activeAt($sec)`.
- `app.js:14,18` imports and exposes `initXrayOverlay` globally.
- `show.blade.php:67-72` instantiates the overlay against the Shaka player.
- The JS polls every 5 s with the current `t` value and renders clickable bio modals.

**But grep across `app/` for any writer to `movie_scene_actors` returns exactly one hit —
the controller's `SELECT`:**

```
$ grep -rn "MovieSceneActor::\|movie_scene_actors\|new MovieSceneActor" app/
app/Http/Controllers/XrayController.php:37:        $presences = MovieSceneActor::query()
```

No seeder, no job, no admin tool, no AI task ever inserts a row. The migration's docblock
acknowledges this (`migrations/...020015...php:14-17`):

> NOTE: Actual face recognition is NOT implemented. This table is the data structure for
> either manual annotation (admin tool) or future ML-based population (e.g. TMDB scene
> credits + face detection pipeline).

**Impact**: Every authenticated playback session emits an X-Ray HTTP request every 5
seconds for the entire runtime of every film, always returning `{"actors":[]}`. For a
2-hour movie that's ~1,440 zero-result requests per session, multiplied by every active
viewer. Network/DB overhead is real; UX delivers nothing.

**Recommended actions** (read-only audit — do not apply):
- Either gate `initXrayOverlay` mounting on `Movie::sceneActors()->exists()` so the poll
  only fires for movies that actually have annotations, **or**
- Ship a no-data short-circuit on the JS side (one empty response → back off to a 30 s
  poll, two in a row → stop polling entirely for the session), **and**
- Stand up either a seeder fixture or a `php artisan flik:xray:annotate {movie}` CLI so
  the feature has at least one populated movie before launch.

---

### F-2 — HIGH — `SoundtrackAnalyzer` is dead code

**File**: `app/Services/Ai/Tasks/SoundtrackAnalyzer.php`

The task is fully implemented — strict-JSON prompt, `formatForAi` integration, fallback
shape, ~250 LOC. Grep across the entire codebase finds zero instantiators:

```
$ grep -rn "SoundtrackAnalyzer" app/ resources/ routes/
app/Services/Ai/Tasks/SoundtrackAnalyzer.php  (definition only)
```

No controller, no admin dashboard, no chatbot tool registration, no console command
imports it. Other Wave 2 tasks of similar weight (e.g. `MovieComparator`, `PlotExplainer`)
all have a controller + view + route triplet — this one doesn't.

`PROGRESS_REPORT.md` and `RANCANGAN.md` reference it as "shipped (O14)" but the runtime
graph disagrees.

**Impact**: Confusing capability list (admins see no way to surface it), maintenance
liability (cost-tracking docblock claims it will write `ai_usage_logs` but it will never
fire), and a misleading entry in the codebase tour.

**Recommended actions**:
- Either wire it: a `SoundtrackController` + view on the movie detail (sibling to
  CinematographyAnalyzer's component) would be the lowest-friction integration —
  results aren't persisted today, so a `Cache::remember(..., 24h)` per-movie inside
  the controller would keep cost flat. **Or**
- Delete the file and remove the entry from `CLAUDE.md`'s "Tasks" list.

---

### F-3 — HIGH — Heavy AI/FFmpeg generators have no scheduler

**Files**: `app/Console/Kernel.php:25-117`, `app/Console/Commands/AiGenerateBts.php`,
`app/Console/Commands/AiGenerateHighlights.php`, `app/Console/Commands/AiCinematography.php`

Three Wave 2/3 generators that each persist a long-lived asset (`movie_behind_scenes`,
`movie_highlight_reels.reel_path`, `movie_cinematography`) have console commands but
**no scheduled entry in `Kernel.php`**. The scheduler only runs:

- `flik:recommendations:recompute` (daily 02:30)
- `flik:churn:predict` (daily 03:00)
- `flik:report:daily` (daily 07:00)
- `flik:ai:weekly-digest` (Monday 06:00)
- `flik:detect:intro-outro --queue --limit=50` (Saturday 03:00)
- `flik:ai:tag-all` (Saturday 01:00)
- `flik:cast:enrich-bios --limit=20` (Saturday 02:00)

Missing: `flik:ai:bts`, `flik:ai:highlights`, `flik:ai:cinematography`. Existing
movies on the catalog will never get behind-the-scenes copy, a highlight reel, or a
cinematography analysis unless an operator manually shells in and runs the command for
each title.

The detail page handles the empty-data case gracefully (the Blade components
self-hide), so users see no breakage — but the headline Wave 2 features are
effectively dark for the entire catalog.

**Compounding factor**: `BehindScenesGenerator` and `HighlightReelGenerator` are
expensive (web search + AI for BTS; FFmpeg re-encode for highlights), so a full-
catalog backfill needs throttling. The pattern from `flik:detect:intro-outro
--queue --limit=50` weekly is the right shape — apply the same to all three.

**Recommended actions**:
- Add Saturday-night schedule entries for `flik:ai:bts --queue --limit=20`,
  `flik:ai:highlights --queue --limit=10`, `flik:ai:cinematography --queue --limit=20`
  (or similar caps tuned to provider budget + FFmpeg throughput).
- Consider an admin-triggered "Generate AI assets for this movie" bulk action on the
  movie row to fill the gap for hot titles that shouldn't wait for the weekly batch.

---

### F-4 — MEDIUM — Plot-explain endpoint is double-throttled (route limiter + in-controller)

**Files**: `routes/web.php:447`, `app/Http/Controllers/PlotExplainController.php:42-52`

The route declaration applies `throttle:ai-batch` (the named limiter, hourly per user)
**and** the controller runs its own `RateLimiter::tooManyAttempts($key, 10)` with a
3600 s window. Same pattern flagged in audit 07 for `MoodDiscoveryController` and
`FamilyNightController`.

```php
// routes/web.php:446-448
Route::post('/api/movies/{movie}/plot-explain', [..., 'explain'])
    ->middleware(['auth', 'throttle:ai-batch'])
    ->name('movies.plot-explain');

// PlotExplainController.php:42-52
$rateKey = 'plot-explain:' . ($userId ?: $request->ip());
if (RateLimiter::tooManyAttempts($rateKey, 10)) { ... 429 ... }
RateLimiter::hit($rateKey, 3600);
```

The route comment above the declaration even acknowledges the duplication and calls
it "defence-in-depth", but the inner cap (10/hr) is **tighter** than the named
`ai-batch` cap, so the outer limiter never actually fires for plot-explain. If the
platform ever raises or lowers the per-feature cap, two places need to be edited.

**Same pattern** found in `FamilyNightController:66-72` (`throttle:ai-batch` route +
10/min in-controller). Per-feature inner caps are reasonable when they enforce a
different ratio than the global one, but the magic number lives outside the central
limiter config which makes capacity tuning hard.

**Recommended actions**: either remove the inner `RateLimiter::hit` calls and define a
dedicated `plot-explain` limiter in `RouteServiceProvider`, or document the layered
strategy in one place. Don't keep both copies of the cap drifting independently.

---

### F-5 — MEDIUM — `RateLimiter::hit()` always runs even when the AI call later fails

**Files**: `app/Http/Controllers/PlotExplainController.php:52`,
`app/Http/Controllers/FamilyNightController.php:66-72`

```php
// PlotExplainController
if (RateLimiter::tooManyAttempts($rateKey, 10)) { return 429; }
RateLimiter::hit($rateKey, 3600);             // ← unconditional
try {
    $result = $explainer->explain(...);       // ← may throw RuntimeException (AI down)
} catch (\RuntimeException $e) {
    return 503;                                 // ← rate budget already consumed
}
```

If the AI provider is misconfigured, the user burns one of their 10 hourly attempts
on every 503. The same shape exists in `FamilyNightController` for the family-night
recommendation flow.

**Recommended actions**: move `RateLimiter::hit` after the successful AI call (or use
`RateLimiter::attempt()` with a closure that throws on AI failure to avoid the
increment). Low-impact for cost (failed AI calls are still cheap), but a real UX
papercut when the AI is misconfigured — users get locked out of a feature that never
worked for them.

---

### F-6 — MEDIUM — `recap_end_seconds` always shipped to player, but population is series-only

**Files**: `app/Services/Ai/Tasks/RecapDetector.php:14-30`,
`resources/views/components/movies/show.blade.php:45`,
`resources/js/player/auto-skip.js`

`RecapDetector` (correctly) early-exits for anything that doesn't smell like an
episode — title must contain "Episode" or match `S\d+E\d+`. But the player template
unconditionally emits `data-recap-end="{{ $movieModel->recap_end_seconds }}"` on
every movie:

```html
<video ...
       data-intro-start="{{ $movieModel->intro_start_seconds }}"
       data-intro-end="{{ $movieModel->intro_end_seconds }}"
       data-outro-start="{{ $movieModel->outro_start_seconds }}"
       data-recap-end="{{ $movieModel->recap_end_seconds }}"
       data-movie-slug="{{ $movieModel->slug }}">
```

For standalone movies, `recap_end_seconds` will always be NULL (Blade renders empty
attribute), so `auto-skip.js` correctly disables the skip-recap button. No bug —
but it does mean every movie's player ships a permanently inert recap marker.

Bigger concern: the FLiK catalog also has a proper `episodes` table now (per CLAUDE.md
"swarm 25"), with a different watch flow (`episodes.watch` route). Confirmed via
`EpisodeWatchController` exists. If the per-episode player uses a different template,
`RecapDetector`'s output might be writing `recap_end_seconds` to a movie row that's
never actually used in the episode player path.

**Recommended action**: verify whether `EpisodeWatchController` uses the same
`<x-movies.show>` component or a dedicated `episodes/watch.blade.php` — if the latter,
confirm it surfaces the recap marker (probably needs `episodes.recap_end_seconds` not
`movies.recap_end_seconds`). Out-of-scope for this audit but worth flagging.

---

### F-7 — MEDIUM — Sidebar exposes 4 of the 5 marketing-ops dashboards (TikTok Clips + Title Alternatives only reachable via per-movie row)

**File**: `config/admin_menu.php:253-318`

The Marketing section lists:

- ✓ Content Gap Analysis (`admin.insights.content-gap`)
- ✓ Pricing Optimization (`admin.insights.pricing`)
- ✓ Email A/B Subjects (`admin.marketing-ops.email-subjects`)
- ✓ Email Campaigns
- ✓ Push Broadcasts / Push Notifications
- ✓ CS Reply Drafter (`admin.marketing-ops.cs-reply`)
- ✓ Promo Codes

**Missing**: TikTok Clip Suggester and Title Alternative Generator. Both are
per-movie (route requires `{movie}`) so a sidebar-as-launcher entry doesn't make
sense, but they're also not surfaced as a top-level "pick a movie" intermediate page —
the only way to reach them is by navigating to `/admin/movies` and using the row
dropdown (`admin/movies/index.blade.php:296-305`).

For consistency with how AI Reviews and Subtitles per-movie features work (also
reached via the movie row), this is fine — but worth documenting in `admin_menu.php`'s
header comment so future maintainers don't add a broken sidebar link expecting a
landing page that doesn't exist.

**Recommended action**: leave the sidebar as is, but add a comment to
`config/admin_menu.php` listing the "per-movie marketing ops" features and where they
live in the UI (movie row dropdown), so the discovery contract is explicit.

---

### F-8 — MEDIUM — `mapMovie()` is duplicated across 4 controllers verbatim

**Files**: `app/Http/Controllers/VelflixController.php`,
`app/Http/Controllers/SmartWatchlistController.php:56-72`,
`app/Http/Controllers/FamilyNightController.php:127-143`,
`app/Http/Controllers/AdvancedSearchController.php:224-240`

Each controller that needs to shape a `Movie` model into the array format `<x-movies>`
expects copies the same 13-line helper. The four copies are byte-identical (no
controller adds extra fields, none deviate). If `<x-movies>` ever adds a new required
field (e.g. `runtime_minutes`, `is_premium`), four files need a synchronised update.

The `SmartWatchlistController` copy even acknowledges it (`// Mirrors VelflixController::mapMovie()`).

**Recommended action**: extract into `Movie::toCardArray()` (instance method) or
`App\Support\MovieCardPresenter::for($movie)`. Out of scope for this audit but worth
flagging for the next refactor pass.

---

### F-9 — LOW — `PlotExplainer` throws on AI failure; every other Wave 2 task degrades silently

**Files**: `app/Services/Ai/Tasks/PlotExplainer.php:62-87`,
`app/Services/Ai/Tasks/MovieComparator.php:99-117`,
`app/Services/Ai/Tasks/SmartWatchlistPrioritizer.php` (aiRerank null-on-fail pattern)

Per the AI task conventions documented in CLAUDE.md:

> Errors are logged via `Log::warning/error`, not thrown — the view/controller layer
> expects a graceful fallback (e.g. `"tidak tersedia"` Indonesian notice, empty array,
> null). The chatbot is the one exception (it throws into a try/catch in ChatController).

`PlotExplainer::explain()` follows the chatbot's "throw into a try/catch" pattern, not
the Wave 2 fallback pattern. The throw point (line 69) re-raises a `RuntimeException`
wrapping the underlying error; `PlotExplainController` catches it and returns 503.

Not a bug — both shapes are explicitly allowed by the contract — but worth noting that
PlotExplainer is the **only** Wave 2 task with `@throws \RuntimeException` in its
docblock, which makes the convention slightly inconsistent. If the convention is
"throw on hard failure" vs "fallback on hard failure", neither is wrong, but a
maintainer copy-pasting PlotExplainer to make a new task will end up with the
throws-shape and may forget to add a controller-level try/catch.

**Recommended action**: either bring PlotExplainer in line (return `null|array` with a
fallback explanation string) or document the chatbot-vs-everything-else split more
prominently than the one-liner in CLAUDE.md.

---

### F-10 — LOW — `YearInReviewGenerator::generate()` runs synchronously on first visit; can take 10+ seconds

**Files**: `app/Http/Controllers/YearInReviewController.php:32-48`,
`app/Services/Ai/Tasks/YearInReviewGenerator.php`

`YearInReviewController::show()`:

```php
$review = YearInReview::where('user_id', $user->id)->where('year', $year)->first();
if (!$review) {
    $payload = $this->generator->generate($user, $year);   // ← sync AI call
    $review = YearInReview::updateOrCreate(...);
}
```

First-visit latency = (stats SQL: 1 query + N×Movie::find batch) + 1 AI call for the
narrative. The AI prompt asks for ~250 words of warm celebratory copy — typically
6–12 s on DeepSeek V4. The HTTP request blocks the browser for that entire time,
without a loading skeleton.

For most users this only happens once per year (the second visit hits the
`updateOrCreate` short-circuit), but a power user clicking through several past
years (`/year-in-review/2024`, `/year-in-review/2023`) will eat a fresh AI call per
year visited.

**Recommended action**: dispatch a job + show a "Building your Year in Review…"
intermediate state with auto-refresh, or render a skeleton + fetch via
`fetch()` on the same page. Cosmetic, not blocking.

---

### F-11 — LOW — Cinematography component renders a "copy hex" widget even though `copyHex()` swallows the success message after 1.4 s and never reuses the copied state

**File**: `resources/views/components/movies/cinematography.blade.php:19-32`

Minor UX papercut — the `copied` state is per-section local, but the component
doesn't visually highlight the just-copied colour swatch beyond the 1.4 s tooltip.
Looks like a TODO comment was lost. Not security/correctness, just polish.

---

### F-12 — LOW — `MovieSceneActor::activeAt()` scope reads decimal columns as strings; comparison with float relies on PHP/MySQL coercion

**Files**: `app/Models/MovieSceneActor.php:36-42` + scope `activeAt()`,
`app/Http/Controllers/XrayController.php:32-42`

The model casts `start_seconds` and `end_seconds` as `decimal:3`, which means the
Eloquent attribute is a PHP string. The XrayController converts the incoming `t`
query param to a float and passes it to a `where('start_seconds', '<=', $second)`
clause — relies on MySQL's loose type coercion. Works today on MySQL with
sql_mode permissive defaults; can mis-rank on stricter modes / different drivers
(e.g. SQLite in tests).

Not exploitable, just brittle. Cast to float explicitly in the query if the
strict-mode flag is ever flipped on staging.

---

## 5. Cross-cutting observations

- **AI fallback hygiene is uniformly applied** to the Wave 2/3 tasks (except F-9 above).
  Every reranker catches `\Throwable`, logs, and returns null/empty so local heuristic
  wins. Every "AI provider unavailable" path returns Indonesian copy ("tidak
  tersedia").
- **Audit logging coverage is good**: each AI task routes through `AiClient::chat()`
  which writes `ai_usage_logs` rows with `taskType` strings (e.g. `chat.plot_explain`,
  `comparison.run`). Spot-checked — all 18 task classes pass meaningful task types.
- **No `UsageTracker` regression risk** identified in this set — none of these
  services bypass `AiClient` to call provider SDKs directly.
- **Caching strategy is heterogeneous but reasoned**:
  - `SmartWatchlistController`: per-user 1 h, key includes watchlist signature.
  - `AiInsightsController`: 24 h global, `?refresh=1` to bypass.
  - `DirectorAuteurAnalyzer`: persisted to `director_analyses` table, never expires,
    explicit refresh route.
  - `YearInReviewController`: persisted to `year_in_reviews` table forever.
  - `MovieComparator` / `PlotExplainer`: not cached — each request is fresh. For
    PlotExplainer this is fine (user asks specific questions); for MovieComparator
    every A×B pair pays the AI cost again. Cheap fix: a `Cache::remember` keyed on
    `min(a.id, b.id) . '|' . max(...)` for ~24 h.
- **Player-side integration of intro/outro/recap + X-Ray is correctly mounted** on
  the Shaka path (`show.blade.php:47-101`). The Video.js (`@elseif($movieModel->video_path)`)
  and YouTube iframe fallback paths get **no auto-skip and no X-Ray** — fine,
  because those code paths are legacy/non-DRM, but worth a comment noting that
  feature parity is intentional.
- **Series episode player** (`EpisodeWatchController` → `episodes/watch.blade.php`)
  was not inspected here — flag F-6 calls this out as a related concern for the
  recap auto-skip wiring.

---

## 6. Suggested follow-ups (priority order)

1. **Decide X-Ray's fate** (F-1). Either ship a writer (seeder + admin annotation
   tool, or future ML pipeline) or stop polling for movies with no annotations.
   Currently every active playback is generating dead-weight HTTP traffic.
2. **Resolve SoundtrackAnalyzer** (F-2). Wire it into the movie detail page next to
   Cinematography, or delete it. The "shipped" docs lie.
3. **Schedule the heavy generators** (F-3). `flik:ai:bts`, `flik:ai:highlights`,
   `flik:ai:cinematography` need weekly cron entries with `--queue --limit=N`.
4. **De-duplicate plot-explain rate limits** (F-4) and fix `RateLimiter::hit` happening
   before AI success check (F-5).
5. **Audit `EpisodeWatchController` for recap auto-skip wiring** (F-6 follow-up). May
   need a sibling field on `episodes` table.
6. **Extract a `Movie::toCardArray()` helper** (F-8) before more controllers copy the
   pattern.
7. **Document the "throw vs fallback" AI-task split** (F-9) or bring PlotExplainer
   in line.
8. **Show a loading state on first Year-in-Review visit** (F-10) so the 10-second AI
   call doesn't look like a hung tab.
