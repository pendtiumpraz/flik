# Audit 08 — Discovery / Search

**Date:** 2026-05-20
**Scope:** Universal smart search (intent-routed), advanced search modes
(image / vibe / person), header smart bar + autocomplete typeahead, trending
shelf + standalone trending page, legacy Livewire search components.
**Verdict:** Mostly healthy. One blocker (legacy Livewire is **orphaned but
not removed** — and the older copy is **functionally broken**) plus a handful
of medium-severity issues around guest reach, intent override UX,
genre-keyword shadowing, and AI link guard scope.

---

## Files inventoried

| Layer       | Path                                                                              | Status |
| ----------- | --------------------------------------------------------------------------------- | ------ |
| Controller  | `app/Http/Controllers/SmartSearchController.php`                                  | OK |
| Controller  | `app/Http/Controllers/AdvancedSearchController.php`                               | OK |
| Controller  | `app/Http/Controllers/TrendingController.php`                                     | OK |
| Service     | `app/Services/Ai/Search/IntentClassifier.php`                                     | OK |
| Service     | `app/Services/Ai/Search/ImageSearchService.php`                                   | OK |
| Service     | `app/Services/Ai/Search/DecadeStyleSearchService.php`                             | OK |
| Service     | `app/Services/Ai/Search/DirectorActorSearchService.php`                           | OK |
| Livewire    | `app/Http/Livewire/SearchVelflix.php` (legacy)                                    | Orphaned + bug |
| Livewire    | `app/Livewire/SearchFlik.php` (newer copy, also unused)                           | Orphaned |
| View        | `resources/views/livewire/search-velflix.blade.php`                               | Orphaned + bug |
| View        | `resources/views/livewire/search-flik.blade.php`                                  | Orphaned |
| View        | `resources/views/components/search/smart-bar.blade.php`                           | OK |
| View        | `resources/views/components/header.blade.php` (mounts smart-bar @auth only)       | Issue |
| View        | `resources/views/search/smart.blade.php`                                          | OK |
| View        | `resources/views/search/image.blade.php`                                          | OK |
| View        | `resources/views/search/vibe.blade.php`                                           | OK |
| View        | `resources/views/search/person.blade.php`                                         | OK |
| Component   | `resources/views/components/home/trending-shelf.blade.php`                        | OK |
| View        | `resources/views/trending/index.blade.php`                                        | Present |
| Routes      | `routes/web.php` (`search.smart`, `search.autocomplete`, `search.image/vibe/person`, `trending.index`) | OK |
| Limiter     | `app/Providers/RouteServiceProvider.php` (`search`, `ai-batch`)                   | OK |

---

## Critical-checks scorecard

| Check                                                                | Status | Notes |
| -------------------------------------------------------------------- | :----: | ----- |
| Smart-search bar mounted in header                                   | Partial | Mounted **only inside `@auth`** at `header.blade.php:124`. Guests get no search affordance at all in the header — they have to know the `/search` URL. |
| Intent classifier routes to correct backend                          | Yes | `SmartSearchController::dispatchIntent()` switch maps `actor` / `director` → `DirectorActorSearchService`, `vibe` → `DecadeStyleSearchService`, `year` → year-bounded Movie query, `genre` → `Genre`-matched Movie query, `title`/default → `FilmKnowledgeService::searchRelevant()`. Empty-result fallback always runs a keyword search so the page is never blank. |
| Autocomplete returns results                                         | Yes | `GET /api/search/autocomplete?q=…` returns `{movies:[…], people:[…]}`. Pure DB LIKE, no AI, capped 10+10. Wired correctly to the Alpine `smartSearchBar` fetch loop with 250ms debounce + AbortController. |
| Image search accepts upload + queries Gemini                         | Yes | `POST /search/image` validates `image` (jpeg/jpg/png/webp, ≤8 MB), base64s the bytes, calls `ImageSearchService::searchByImage()` which talks to Google `generativelanguage.googleapis.com` via SsrfGuard + 45s timeout. Form auto-submits on file pick. |
| Vibe + person search render                                          | Yes | Both forms POST to their respective controllers, render the same view with `submitted=true`, `$movies` (Collection of arrays), and friendly empty states. Suggestion chips submit the form via `name="vibe"`/`name="name"` buttons. |
| Trending shelf reachable on home                                     | Yes | `resources/views/main.blade.php:25` renders `<x-home.trending-shelf window="24h" :limit="12" />`. Component self-hides when the cache is empty. Standalone page `/trending` (`trending.index` route) renders `trending.index.blade.php` with all four windows. |
| Legacy Livewire still working OR removed cleanly                     | **No** | Two legacy components exist (`App\Http\Livewire\SearchVelflix`, `App\Livewire\SearchFlik`) + two Blade partials. **Zero call sites in the codebase** (grep for `livewire:search-velflix`, `livewire:search-flik`, `@livewire('search…)` → no matches). Worse: the older `SearchVelflix::render()` has a bug — `strlen($this->searchVelflix >= 3)` parses as `strlen(bool)` (always 1), so the conditional is permanently truthy and the LIKE fires on every keystroke including empty. The newer `SearchFlik` fixes the bug but is also unmounted. Net: delete both. |

---

## Findings

### F-1 — Legacy Livewire search left as orphan + has a real bug (HIGH)

- `app/Http/Livewire/SearchVelflix.php:23` —
  `if (strlen($this->searchVelflix >= 3))`. The `>= 3` binds to
  `$this->searchVelflix` (string), producing a bool, which `strlen()` coerces
  to `"1"` → length always 1 → condition always true. The same bug is
  mirrored in `resources/views/livewire/search-velflix.blade.php:5`.
- Neither component is mounted anywhere — searched `resources/`, `app/`,
  and route definitions; the only references are the component class +
  view files themselves and the docs.
- `app/Livewire/SearchFlik.php` is the post-rename copy (fixes the bug,
  pulls `poster_url`) but is also unmounted.
- **Risk if rediscovered:** the broken component will throw / leak movies
  on first keystroke; the working copy duplicates `smart-bar`'s job
  without intent classification, autocomplete keying, abort handling, or
  RateLimiter coverage.
- **Recommendation:** delete all four files (`app/Http/Livewire/SearchVelflix.php`,
  `app/Livewire/SearchFlik.php`, `resources/views/livewire/search-velflix.blade.php`,
  `resources/views/livewire/search-flik.blade.php`). Smart-bar is the
  canonical entry. Update `tests/cypress/support/routes.json` if it
  still references either component (grep flagged it).

### F-2 — Smart search hidden from guests (MEDIUM)

- `resources/views/components/header.blade.php:124` wraps
  `<x-search.smart-bar />` inside an `@auth … @endauth` block. Guests
  have **no search affordance** in the chrome.
- The underlying routes are also `auth`-gated (lines 1024+, 1080+, 1090+
  in `routes/web.php`) so even if the bar were exposed, guests would
  bounce to login on submit. Net effect: a brand-new visitor cannot
  search the catalog at all, which conflicts with the discovery brand
  goal.
- **Recommendation (pick one):**
  1. Move `<x-search.smart-bar />` and the `search.*` routes out of
     `auth` middleware, gate only the *AI-expensive* paths (`/search/image`,
     `/search/vibe`, `/search/person`, autocomplete) per the existing
     `ai-batch`/`search` throttle config.
  2. If guest-search isn't on the roadmap, render a "Login to search"
     pill in the `@else` branch so the chrome doesn't silently lose
     the feature.

### F-3 — `intent=auto` chip always renders inactive (LOW)

- `resources/views/search/smart.blade.php:54` —
  `$active = $intent === $key || ($key === 'auto' && false);`. The
  literal `false` short-circuits the second clause, so the "Auto" chip
  never highlights even when the user used the default route (no
  `intent` param). The actual `$intent` then arrives as `title`
  (default classifier output for unmatched queries), so the **Judul**
  chip pulses active instead — confusing UX.
- **Fix:** track whether the request had an `intent` query param
  (e.g. `$intentExplicit = $request->has('intent')`) and pass it to the
  view so `auto` lights up when no override was applied.

### F-4 — Genre intent shadows keyword in title (MEDIUM)

- `SmartSearchController::resolveGenres()` uses
  `str_contains($needle, $name) || str_contains($name, $needle)` —
  the **bidirectional** match means a 4-letter genre like `Drama`
  swallows queries such as `"dramaland"` or `"drama2010"`. Worse, the
  fallback also runs `LIKE '%…%'` which can grab the same row twice.
- Combined with `IntentClassifier::heuristic()` (when the AI is
  unavailable) — a query like `"comedy central"` will be tagged `genre`
  by the heuristic, then matched into the broad Comedy bucket and the
  user never gets a fallback title search because the genre branch
  returned non-empty.
- **Fix:** require whole-word match (`preg_match('/\b' . preg_quote($name) . '\b/iu', $needle)`)
  and drop the `str_contains($name, $needle)` direction.

### F-5 — Director / image AI suggestions bypass the anti-hallucination guard (MEDIUM)

- `ImageSearchService::searchByImage()` and
  `DirectorActorSearchService::searchByDirector()` both call
  `FilmKnowledgeService::findClosestByTitle()` (fuzzy) and fall through
  to `findByTitle()` (exact). Neither layer applies a **similarity
  floor** — a Gemini guess of `"The Godfather"` against a catalog with
  only `"The Godfather Part II"` returns Part II as a "match", and the
  card renders without any caveat unless the AI also stamped a low
  `_ai_confidence`.
- The smart-search title path is fine because it uses
  `FilmKnowledgeService::searchRelevant()` (keyword-scored across
  several fields).
- **Fix:** in `ImageSearchService` and the director branch, require
  `findClosestByTitle()` to return a movie whose Levenshtein /
  normalized-distance score crosses a threshold; otherwise drop the
  candidate or surface it as "AI guess, not in catalog".

### F-6 — `IntentClassifier::heuristic()` mis-buckets bare year input (LOW)

- The regex `/^\s*(19|20)?\d{2}(?:\s*-?(?:an|s|en))?\s*$/u` matches
  `"91"` (would coerce to year 91 in `extractYear` later, which would
  fail) and `"01"` (same). The downstream `SmartSearchController::extractYear()`
  only picks 4-digit forms or `\d0s/an/en`, so two-digit single-year
  inputs land in the `year` bucket but produce **no result** — the
  controller then runs the fallback keyword search anyway, but the
  "AI detected: year" badge is misleading.
- **Fix:** tighten the heuristic regex to require 4 digits OR the
  decade suffix, never both-absent.

### F-7 — Header search-bar Alpine `init()` is a no-op (LOW / cosmetic)

- `smart-bar.blade.php:193-195` — `init()` is declared but empty
  (only a comment). Alpine will still call it; harmless but worth
  either removing or implementing the "restore from URL hash" path
  the comment hints at.

### F-8 — Autocomplete LIKE is unbounded on huge catalogs (LOW)

- `SmartSearchController::autocomplete()` runs two `LIKE '%…%'` queries
  with no covering index. With ~5k movies this is fine; once the
  catalog grows past ~50k the suggestion latency will become visible
  at the 250 ms debounce.
- **Fix when needed:** add a FULLTEXT index on `movies(title, original_title)`
  and switch to `MATCH … AGAINST` for the autocomplete path. Cast
  search can stay LIKE (small table).

### F-9 — `intent` override input is a free-form select on the smart page only (LOW)

- The override chips on `search/smart.blade.php` build URLs with
  `&intent={key}`. The controller validates `intent` against
  `title|actor|director|vibe|year|genre|auto`, so this is safe — but
  the smart-bar component itself does not surface the override chips,
  meaning header-bar users can only ever land in `auto`. That's fine
  (the override is meant as a "re-search" affordance) — flagging it
  here only to make the design intent explicit for the next visitor.

---

## Routing summary

All routes live inside `Route::middleware('auth')->group(...)` at
`routes/web.php:1024`:

| Method | Path                              | Name                  | Limiter      |
| ------ | --------------------------------- | --------------------- | ------------ |
| GET    | `/search`                         | `search.smart`        | `throttle:search` (60/min/user) |
| GET    | `/api/search/autocomplete`        | `search.autocomplete` | `throttle:search` |
| GET    | `/search/image`                   | `search.image.form`   | — (form render) |
| POST   | `/search/image`                   | `search.image`        | `throttle:ai-batch` |
| GET    | `/search/vibe`                    | `search.vibe.form`    | — |
| POST   | `/search/vibe`                    | `search.vibe`         | `throttle:ai-batch` |
| GET    | `/search/person`                  | `search.person.form`  | — |
| POST   | `/search/person`                  | `search.person`       | `throttle:ai-batch` |
| GET    | `/trending`                       | `trending.index`      | — (public, line 16) |

The `RateLimiter::for('search', ...)` (RouteServiceProvider:124) keys on
`auth()->id() ?? ip()`. Combined with controller-level
`RateLimiter::tooManyAttempts('smart-search:…', 30, 60)` in
`SmartSearchController` and `'search-image|vibe|person:…', 15, 60` in
`AdvancedSearchController`, both layers fire — the route throttle is a
60/min ceiling and the controller throttle is a 30/min (smart) /
15/min (advanced) inner cap. Redundant but harmless.

## Defense-in-depth notes

- **Validation**: all controllers use Laravel `validate()` with
  `min/max/in` constraints; intent override is constrained to the valid
  bucket list before reaching the dispatcher.
- **CSRF**: image/vibe/person forms include `@csrf` (verified in views).
- **SSRF**: `ImageSearchService` wraps Gemini calls in `SsrfGuard::assertUrlAllowed()`
  and limits redirects to 3.
- **Upload safety**: image route validates `file|image|mimes:jpeg,jpg,png,webp|max:8192` (KB).
- **AI grounding**: classifier output is whitelisted (`VALID_INTENTS`)
  and confidence is clamped to `[0, 1]`. JSON parsing strips code fences
  defensively.
- **Graceful degradation**: every AI call has a heuristic fallback
  (`IntentClassifier::heuristic()`, `DecadeStyleSearchService::heuristicVibe()`).
  `ImageSearchService` returns an empty collection when no Google
  provider is configured rather than throwing — view renders the
  "tidak ada match" empty state.

## Suggested cleanup PR (one commit)

1. Delete `app/Http/Livewire/SearchVelflix.php`, `app/Livewire/SearchFlik.php`,
   `resources/views/livewire/search-velflix.blade.php`,
   `resources/views/livewire/search-flik.blade.php` (F-1).
2. Decide guest reach for search; either move the smart-bar out of
   `@auth` + relax route middleware on the cheap endpoints, or render
   a login pill in the `@else` branch (F-2).
3. Fix the `auto` chip predicate in `search/smart.blade.php` (F-3).
4. Tighten `resolveGenres()` and `IntentClassifier::heuristic()` regex
   matching (F-4, F-6).
5. Add a similarity floor in `ImageSearchService` and the director
   branch of `DirectorActorSearchService` (F-5).

Items 1, 3 are mechanical. Item 2 is a product decision. Items 4, 5, 6
are quality-of-result improvements that won't break anything by
shipping incrementally.
