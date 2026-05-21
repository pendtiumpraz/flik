# Audit 07 — Recommendations Domain

**Date**: 2026-05-20
**Scope**: Personalized recommendations stack — batch engine, cold-start, mood discovery,
time-aware shelf, smart watchlist, family-night, onboarding quiz.
**Mode**: Read-only.

---

## 1. Files inspected

| Layer | File |
|---|---|
| Service (batch) | `app/Services/Ai/Recommendations/RecommendationEngine.php` |
| Service (time-of-day) | `app/Services/Ai/Recommendations/TimeAwareRecommender.php` |
| Service (cold-start) | `app/Services/Ai/Tasks/ColdStartRecommender.php` |
| Service (mood) | `app/Services/Ai/Tasks/MoodDiscoveryService.php` |
| Service (watchlist sort) | `app/Services/Ai/Tasks/SmartWatchlistPrioritizer.php` |
| Service (group recs) | `app/Services/Ai/Tasks/FamilyMovieNightRecommender.php` |
| Controllers | `app/Http/Controllers/RecommendationController.php` |
|  | `app/Http/Controllers/OnboardingController.php` |
|  | `app/Http/Controllers/MoodDiscoveryController.php` |
|  | `app/Http/Controllers/SmartWatchlistController.php` |
|  | `app/Http/Controllers/FamilyNightController.php` |
|  | `app/Http/Controllers/WatchlistController.php` (for contrast — `/my-list`) |
| Console / job | `app/Console/Commands/RecomputeRecommendations.php` |
|  | `app/Jobs/BatchRecomputeRecommendations.php` |
|  | `app/Console/Kernel.php` (schedule definition) |
| Models | `app/Models/UserPreference.php`, `app/Models/UserRecommendation.php` |
| Migrations | `database/migrations/2026_05_10_010015_create_user_preferences_table.php` |
|  | `database/migrations/2026_05_10_010018_create_user_recommendations_table.php` |
| Routes | `routes/web.php` (lines ~1024–1071) |
| Views | `resources/views/onboarding/quiz.blade.php` |
|  | `resources/views/discovery/mood.blade.php` |
|  | `resources/views/watchlist/smart.blade.php`, `resources/views/watchlist/index.blade.php` |
|  | `resources/views/components/home/time-aware-shelf.blade.php` |
|  | `resources/views/main.blade.php` (home shelf wiring) |
|  | `resources/views/family-night/{form,result}.blade.php` |
| Rate limiters | `app/Providers/RouteServiceProvider.php` (lines ~115–127) |

---

## 2. Critical-check summary

| Check | Verdict | Evidence |
|---|---|---|
| Batch RecommendationEngine scheduled? | YES | `app/Console/Kernel.php:28` — `flik:recommendations:recompute` daily at 02:30 with `withoutOverlapping()->onOneServer()` |
| Cold-start kicks in for new users? | YES (engine path) | `RecommendationEngine::buildProfile()` sets `is_cold_start = signal_count < 2`, falls through to `coldStartFallback()` (popularity by `is_trending` desc, `popularity` desc). |
| Cold-start via onboarding quiz answers? | NO (gap — see findings) | `ColdStartRecommender` is the *richer* quiz-answer-aware path, but **no controller/job/command calls it.** It is dead code. |
| Onboarding quiz submit persists preferences? | YES | `OnboardingController::submit()` validates + `UserPreference::updateOrCreate` (lines 38–58); writes `favorite_genres`, `favorite_eras`, `preferred_moods`, `language_preference`, `onboarded_at`. |
| Mood discovery output reachable? | YES | Route: `GET/POST /discover/mood` (`routes/web.php:1032-1035`), throttle `ai-batch`. Linked in `header.blade.php` and `mobile-nav.blade.php`. |
| Time-aware shelf on home page? | YES | `resources/views/main.blade.php:37-39` — `<x-home.time-aware-shelf />` rendered for auth users only. JSON endpoint `/api/recommendations/time` also exists. |
| Smart watchlist sort visible to users? | YES | Route `/watchlist/smart` (`routes/web.php:1067`), linked in header dropdown (`header.blade.php:97,260`). Result cached 1h via signature key. |
| Recommendations cached per user (6h)? | YES | `RecommendationEngine::CACHE_TTL_SECONDS = 21600`; key `flik:user:{id}:recommendations` (`cacheKey()`). Cache populated in `cacheResult()` after every `computeFor()` call. |
| Popularity fallback when no data? | YES | `coldStartFallback()` orders by `is_trending` then `popularity`. `MoodDiscoveryService::fallbackPopular()` and `FamilyMovieNightRecommender::fallbackPopular()` provide their own equivalents. |
| AI degrades gracefully? | YES | All AI rerank paths (`RecommendationEngine::aiRerank`, `ColdStartRecommender::aiRerank`, `SmartWatchlistPrioritizer::aiRerank`, `FamilyMovieNightRecommender::aiRerank`) catch `\Throwable`, log warning, return null — local score is honored. |

---

## 3. Architecture map

```
                                              ┌──────────────────────────┐
                                              │  flik:recommendations:   │
                                              │  recompute  (02:30 daily)│
                                              └────────────┬─────────────┘
                                                           │ dispatch
                                                           ▼
                                              ┌──────────────────────────┐
                                              │ BatchRecomputeRecommend- │
                                              │ ations  (queue ai-batch) │
                                              └────────────┬─────────────┘
                                                           │ per active user (last 30d)
                                                           ▼
┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET /api/recommendations          │───▶│ RecommendationEngine::computeFor()   │
│ RecommendationController::forUser │    │   • buildProfile (history+ratings+   │
└───────────────────────────────────┘    │     watchlist; cold-start <2 signals)│
                                         │   • generateCandidates (top genre +  │
                                         │     popularity)                      │
                                         │   • scoreCandidates (genre×6 +       │
                                         │     rating×2 + popularity×1)         │
                                         │   • aiRerank (only if signals ≥ 8)   │
                                         │   • persist → user_recommendations   │
                                         │   • cache 6h → Redis                 │
                                         └──────────────────────────────────────┘

┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET /api/recommendations/time     │───▶│ TimeAwareRecommender                 │
│ x-home.time-aware-shelf (auth)    │    │   slot ∈ {morning, afternoon,        │
│ RecommendationController::byTime  │    │   evening, late, overnight} via      │
│   ofDay                           │    │   Asia/Jakarta hour; blends slot     │
└───────────────────────────────────┘    │   genres + user genre weights        │
                                         └──────────────────────────────────────┘

┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET /onboarding (auth)            │    │ UserPreference (user_preferences):   │
│ POST /onboarding                  │───▶│   favorite_genres, favorite_eras,    │
│ OnboardingController              │    │   preferred_moods, onboarded_at      │
└───────────────────────────────────┘    │   ← also triggers Referral qualify   │
                                         └──────────────────────────────────────┘
                                                  (no caller bridges these prefs
                                                   into ColdStartRecommender)

┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET/POST /discover/mood           │───▶│ MoodDiscoveryService::recommend()    │
│ MoodDiscoveryController           │    │   AI tags → ai_tags JSON contains    │
│ throttle:ai-batch                 │    │   → genre-slug fallback              │
└───────────────────────────────────┘    │   → popularity fallback              │
                                         └──────────────────────────────────────┘

┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET /watchlist/smart              │───▶│ SmartWatchlistPrioritizer::          │
│ SmartWatchlistController          │    │   prioritize()                       │
│ Cache::remember 1h, key = sig     │    │   local score + AI rerank top-10     │
└───────────────────────────────────┘    └──────────────────────────────────────┘

┌───────────────────────────────────┐    ┌──────────────────────────────────────┐
│ GET/POST /family-night            │───▶│ FamilyMovieNightRecommender::        │
│ FamilyNightController             │    │   recommend(userIds, constraints)    │
│ throttle:ai-batch                 │    │   consensus from N viewers' history  │
└───────────────────────────────────┘    └──────────────────────────────────────┘
```

---

## 4. Findings

### 4.1 HIGH — ColdStartRecommender is dead code; onboarding answers don't flow into recs

**Files**: `app/Services/Ai/Tasks/ColdStartRecommender.php`, `app/Http/Controllers/OnboardingController.php`, `app/Services/Ai/Recommendations/RecommendationEngine.php`

`ColdStartRecommender::recommendForNewUser(UserPreference $prefs, …)` is a fully implemented
genre+era+era-overlap → local score → AI-rerank pipeline (140 LOC). Grep across the entire
codebase shows **zero callers** — it's referenced only in `PROGRESS_REPORT.md` checklists and
docs:

```
$ grep -rn "ColdStartRecommender" app/
app/Services/Ai/Tasks/ColdStartRecommender.php:21:class ColdStartRecommender
app/Services/Ai/Tasks/ColdStartRecommender.php:180:  Log::warning('ColdStartRecommender AI re-rank failed', …);
```

Meanwhile `RecommendationEngine`'s cold-start path (`coldStartFallback()`) **completely ignores
`UserPreference`** and just returns `Movie::orderByDesc('is_trending')->orderByDesc('popularity')`.
After `OnboardingController::submit()` redirects to `/movies`, the user's painstakingly chosen
genres / eras / moods are written to `user_preferences` but never used to generate recommendations.
The next time `/api/recommendations` is hit, the user is treated as if onboarding hadn't happened
(unless they've already racked up ≥2 signals from ratings / watchlist / history).

**Impact**: The headline cold-start UX (3-question quiz → tailored picks) is broken at the seam.
User sees a generic "trending" list immediately after onboarding.

**Fix sketch** (do not apply — read-only audit): in `RecommendationEngine::coldStartFallback()`
(or a new branch before it), if `UserPreference::where('user_id', $user->id)->first()` exists,
delegate to `app(ColdStartRecommender::class)->recommendForNewUser($prefs, $count)` and persist
its output through the same `UserRecommendation` rows.

---

### 4.2 HIGH — No automatic onboarding redirect for new users

**Files**: `app/Observers/UserObserver.php`, `app/Http/Middleware/*`, `routes/web.php`

There is no middleware or post-registration redirect that sends a freshly-registered user to
`/onboarding`. The quiz route is reachable only via the header dropdown / a direct link. The
`onboarded_at` timestamp is set when the form is submitted, but nothing reads it elsewhere to
gate access (e.g. show a banner, force the quiz before `/movies`). Combined with finding 4.1,
this means the quiz is essentially optional and most users will never complete it.

Grep evidence:

```
$ grep -rn "onboarding.quiz\|/onboarding" app/
app/Http/Controllers/OnboardingController.php:21:    /** Show the 3-question onboarding quiz. */
app/Http/Controllers/SeoController.php:76:        Disallow: /onboarding   ← robots.txt
app/Http/Controllers/SeoController.php:113:        'loc' => url('/onboarding'),
```

No middleware, no observer hook, no `EnsureOnboarded` guard exists.

---

### 4.3 MEDIUM — `OnboardingController` writes genre **slugs**, but `RecommendationEngine` keys on genre **ids**

**Files**: `OnboardingController.php:52`, `resources/views/onboarding/quiz.blade.php:59`,
`ColdStartRecommender.php:43-46`, `RecommendationEngine.php:128-236`

The quiz checkboxes submit `value="{{ $genre->slug }}"` (e.g. `["action","drama"]`).
`UserPreference::favorite_genres` therefore stores slugs. `ColdStartRecommender` correctly
queries both `genres.slug` and `genres.name` to match. **But** if/when `RecommendationEngine`
is wired to consume `UserPreference` per finding 4.1, its `genre_weights` map is keyed by
`genre_id` (integer) — so anyone copying that pattern needs to remember the slug→id translation
step. There is no shared `ResolveGenrePreference` helper.

This is a latent footgun rather than a live bug, but worth flagging because two services already
disagree on the canonical shape of "user's favorite genres".

---

### 4.4 MEDIUM — `ai-batch` rate limiter applied to `/discover/mood`, but `MoodDiscoveryController` also runs its own `RateLimiter::hit()` 15/min

**File**: `app/Http/Controllers/MoodDiscoveryController.php:40-51`

```php
$rateKey = 'mood-discover:' . (auth()->id() ?? $request->ip());
if (RateLimiter::tooManyAttempts($rateKey, 15)) { … }
RateLimiter::hit($rateKey, 60);
```

…while the route already has `throttle:ai-batch` (hourly limit per user). This is double-throttling.
The view-level limiter is much tighter (15 hits per 60s) and silently swallows the user's mood
input into an error message rather than returning a 429. If the platform later raises `ai-batch`
caps, this hard-coded 15 cap won't move with it. Same pattern in `FamilyNightController:66-72`
(also throttle:ai-batch + manual `RateLimiter::hit` 10/min).

Not exploitable, just inconsistent. Consider removing the in-controller throttle now that the
named limiter exists.

---

### 4.5 MEDIUM — `RecommendationEngine::persist()` wipes all prior recs in a transaction

**File**: `app/Services/Ai/Recommendations/RecommendationEngine.php:445-452`

```php
DB::transaction(function () use ($user, $rows) {
    UserRecommendation::where('user_id', $user->id)->delete();
    foreach (array_chunk($rows, 100) as $chunk) {
        UserRecommendation::insert($chunk);
    }
});
```

The migration declares a unique index `['user_id','movie_id','batch_id']` and an analytical scope
`UserRecommendation::scopeLatestBatchFor()` that selects "the most recent batch". The persist
strategy contradicts both: it deletes all history, so you can never compare batch-over-batch
recommendations, A/B test the engine, or roll back to "yesterday's picks". The `batch_id` column
becomes vestigial because there's only ever one batch in the table at a time per user.

Also: the `forUser` controller's `loadFromDatabase()` fallback does this:

```php
$latestBatch = UserRecommendation::where('user_id', $userId)->orderByDesc('generated_at')->value('batch_id');
```

…which works fine on the current persist scheme, but if persistence is ever changed to keep
history, this scope-driven query is already in place — so the *reader* assumes batching, the
*writer* doesn't preserve it.

**Recommendation**: prune by batch age (e.g. keep last 3 batches) instead of full wipe; or
explicitly document the "table is always exactly one batch wide" invariant in the model.

---

### 4.6 MEDIUM — `BatchRecomputeRecommendations` has no failure ceiling and `tries = 1`

**File**: `app/Jobs/BatchRecomputeRecommendations.php:38-67`

`$tries = 1` is correct for the wrapping job (it's idempotent per-user). But the inner loop
swallows every `\Throwable` per user and only logs, never re-raises. If the AI provider goes
down for an hour the job will silently "succeed" with `processed=0, failed=5000` — observability
is one `Log::info` line. Consider:

- Emit a metric (`failed/total`) when `failed/processed > 0.10`.
- Surface a degraded-state notification to super_admins on the next `flik:report:daily`.

The pattern is fine, just under-instrumented.

---

### 4.7 LOW — `TimeAwareRecommender::excludedMovieIds()` only excludes `completed = true`

**File**: `app/Services/Ai/Recommendations/TimeAwareRecommender.php:271-279`

Excludes only fully-completed views, so a movie the user has watched 90% of yesterday is still
eligible to be re-recommended in tonight's "Cocok ditonton sekarang" slot. Compare with
`RecommendationEngine::buildProfile()` (line 156) which excludes ALL watched IDs (any history
row). The two services disagree on what "watched" means. Probably intentional ("near-complete
should be ignored" vs "give it another chance"), but worth a comment to lock in the policy.

---

### 4.8 LOW — `time-aware-shelf` blade resolves the service on every page render

**File**: `resources/views/components/home/time-aware-shelf.blade.php:25-41`

The shelf is included unconditionally on the home page (`main.blade.php:37`) for auth users.
Every home render = a `TimeAwareRecommender` invocation = 3 DB queries (history×60, ratings,
watchlist) + a candidate fetch + scoring. No caching. Compare with `SmartWatchlistController`
which `Cache::remember(…, 1h, …)`'s its result.

For a brand-new homepage hit this is ~5 queries + N×genre eager-loads. Not a security issue,
but the home page is the most-trafficked route — a 5–10 min cache here keyed on
`{user_id}:{slot}` would cut DB load significantly. The shelf even has a client-side JS refresher
that rechecks the slot every 60s, so a short server-side cache is safe.

---

### 4.9 LOW — `UserPreference::language_preference` is collected but unused

**File**: `app/Models/UserPreference.php:18`, `OnboardingController.php:46`

The quiz form does **not** render a language input (`resources/views/onboarding/quiz.blade.php`
only shows genres / eras / moods sections — the field is `nullable` in the controller validation).
The column exists, the cast exists, the controller persists it, but nothing prompts the user for
it and nothing reads it back. Dead field, easy cleanup.

---

### 4.10 LOW — `MoodDiscoveryService::aiTagsColumnExists()` static cache won't see schema changes within a long-lived worker

**Files**: `MoodDiscoveryService.php:303-315`, `SmartWatchlistPrioritizer.php:359-371`,
`FamilyMovieNightRecommender.php:419-428`

Three services cache the `Schema::hasColumn('movies','ai_tags')` probe in a `static $cached`
variable that survives for the lifetime of the PHP process. Long-running queue workers
(`queue:work` without `--max-jobs`) will get a stale answer across migrations. Minor — migrations
on production should restart workers anyway — but worth a note.

---

## 5. Cross-cutting observations

- **AI fallback hygiene is consistent**: every reranker catches `\Throwable`, logs a warning, and
  returns null/empty so local heuristic wins. This pattern is honored uniformly.
- **No `UsageTracker` integration in `MoodDiscoveryService` / `SmartWatchlistPrioritizer` /
  `FamilyMovieNightRecommender`** — they call `AiClient::chat()` directly, and `AiClient` is
  documented (CLAUDE.md) to auto-write `ai_usage_logs` rows. As long as that's true, all good;
  if any of these services switch to a raw provider call, the cost tracking will silently drop.
- **`UserRecommendation::SOURCE_COLLABORATIVE`** is a defined constant but never written — no
  collaborative filter path exists in the engine. Either remove the constant or document the
  reserved future intent.
- **Schema readiness**: `user_preferences` has a `UNIQUE(user_id)` constraint (correct for
  `updateOrCreate`), and `user_recommendations` has `INDEX(user_id, score)` for the hot
  list-by-user query. Indexes look right.

---

## 6. Suggested follow-ups (priority order)

1. **Wire `ColdStartRecommender` into the rec pipeline** (or delete it). Currently the onboarding
   quiz is UX theater — collected answers go nowhere. (Finding 4.1)
2. **Add a post-registration redirect / banner** to push new users into `/onboarding`.
   (Finding 4.2)
3. **Standardise "user's favorite genres" shape** (slug vs id) in a small helper before
   anyone copy-pastes a third interpretation. (Finding 4.3)
4. **Decide on batch retention policy** for `user_recommendations` (keep N batches vs wipe-on-
   write) and align reader/writer. (Finding 4.5)
5. **Cache `TimeAwareRecommender` output** per `{user, slot}` for ~10 min on home page. (4.8)
6. **Remove dead `language_preference`** column or surface a UI for it. (Finding 4.9)
7. **Remove in-controller `RateLimiter::hit` calls** that duplicate `throttle:ai-batch`. (4.4)
