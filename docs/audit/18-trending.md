# Audit 18 — Trending & View Tracking

**Scope:** `MovieView` ledger, `ViewTracker` (30-min dedupe, peppered ip_hash), `TrendingAggregator` (4 windows: 1h/24h/7d/30d), `TrendingMovie` cache table, scheduler entries, home "Trending" shelf, `/trending` page with tabs.
**Date:** 2026-05-20
**Branch:** `main`
**Verdict:** PARTIAL — wiring, privacy, scheduling, and UI are all correct, but the **recency boost in the scoring formula is broken by Carbon 3 signed `diffInSeconds`** (every movie gets the same boost, defeating the point), and the spec's **">30s threshold" for the watch-progress trigger is not actually enforced**.

---

## 1. `MovieView` ledger & schema

### Migrations
- `database/migrations/2026_05_10_090001_create_movie_views_table.php` — fields: `id`, `movie_id` (FK cascade), `user_id` (nullable FK nullOnDelete), `session_id` (string 40, nullable), `ip_hash` (char 64, nullable), `viewed_at` (timestamp indexed), `duration_seconds` (uint nullable), `device` (string 20 nullable). No `created_at/updated_at` — `viewed_at` is canonical.
- Indexes: `(viewed_at)`, `(movie_id, viewed_at)`, `(user_id, viewed_at)` — exactly what `TrendingAggregator::compute` and future "user history" reports need.
- `database/migrations/2026_05_10_090002_create_trending_movies_table.php` — `id`, `movie_id` (FK cascade), `window` (enum `1h|24h|7d|30d`), `view_count`, `unique_viewer_count`, `score` (double), `rank` (uint smallint), `computed_at`, timestamps. Unique `(movie_id, window)`, index `(window, rank)`.
- Idempotent guards (`if (Schema::hasTable(...)) return;`) on both up() methods — safe to re-run.

### Model `App\Models\MovieView`
- `$timestamps = false`, `$fillable` lists all writable columns; reinforces in the docblock that callers should go through `ViewTracker` rather than `MovieView::create()` directly.
- `viewed_at` cast to datetime; `duration_seconds` cast to integer.

### Model `App\Models\TrendingMovie`
- `$casts` covers all numeric/time fields. `scopeForWindow($q, $window)` is the canonical read path (`WHERE window = ? ORDER BY rank`).

### Issues
None. Schema, indexes, and models match the documented intent.

---

## 2. `ViewTracker` — write-path & dedupe

### Call sites
- `App\Http\Controllers\WatchHistoryController::updateProgress` (line 71) — fires `record(...)` only when `$isNew` (first WatchHistory row for this user/movie/episode). Includes `durationSeconds`.
- `App\Http\Controllers\VelflixController::show` (line 258) — fires `record(...)` on every detail-page render, `durationSeconds: null`. Dedupe handles repeats.
- These two are the **only** call sites in `app/` per `grep ViewTracker`. No other surface (search, recommendations, watch parties) feeds the ledger — intentional, matches the class docblock.

### Dedupe window
- Constant `DEDUP_MINUTES = 30` (`ViewTracker.php:48`). Implemented two-layer:
  1. **Cache lock** keyed `trending:view-lock:{movieId}:{userId|-}:{ipHashPrefix|-}:{sessionPrefix|-}` with TTL `60s` (line 51). Pre-empts within-process bursts without a DB round-trip.
  2. **DB check** `wasRecentlyRecorded()` — `viewed_at >= now()-30min` AND (`user_id=?` OR `ip_hash=?` OR `session_id=?`). Authoritative gate for multi-worker / multi-server scenarios.
- The cache lock TTL (60s) is intentionally **shorter** than the dedup window (30 min). For minutes 1–30 the DB check is doing the work; the lock only blocks the very-rapid-fire case. Fine, but a leaky abstraction — see V-1.
- Lock gets `Cache::put()` even after the DB no-op path (lines 82, 100) so a successful negative doesn't keep re-hitting the DB.

### `ip_hash` peppering
- **Correct.** `hash('sha256', $ip.'|'.$pepper)` where `$pepper = (string) config('app.key')` (lines 154-156). `APP_KEY` is the pepper, makes the hash unreversible from the (~4B) IPv4 keyspace even on full table leak.
- `'|'` separator prevents `ip=1.2.3.4` + `key=5` from colliding with `ip=1.2.3.45` (defence against length-extension-style preimage tricks — not a real attack on sha256, but defensive).
- `ip_hash` column is `char(64)` and the function output is 64 hex chars — exact-fit.
- Migration column comment explicitly states `"sha256(ip + APP_KEY pepper) — anonymous unique counter, NOT reversible to IP"` — good audit trail.

### Session id resolution
- Prefers Laravel session ID, falls back to a fresh `Str::random(40)` for CLI/API contexts (lines 119-136). Truncated to 40 chars to match column width.
- The random fallback **never dedupes** because each call gets a new token. That's fine for genuine CLI runs (rare); the user-facing flows always have a session.

### Device sniffing
- `match(true)` ladder on lowercased UA; returns one of `tv|tablet|mobile|desktop|null` (lines 164-181). Capped at 20 chars implicit by the column width. The class docblock correctly points out that fine-grained fingerprinting lives in `DeviceFingerprinter` for DRM, not here.

### Error path
- `try/catch (Throwable)` wraps the whole `record()` body — failures are `Log::warning`ed but never rethrown (lines 101-110). Matches `AuditLogger` pattern; the player and detail page must keep working when the trending table is unreachable.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **V-1** | **MEDIUM (spec gap)** | The audit checklist asks "**ViewTracker called from WatchHistoryController on first progress (>30s threshold)?**". The actual gate is `if ($isNew)` — i.e. the *first WatchHistory row*, not the *first progress write past 30 seconds*. A user who opens the player and lets it play for 2 seconds before navigating away **still records a trending view** (the `WatchHistory` row is created on first AJAX heartbeat, and `current_time` can be 0). The spec's intent was presumably to discount tiny-bounce sessions. If the checklist is authoritative, add `&& (float) $request->current_time >= 30` (mirroring the streak-service gate at line 98 which already uses 60s). | `app/Http/Controllers/WatchHistoryController.php:68` |
| V-2 | LOW | Lock-key prefix collisions: the lock truncates `ip_hash` to 16 chars (line 236). 16 hex = 64 bits → birthday collisions ~2³² entries, comfortably above realistic traffic per-minute. Documented as a perf trade-off. |  `ViewTracker.php:226-239` |
| V-3 | LOW | When `$user_id` changes (anon → login mid-session), the cache lock key changes and a duplicate insert can slip past the cache layer; the DB check still catches it because `session_id` stays the same. Documented expectation, not a bug. |  |

---

## 3. `TrendingAggregator` — scoring + cache rewrite

### Windows & cadence (matches checklist exactly)
| Window | Lookback (`self::WINDOWS`) | Scheduled cadence (`App\Console\Kernel`) |
|---|---|---|
| `1h` | 60 min | `everyTenMinutes()` (line 79) |
| `24h` | 24 × 60 min | `hourlyAt(5)` (line 84) |
| `7d` | 7 × 24 × 60 min | `everySixHours()` (line 89) |
| `30d` | 30 × 24 × 60 min | `dailyAt('03:30')` (line 94) |

All four entries carry `->withoutOverlapping()->onOneServer()` — HA-safe.

### Score formula (per docblock)
```
raw   = view_count + (unique_viewer_count * 2) + recency_boost
final = raw * (is_popular ? 0.5 : 1.0)
```
Matches the checklist: `views + uniques×2 + recency_boost`, then half-weight for `is_popular` movies.

### Aggregation query
- Single `MovieView` SELECT with `COUNT(*)`, `COUNT(DISTINCT user_id)`, `MAX(viewed_at)` grouped by `movie_id`, filtered to `viewed_at >= now()-N`. Uses the `(viewed_at)` index for the range; the `(movie_id, viewed_at)` index covers the group.
- Anonymous viewers (`user_id IS NULL`) collapse to `COUNT(DISTINCT user_id) = 0` — they contribute to `view_count` but not `unique_viewer_count`. The class docblock spells this out and notes `DISTINCT ip_hash` as a future addition once pepper-rotation is solved.

### Featured-vs-organic split
- Bulk fetch of `is_popular` for all candidate `movie_id`s in one query (`pluck('is_popular', 'id')`) — no N+1.
- Penalty: `0.5 *` applied only when `is_popular == true`. The class docblock notes this is a proxy for a future `is_featured` flag (which doesn't exist yet) and the rationale (popular films already get hero-slider/"Trending Now" billing, so half-weighting prevents double-counting).
- `Movie::is_popular` exists on the table (`2026_03_07_000002_create_movies_table.php:22`).

### Cache atomicity
- `DB::transaction(function () use (...) { ... })` wraps `delete()` + chunked `insert(50)` (lines 155-184). Atomic per-window; readers see either the old slice or the new one, never half.
- **Per-window scope** — `WHERE window = ?` on the delete (line 159). Other windows running concurrently on independent schedules are not affected.
- Top 100 cap (`TOP_N = 100`) keeps the table tiny.

### Empty-window behaviour
- If `MovieView` has zero rows in the window, the aggregator deletes the cached slice and returns (`TrendingMovie::where('window', $window)->delete()`, line 105). Without this, a yesterday-hot list would keep serving 24h after the last view. Logged at info level.
- Empty post-scoring is also handled (line 161 `if ($scored->isEmpty()) return;`).

### Recency boost — BUG
- The intended formula: `recencyBoost = max(0, 1.0 - (ageSeconds / windowSeconds))` → newer views in the window count for slightly more.
- Implementation (line 134):
  ```php
  $ageSeconds = max(0, $now->diffInSeconds($lastViewedAt));
  $recencyBoost = max(0.0, 1.0 - ($ageSeconds / $windowSeconds));
  ```
- **Carbon 3** (locked at `nesbot/carbon 3.11.1` per `composer.lock`) changed `diffInSeconds()` to be **signed by default** and to return a float. When `$lastViewedAt < $now` (the only case we ever see — `last_viewed_at` is `MAX(viewed_at)` of past rows), `$now->diffInSeconds($lastViewedAt)` is **negative**.
- `max(0, negative) = 0` → `recencyBoost = 1.0 - 0 = 1.0` for **every** movie in every window.
- Net effect: the recency term contributes a constant `+1.0` to every `raw` score, so it provides **no discrimination at all**. A film whose most recent view was 30 minutes ago and a film whose most recent view was 30 days ago get the same boost — defeating the entire purpose of the recency_boost term.
- This was likely written against Carbon 2 (where `diffInSeconds` was absolute by default), or by mental model. Fix: either `abs($now->diffInSeconds($lastViewedAt))`, or use `$lastViewedAt->diffInSeconds($now)` (still signed but in the expected direction), or `$now->getTimestamp() - $lastViewedAt->getTimestamp()` (explicit and Carbon-version-proof — recommended).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **A-1** | **HIGH (correctness)** | `recencyBoost` always evaluates to `1.0` for every row because of Carbon 3 signed `diffInSeconds`. The recency component is dead weight, equal across all films — the formula effectively collapses to `views + 2×uniques (+1) × penalty`. Recency-of-momentum is not reflected in the score at all. **Fix:** replace with `$ageSeconds = max(0, $now->getTimestamp() - $lastViewedAt->getTimestamp());`. | `app/Services/Trending/TrendingAggregator.php:131-135` |
| A-2 | LOW | `recencyBoost` is at most `1.0` — for any film with a non-trivial view_count this is rounding noise (`raw = 50 + 2*20 + ~0.7` vs `... + ~0.1`). Even with A-1 fixed, the term is so weak it rarely changes the ranking. Either widen the weight (e.g. `* 10`) or accept it as a deliberate tiebreaker only. | same |
| A-3 | LOW | `DISTINCT user_id` doesn't include `DISTINCT ip_hash` for the anonymous half of the population — anonymous traffic is rolled entirely into `view_count`. Documented in the class docblock as a "future addition once we trust the pepper rotation story". | `:91-98` |
| A-4 | LOW | `chunked insert(50)` is defensive but `TOP_N = 100` means at most 2 chunks ever. Harmless. | `:181-183` |

---

## 4. `RecomputeTrending` console command

- Signature `flik:trending:recompute {--window=all : 1h | 24h | 7d | 30d | all}`.
- `--window=all` (default) iterates every key of `TrendingAggregator::WINDOWS` in source order. Unknown windows are listed and rejected with exit code FAILURE before any work starts (lines 31-43).
- Per-window try/catch — one window failing doesn't take the others offline (lines 51-62). The scheduler calls each window individually anyway, so this only matters for manual `--window=all` runs.
- Returns `SUCCESS` only when every window succeeded; `FAILURE` if any failed (so `task` automation sees the right exit code).
- Wall-clock timing logged per window. No production-impacting `Cache::flush` or similar.

### Issues
None.

---

## 5. Scheduler entries (`App\Console\Kernel`)

- All four `flik:trending:recompute --window=XX` entries are present at lines 78-96.
- Cadence matches checklist precisely: 1h→every 10 min, 24h→`hourlyAt(5)` (5 past the hour — keeps it off the top-of-hour spike with other jobs), 7d→every 6 h, 30d→`dailyAt('03:30')`.
- Each carries `withoutOverlapping()` (prevents a long 30d run colliding with itself) and `onOneServer()` (HA — only one box runs each window).
- No `timezone(...)` declared on the trending entries; they inherit `APP_TIMEZONE` (the CLAUDE.md note suggests `Asia/Jakarta`). Other jobs (`flik:backup`, `flik:security:daily-digest`) explicitly set `timezone('Asia/Jakarta')`; trending should arguably do the same for the `30d` daily entry to make `03:30` unambiguous if the server's `APP_TIMEZONE` ever drifts.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| S-1 | LOW | Trending scheduler entries don't pin `->timezone('Asia/Jakarta')` like other Indonesian-time-sensitive jobs do. Inherits global `APP_TIMEZONE`, which is fine but inconsistent. | `app/Console/Kernel.php:78-96` |

---

## 6. Home shelf (`<x-home.trending-shelf>`)

- Mounted at `resources/views/main.blade.php:25` with `window="24h"` and `limit=12`.
- Reads `TrendingMovie::forWindow($window)->with(['movie.genres'])->take($limit)->get()->filter(fn($r) => $r->movie !== null)` — `forWindow` is the model scope, the `(window, rank)` index covers the sort.
- `filter(fn($r) => $r->movie !== null)` defends against the race where a movie was deleted between aggregator runs.
- **Empty cache → renders nothing.** Whole `<section>` is wrapped in `@if($rows->isNotEmpty())` (line 43). No "no trending yet" stub on the home page — confirmed against checklist.
- Try/catch around the read (lines 22-32) — DB failure logs a warning and degrades to empty.
- "Lihat semua" link points at `/trending#window-{$window}` so the deep link lands on the matching tab.
- Title is per-window Indonesian: "Trending Sekarang / Hari Ini / Minggu Ini / Bulan Ini".
- Netflix-style giant rank numeral via `.trending-rank` CSS (font-size 156px desktop, 116px mobile).
- `loading="lazy"` + `onerror` poster fallback are in place.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| H-1 | LOW | Component imports `App\Models\TrendingMovie` inside the `@php` block — fine, but the `@props` validation lets callers pass arbitrary `$window` values which are then bounced against `$allowedWindows` (line 19). Good defensive pattern. | informational |
| H-2 | LOW | Inline `<style>` block ships on every page render (no caching). At ~50 lines this is negligible; if the shelf gets re-used elsewhere consider hoisting to `app.css`. | `:99-148` |

---

## 7. `/trending` page

- Route: `Route::get('/trending', [TrendingController::class, 'index'])->name('trending.index')` (public, no auth) — `routes/web.php:16-17`.
- Controller pulls all four windows in **one query** (`whereIn('window', $windows)`) then buckets in PHP — saves four round-trips, ~400-row upper bound is tiny.
- `with(['movie.genres'])` eager-loads to avoid N+1 in the grid.
- Missing-window safety net: `foreach ($windows as $w) { $rowsByWindow[$w] = $rowsByWindow[$w] ?? collect(); }` — ensures every tab key exists in the view (lines 43-45).
- View `resources/views/trending/index.blade.php` — Alpine `x-data="{ active: '24h' }"`, tabs render via `@foreach($windows)`, panels rendered with `x-show="active === '...'"` + `x-cloak`.
- Deep link: `x-init` parses `window.location.hash` (`/window-(1h|24h|7d|30d)/`) and sets `active = m[1]` — matches the home-shelf "Lihat semua" link.
- Hash also updated on tab click: `window.location.hash = 'window-{$w}'` — shareable URLs survive refresh.
- Empty per-window state renders a "Belum ada data trending untuk periode ini" card — distinct from the home-shelf's "render nothing" stance, which is correct: on a dedicated `/trending` page an empty stub is actually the right UX.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| T-1 | LOW | `$genres = Genre::all()->mapWithKeys(...)` is computed but **never referenced** by the view (the view uses `$movie->genres` from the eager-load). Dead code; small DB hit on every page load. | `app/Http/Controllers/TrendingController.php:47` |
| T-2 | LOW | `x-show` with `x-cloak` is fine, but on slow Alpine boot users briefly see all four panels stacked. Acceptable, but `x-show` could be swapped for server-side default (`@if ($w === '24h')`) to avoid the flash. | `resources/views/trending/index.blade.php:52` |
| T-3 | LOW | The header copy claims "Diperbarui otomatis tiap 10 menit" — true for the `1h` window only. The 24h window updates hourly, 7d every 6h, 30d daily. Not factually wrong (10 min is the floor), but might mislead users expecting the 30d list to refresh that frequently. | `:33` |

---

## 8. End-to-end checklist (from the prompt)

| Check | Status | Notes |
|---|---|---|
| ViewTracker called from `WatchHistoryController` on first progress | **PARTIAL** | Fires on `$isNew` row, **not** on `>30s threshold`. See V-1. |
| ViewTracker called from `VelflixController::show` (page view) | PASS | `durationSeconds: null`, dedup handles refresh storms. |
| Dedupe window (30 min per identity) actually works | PASS | Cache lock (60s) + DB recency check (30 min) two-layer. |
| `ip_hash = sha256(ip + APP_KEY)` — peppered properly | PASS | `hash('sha256', $ip.'|'.config('app.key'))`. Column comment + class docblock both flag privacy intent. |
| `TrendingAggregator` wipes + replaces cache atomically | PASS | `DB::transaction { delete + chunked insert }`, scoped per-window. |
| Score formula = `views + uniques×2 + recency_boost` | **PARTIAL** | Formula is right on paper but `recency_boost` is broken by Carbon 3 signed `diffInSeconds` — always = 1.0. See A-1. |
| All 4 windows scheduled (1h every 10min, 24h hourly, 7d every 6h, 30d daily 03:30) | PASS | Exact cadence match. All `withoutOverlapping()->onOneServer()`. |
| Home shelf renders nothing on empty cache (no broken UI) | PASS | `@if($rows->isNotEmpty())` wraps the entire `<section>`. |
| `/trending` page has tab per window with deep-link | PASS | Alpine tabs + `#window-XX` hash, `x-init` parses on load. |
| Featured-vs-organic split (`is_popular` weighted 0.5×) | PASS | `FEATURED_PENALTY = 0.5` applied; `is_popular` documented as a proxy until a real `is_featured` ships. |

---

## Top fixes to ship before release

1. **`app/Services/Trending/TrendingAggregator.php:131-135` (A-1)** — replace `$now->diffInSeconds($lastViewedAt)` with `max(0, $now->getTimestamp() - $lastViewedAt->getTimestamp())`. Without this fix the `recency_boost` term is a constant `+1.0` for every movie, defeating the design intent of "hot right now beats hot all week".
2. **`app/Http/Controllers/WatchHistoryController.php:68` (V-1)** — decide whether the spec's ">30s threshold" matters. If yes, gate the `record(...)` call additionally on `(float) $request->current_time >= 30`. If no, drop the threshold from the audit checklist and document the actual `$isNew` semantics in the controller comment.
3. **`app/Console/Kernel.php:78-96` (S-1, nit)** — pin `->timezone('Asia/Jakarta')` on the `30d daily 03:30` entry for consistency with the other Jakarta-time-sensitive jobs. The other three (10-min, hourly, every-6h) don't care about wall-clock TZ.
4. **`app/Http/Controllers/TrendingController.php:47` (T-1, nit)** — delete the dead `$genres = Genre::all()...` line or wire it into the view.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Models\MovieView.php`
- `D:\AI\velflix\velflix\app\Models\TrendingMovie.php`
- `D:\AI\velflix\velflix\app\Services\Trending\ViewTracker.php`
- `D:\AI\velflix\velflix\app\Services\Trending\TrendingAggregator.php`
- `D:\AI\velflix\velflix\app\Console\Commands\RecomputeTrending.php`
- `D:\AI\velflix\velflix\app\Console\Kernel.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\TrendingController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\VelflixController.php` (`show()` only, lines 249-263)
- `D:\AI\velflix\velflix\app\Http\Controllers\WatchHistoryController.php`
- `D:\AI\velflix\velflix\resources\views\components\home\trending-shelf.blade.php`
- `D:\AI\velflix\velflix\resources\views\trending\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\main.blade.php` (shelf mount only, line 25)
- `D:\AI\velflix\velflix\routes\web.php` (trending route, lines 12-17)
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_090001_create_movie_views_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_090002_create_trending_movies_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_03_07_000002_create_movies_table.php` (for `is_popular` confirmation)
- `D:\AI\velflix\velflix\composer.lock` (for Carbon 3.11.1 confirmation)
