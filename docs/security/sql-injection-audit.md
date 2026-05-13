# SQL Injection Audit — `app/`

**Date:** 2026-05-13
**Scope:** Every `DB::raw()`, `whereRaw()`, `havingRaw()`, `selectRaw()`,
`orderByRaw()`, `groupByRaw()`, `Schema::raw()` call site under `app/`.
**Result:** 0 VULNERABLE call sites. 1 PARAMETERIZED. 1 SAFE-but-borderline
(allowlist-controlled column interpolation). All others are static SQL
strings with no user input.

---

## Re-running the audit

```bash
# Primary scan — every raw-SQL call shape Laravel exposes.
grep -rn -E 'DB::raw\(|whereRaw\(|havingRaw\(|selectRaw\(|orderByRaw\(|groupByRaw\(|Schema::raw\(' app/

# Secondary scan — full-string SQL execution, in case anyone added one.
grep -rn -E 'DB::statement\(|DB::select\(|DB::insert\(|DB::update\(|DB::delete\(|DB::unprepared\(' app/

# Tertiary — sort/order column names plumbed straight from request input.
grep -rn -E '->orderBy\(\s*\$request|->groupBy\(\s*\$request' app/
```

A reviewer should also re-check `routes/web.php` (closure routes can hide
ad-hoc raw queries) and any new `app/Services/Analytics/*` or
`app/Http/Controllers/Admin/*` controllers added since this audit.

---

## Classification legend

- **SAFE** — only static SQL string literals; no `$variable` interpolation
  and no `.` concatenation with anything mutable.
- **PARAMETERIZED** — uses the second-arg bindings array
  (`whereRaw('col = ?', [$value])`).
- **SAFE-ALLOWLIST** — interpolates a value into the SQL string, but the
  value provably comes from a hardcoded allowlist (not user input).
- **VULNERABLE** — string interpolation / concatenation of request or
  model input into the SQL fragment. (None found.)

---

## Findings

### `app/Services/Performance/PerformanceMonitor.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 48–51   | `DB::raw('COUNT(*) as calls')` etc. | SAFE | Pure aggregate literals on `ai_usage_logs`. |
| 164     | `DB::raw('COUNT(*) as pending')` | SAFE | Aggregate literal on `jobs`. |

### `app/Services/Analytics/AbTestFramework.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 181–183 | `selectRaw('COUNT(*) AS assigned')` etc. | SAFE | Static aggregates. |

### `app/Services/Analytics/RevenueAnalytics.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 262–264 | `DB::raw('DATE(paid_at) as day')` etc. | SAFE | Static aggregates. |
| 274–275 | `DB::raw('DATE(created_at) as day')` etc. | SAFE | Static aggregates. |
| 284–285 | `DB::raw('DATE(created_at) as day')` etc. | SAFE | Static aggregates. |

### `app/Services/Analytics/GeoAnalytics.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 98      | `DB::raw('SUM(amount) as total')` | SAFE | Static aggregate. |
| 164     | `DB::raw('COUNT(*) as n')` | SAFE | Static aggregate. |
| 259     | `DB::raw('MAX(id) as latest_id')` | SAFE | Static aggregate. |
| 298     | `DB::raw('MAX(id) as latest_id')` | SAFE | Static aggregate. |

### `app/Services/Ai/FilmKnowledgeService.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 145     | `selectRaw('MIN(YEAR(release_date)) as min_year, …')` | SAFE | Static aggregate. |
| 189–190 | `whereRaw('LOWER(title) = ?', [$needle])`, `orWhereRaw('LOWER(original_title) = ?', [$needle])` | PARAMETERIZED | `$needle` arrives via `?` binding. Correct form. |

### `app/Services/Ai/Tasks/DailyAdminReportGenerator.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 118     | `DB::raw('COUNT(*) as cnt')`, `DB::raw('COALESCE(SUM(amount),0) as revenue')` | SAFE | Static aggregates. |
| 182–183 | `DB::raw('COUNT(DISTINCT user_id) as views')`, `DB::raw('AVG(CASE … ELSE 0 END) as avg_progress')` | SAFE | Static aggregates. |

### `app/Services/Ai/Tasks/ContentGapAnalyzer.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 114     | `DB::raw('COUNT(genre_movie.movie_id) as cnt')` | SAFE | Static. |
| 141     | `DB::raw('COUNT(DISTINCT movie_id) as cnt')` | SAFE | Static. |
| 164     | `select($countryColumn.' as code', DB::raw('COUNT(*) as cnt'))` | **SAFE-ALLOWLIST** | `$countryColumn` is the return value of `detectMovieCountryColumn()` (line 225), which only ever returns one of `country`, `country_code`, `origin_country`, `production_country`. Not user-controlled. The same value is also passed through `Schema::hasColumn()` before being returned. Left as-is. |
| 257     | `DB::raw('COUNT(*) as views')` | SAFE | Static. |
| 281–282 | `DB::raw('COUNT(*) as ratings_count')`, `DB::raw('AVG(ratings.score) as avg_score')` | SAFE | Static aggregates. |

### `app/Services/Ai/Tasks/YearInReviewGenerator.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 135     | `DB::raw('COUNT(DISTINCT genre_movie.movie_id) as cnt')` | SAFE | Static. |
| 160     | `DB::raw('COUNT(DISTINCT cast_movie.movie_id) as cnt')` | SAFE | Static. |

### `app/Models/QuizAttempt.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 62–63   | `selectRaw('MAX(score) as best_score')`, `selectRaw('MIN(time_seconds) as best_time')` | SAFE | Static. |

### `app/Http/Controllers/QuizController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 219     | `selectRaw('u.id as user_id, u.name, MAX(qa.score) as best_score, …')` | SAFE | Static SELECT list. `$movieId` is bound by a separate `where('qa.movie_id', $movieId)` call, not interpolated. |
| 246     | `havingRaw('MAX(score) > ?', [$myBest])` | PARAMETERIZED | `$myBest` is cast to `(int)` before binding, and arrives through `?`. Correct form. |

### `app/Http/Controllers/SmartWatchlistController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 33      | `selectRaw('COALESCE(MAX(updated_at), MAX(created_at)) AS sig, COUNT(*) AS c')` | SAFE | Static. `user_id` is bound via the preceding `where()`. |

### `app/Http/Controllers/Admin/AiUsageController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 26      | `selectRaw('COUNT(*) as call_count, SUM(input_tokens) as input_tokens, …')` | SAFE | Multi-line static aggregate string. |
| 66–70   | `DB::raw('COUNT(ai_usage_logs.id) as call_count')` etc. | SAFE | Static. |
| 89–94   | `DB::raw('COUNT(*) as call_count')` etc. | SAFE | Static. |
| 110–112 | `DB::raw('DATE(created_at) as day')` etc. | SAFE | Static. |

### `app/Http/Controllers/Admin/ChurnDashboardController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 79–84   | `selectRaw('COUNT(*) AS total')` and four risk-level CASE bucket strings | SAFE | All literal SQL, no interpolation. |

### `app/Http/Controllers/Admin/AuditLogController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 45      | `selectRaw("DISTINCT SUBSTRING_INDEX(action, '.', 1) AS prefix")` | SAFE | Static. No interpolation. |

### `app/Http/Controllers/Admin/SentimentDashboardController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 70–76, 117–122, 152–157, 194–200 | `selectRaw(...)` and `DB::raw(...)` for COUNT/CASE/AVG aggregates | SAFE | All literal. `movie_id` is filtered by a separate parameterised `where()`. |
| 111     | `orderByDesc(DB::raw('COUNT(comments.id)'))` | SAFE | Static aggregate. |

### `app/Http/Controllers/Admin/RevenueDashboardController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 117     | `selectRaw('subscription_plans.slug as slug, COUNT(*) as cnt')` | SAFE | Static. |

### `app/Http/Controllers/Admin/GeoDistributionController.php`

| Line(s) | Form | Classification | Notes |
|---|---|---|---|
| 93, 124 | `DB::raw('MAX(id) as latest_id')` | SAFE | Static. |
| 161     | `DB::raw('COUNT(*) as n')` | SAFE | Static. |
| 178     | `DB::raw('SUM(amount) as total')` | SAFE | Static. |

---

## Summary

- **Total raw-SQL call sites scanned:** 67
- **VULNERABLE:** 0
- **PARAMETERIZED:** 3 (`FilmKnowledgeService.php:189`, `:190`, `QuizController.php:246`)
- **SAFE (static literals):** 63
- **SAFE-ALLOWLIST (hardcoded interpolation, no user input):** 1 (`ContentGapAnalyzer.php:164`)

No edits were required to fix vulnerabilities.

`DB::statement` / `DB::unprepared` / `DB::select` / `DB::insert` /
`DB::update` / `DB::delete`: zero matches under `app/`. `routes/web.php`
also contains zero raw-SQL calls.

---

## Defensive helper

`app/Support/SqlSafe.php` was added so that any future code that needs
to interpolate a sort-column or table identifier from request input has
a centralised, hard-to-misuse helper.

```php
use App\Support\SqlSafe;

$sortable = ['created_at', 'updated_at', 'title', 'popularity'];
$column   = SqlSafe::columnAllowlist($sortable, $request->input('sort'), 'created_at');
$dir      = SqlSafe::direction($request->input('dir'), 'desc');

Movie::query()->orderBy($column, $dir)->paginate();
```

The class has three methods:

- `columnAllowlist(array $allowed, mixed $candidate, string $default): string`
  — strict in-array check, plus a defence-in-depth regex on the
  allowed value itself so a future caller can't accidentally smuggle a
  comma-separated list through the allowlist.
- `direction(mixed $candidate, string $default = 'asc'): string`
  — normalises to `'asc'` or `'desc'`.
- `identifier(mixed $candidate): ?string`
  — bare-identifier validator (no dotted form).

When you need to use it in a place where the column is currently fine
because there's no user input — but you want to make that property
*explicit* — leave a `// SECURITY-AUDIT: <reason>` comment and reference
this document.

---

## Unresolved findings

None. All raw-SQL call sites are accounted for.

If new raw-SQL is added in the future, add a row to the relevant table
above (or create a new section), re-run the grep commands at the top of
this document, and update the summary counts.
