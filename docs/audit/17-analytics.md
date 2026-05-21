# Audit 17 — Analytics Dashboards

**Scope:** Revenue (MRR/ARR/ARPU/LTV), Geo distribution, User cohort retention, Engagement funnel, Churn risk, Performance (AI latency p95), Content gap, Pricing optimizer, A/B test framework, AI usage spend tracker, Sentiment dashboard.
**Date:** 2026-05-20
**Branch:** `main`
**Verdict:** PARTIAL — 8 of 11 dashboards render correctly. **3 release-blocking bugs** make Funnel, Cohort CSV export, and the entire A/B test surface 500/UnknownColumnException. The analytics layer's heavy lifting (RevenueAnalytics, CohortAnalyzer, GeoAnalytics, PerformanceMonitor, ContentGapAnalyzer, PricingOptimizer, ChurnPredictor) is well-architected and defensive; the failures are in the glue (controller ↔ service / model ↔ schema name mismatches).

---

## Sidebar reachability check

All 11 dashboards are wired into `config/admin_menu.php` and gated behind `analytics.*` permissions defined in `database/seeders/RolePermissionSeeder.php` (lines 66-72) and assigned to the `admin` role (lines 143-145). Sidebar links resolve to named routes that all exist in `routes/web.php:783-808`. The `Reports / AI Insights` group surfaces `admin.insights.content-gap` and `admin.insights.pricing`; the `Analytics` group surfaces Revenue / Geo / Cohort / Funnel / A/B / Churn / Performance. AI Usage and Sentiment live in the AI / Ops group.

**Reachability verdict:** PASS for all 11.

---

## 1. Revenue Dashboard (`/admin/revenue`)

### Renders on empty DB?
- **YES.** Every aggregate in `RevenueAnalytics::*` is wrapped in `try/catch` with `Log::warning` and a 0.0 fallback (`RevenueAnalytics.php:76`, `:160`, `:200`, etc.). When `subscriptions` / `subscription_plans` tables are present but empty, every KPI shows zero and `latestSubscriptions` is an empty collection — the view renders the "No paid subscriptions yet" placeholder (`revenue/dashboard.blade.php:170`).
- The controller `RevenueDashboardController::buildPayload()` additionally wraps each service call in `safe()` (lines 211-218), so even a `Throwable` inside the analytics layer degrades silently.

### Numbers correct?
- **YES.** `mrr()` normalises monthly / yearly / quarterly / weekly billing cycles into a per-month figure (`RevenueAnalytics.php:380-386`) — so a mixed plan portfolio sums correctly.
- `ltv() = arpu() ÷ monthly_churn_rate` with a `MIN_MONTHLY_CHURN = 0.005` floor (line 37) — prevents the "single retained cohort → LTV = ∞" bug.
- `globalMonthlyChurnRate()` normalises a 90-day window to a 30-day-equivalent rate (line 446) — correct math.
- `revenueTimeline(90)` builds a per-day map using `paid_at` (falling back to `created_at` for legacy rows) and recomputes an MRR snapshot per day (`mrrSnapshotAt`) — cheap and accurate.

### Chart fixed (no infinite vertical growth)?
- **YES.** `revenue/dashboard.blade.php:104-124` wraps each canvas in a parent `<div>` with explicit `height:340px;display:flex;flex-direction:column` and the canvas wrapper sets `position:relative;flex:1;min-height:0`. Chart.js init uses `responsive:true, maintainAspectRatio:false`. This is the canonical fix for the "Chart.js doubles in height every render" bug. Verified.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| R-1 | LOW | `stats['revenue_last_month']` is hard-coded to `0.0` (controller line 172) and never populated; the view's "vs last month" delta compares this-month-new vs this-month-churn rather than this-month vs prev-month. The label is therefore misleading. | `RevenueDashboardController.php:172`, `revenue/dashboard.blade.php:82` |
| R-2 | LOW | `ltvByPlan[*].churn_rate` is computed as `arpu / ltv * 100` (controller line 145) — a circular re-derivation that produces `1 / max(churn, MIN)` × 100 = the global floor, not a per-plan rate. Useful as a placeholder; not actually per-plan. | `RevenueDashboardController.php:145` |
| R-3 | INFO | Cache key is `admin:revenue:dashboard:v2` (10-min TTL). Hot-cache busts on `?refresh=1`. Working as designed. |  |

---

## 2. Geo Distribution (`/admin/geo`)

### `array_column` empty-array fix verified?
- **YES.** `resources/views/admin/geo/distribution.blade.php:7-9` wraps every `array_column()` in `array_merge(..., [1])` before passing to `max()` so the historical "max() of empty array returns the array, not the scalar fallback" bug cannot recur. Explicitly commented as such (line 6).
- Controller `GeoDistributionController::computeDistribution` uses `array_sum(array_column(...))` for totals (lines 203-205) — `array_sum` of an empty array is `0`, so that path is safe.

### Renders on empty DB?
- **YES.** When `drm_sessions` and `audit_logs` are absent or empty, `userCountry` is `[]`; per-country rollups skip; KPIs all show 0; tables show the empty body. No 500.
- Cache key `geo:distribution:v1` with 6 h TTL (`GeoDistributionController.php:38-40`).

### Resolver precedence working?
- **YES.** Order: `drm_sessions.country_code` (cached) → `drm_sessions.client_ip` (resolved via `GeoIpResolver`) → `audit_logs.client_ip`. Users already attributed via DRM are skipped on the audit-log pass (line 137). Hard cap of 5000 users per render (line 49) keeps first-paint snappy.
- `Service\Analytics\GeoAnalytics::resolveUserCountries` is the same logic plus an optional `users.last_known_country` / `users.registered_ip` precedence layer (lines 218-249). Both implementations exist (slightly duplicated — see G-1 below).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| G-1 | LOW | The controller (`GeoDistributionController::computeDistribution`) re-implements country resolution inline instead of delegating to `App\Services\Analytics\GeoAnalytics::resolveUserCountries`. Two slightly different precedence chains exist (the controller doesn't honour `users.last_known_country` / `users.registered_ip` even if a future migration adds them). Either delete the inline logic and inject the service, or delete the unused `GeoAnalytics` class. | `GeoDistributionController.php:84-189`, `Services/Analytics/GeoAnalytics.php:208-326` |
| G-2 | LOW | When GeoIP DB is missing (`config/geoip.php` / no MaxMind file) `GeoIpResolver::country` returns `null` for every IP, and the controller will show zero resolved users + no country rows — no error, but the dashboard looks blank. No "geoip-unavailable" notice in the view. | `geo/distribution.blade.php` |
| G-3 | INFO | The country-name map covers ~55 countries focused on the Indonesian market (lines 287-347). Unknown codes fall back to the raw code — never a blank cell. |  |

---

## 3. Cohort Retention (`/admin/cohorts`)

### Reachable & renders?
- **YES** for `/admin/cohorts` (`?type=weekly` default, `?type=monthly` supported). Cached 6 h. `?refresh=1` busts. AI insight cached separately under `admin.cohorts.insight.<type>.v1`.
- `CohortAnalyzer::weeklyCohorts(12)` / `monthlyCohorts(6)` produce a stable shape regardless of DB content. `emptyMatrix()` (`CohortAnalyzer.php:332`) is invoked when zero users exist — the view receives a populated grid of zero-pct cells instead of a `null` payload.

### Matrix populates with real data?
- **YES (verified).** Bucketing is done in PHP (`Carbon::startOfWeek/startOfMonth`) so the analyzer is portable across SQLite/MySQL/Postgres without driver-specific date functions. Period index is capped to `weeksBack - 1` and future periods get `pct:null` (rendered as blank cells, not a misleading 0%) — `CohortAnalyzer.php:287-295`.
- Heatmap colour ramp in `cohorts/dashboard.blade.php:42-58` interpolates from `#1a1300` to `#C5A55A` with a 0.75 gamma — readable across the full range.

### Churn-by-cohort method present?
- **YES.** `CohortAnalyzer::churnByCohort()` (lines 400-475) flags a user "churned" when EITHER inactive for ≥ 14 days OR holding only expired subscriptions. Defensive on missing `subscriptions` table (line 522). Returned but **NOT called by the dashboard controller** — only exposed via the service for programmatic callers. The cohort dashboard view does not surface churn-per-cohort today.

### CSV export
- **BROKEN.** Route `cohorts.export` at `routes/web.php:791` maps to `[CohortDashboardController::class, 'export']` — **that method does not exist on the controller**. The matching protected helper is `exportCsv()` and the public path is `index(...)` with `?export=csv`. Hitting `/admin/cohorts/export.csv` throws `BadMethodCallException: Method App\Http\Controllers\Admin\CohortDashboardController::export does not exist`. (The header links at `?export=csv` work because they hit the `index` route.)

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| C-1 | **BLOCKER (low blast radius)** | `routes/web.php:791` registers `cohorts.export` → `'export'` method, which does not exist. Anyone clicking the named route or anything that uses `route('admin.cohorts.export')` will get a 500. Header export button uses `?export=csv` so the standard UX path works — but the named route is dead. **Fix:** either add a public `export()` proxy method, change the route to `'index'`, or remove the duplicate route. | `routes/web.php:791`, `Admin/CohortDashboardController.php:39,136` |
| C-2 | LOW | `aiInsight()` lives on `CohortAnalyzer` (lines 593-676) AND duplicates in `App\Services\Ai\Tasks\CohortInsightGenerator::generate()`. The controller injects the latter; the former is dead code. | `CohortAnalyzer.php:593`, `Ai/Tasks/CohortInsightGenerator.php` |
| C-3 | INFO | `assemble()` returns rows newest-first (line 325). Comment on line 41 notes this; matches the view's natural top-down read order. |  |

---

## 4. Engagement Funnel (`/admin/funnel`)

### Reachable & renders?
- **NO. RELEASE-BLOCKING BUG.**
- `FunnelDashboardController::index` calls `$this->analyzer->engagementFunnel($days)` (`FunnelDashboardController.php:34`). **`engagementFunnel` does not exist on `FunnelAnalyzer`.** The public methods are `signupToSubscribed(int $days)` and `dropoffByStage(int $days)` — that's it. Result: every request to `/admin/funnel` throws `BadMethodCallException: Method App\Services\Analytics\FunnelAnalyzer::engagementFunnel does not exist`.
- Even if the method name were fixed, the view consumes shape keys (`percent_from_previous`, `percent_from_top`) that the service does not produce — it returns `conversion_pct` and `from_top_pct`. The `?? 0` and `?? $pct` fallbacks in the view (`funnel/dashboard.blade.php:42`) would mask the breakage but render misleading "0% step" labels next to real counts. The drop-off alert builder in the controller (`FunnelDashboardController.php:38-54`) reads `percent_from_previous` too and would always produce zero alerts even if the route worked.

### Drop-off alerts?
- Logic is wired (alert when `100 - percent_from_previous > 50`) but unreachable until the method-name and key-shape bugs are fixed.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| F-1 | **BLOCKER** | `FunnelDashboardController::index` calls a non-existent `FunnelAnalyzer::engagementFunnel($days)`. Every `/admin/funnel` request 500s. **Fix:** rename the call to `signupToSubscribed($days)`. | `FunnelDashboardController.php:34`, `FunnelAnalyzer.php:74` |
| F-2 | **BLOCKER (paired)** | The view (`funnel/dashboard.blade.php:42`) and the alert loop (`FunnelDashboardController.php:44,46`) reference `percent_from_previous` / `percent_from_top`, but `FunnelAnalyzer::annotateRatios()` emits `conversion_pct` and `from_top_pct`. **Fix:** rename in BOTH places (controller + view), OR add aliases in the service so both shape conventions resolve. | `FunnelAnalyzer.php:226-251`, `FunnelDashboardController.php:38-54`, `funnel/dashboard.blade.php:42` |
| F-3 | LOW | Once fixed, drop-off alerts only fire when the loss is > 50% step-to-step. The alert wording in the view also doesn't surface the prebuilt service helper `FunnelAnalyzer::dropoffByStage()` which returns the SINGLE biggest drop — a more focused signal than the controller's own scan. Optional improvement: render `dropoffByStage()` payload as the headline alert. | |

---

## 5. Churn Dashboard (`/admin/churn`)

### Reachable & renders?
- **YES.** `ChurnDashboardController::index` reads only from `churn_predictions`. On a fresh install with zero predictions, `computeStats()` (lines 76-104) returns all-zero counts; `predictions` is an empty collection; the view's `@if($predictions->isEmpty())` block (`churn/dashboard.blade.php:193`) renders an empty-state. No 500.
- Risk-level filter via `?level=low|medium|high|critical` validated against `ChurnPrediction::LEVELS` (lines 209-216).

### Risk-segmented users?
- **YES.** Default view excludes `low` risk users (only medium/high/critical) — line 124. Sorted by `risk_score` desc (`scopeOrderedByRisk`). Table limit 50; CSV export limit 5000.
- CSV export at `?export=csv` streams via `chunkById(500)` with UTF-8 BOM (lines 156-203). Includes signal columns so retention ops have raw data to act on.

### Predictor wiring
- `ChurnPredictor` (App\Services\Ai\Tasks\ChurnPredictor) is well-structured. Five weighted signals (0.30+0.25+0.20+0.10+0.15=1.0) with a saturation cap on `days_since_last_watch` so a long-tail of cold users doesn't dominate. AI suggestion only invoked for `risk_score >= 0.6` and falls back to a deterministic template (`fallbackAction()` lines 456-477) on AI failure.
- `ChurnPrediction::$guarded = ['*']` and the predictor uses `forceFill` — correct, prevents users from POSTing a self-rated low risk.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| Ch-1 | LOW | `subscriptionSignal()` computes `expiresInDays` via `$now->diffInDays($endsAt, false)` (line 283). On Carbon ≥ 3 (Laravel 12 default), `diffInDays` returns a float; the sign convention is correct (`false` disables abs) but the rounded int comparison `>= 0 && <= 7` works as intended. Verified. |  |
| Ch-2 | LOW | `predictAll()` chunks `users()->whereHas('subscriptions')` (lines 112-126). For users with multiple stale subscription rows the heuristic uses `latest('id')` (line 273) which is correct, but the predictor will re-score every subscriber every run regardless of whether their signals changed — wastes AI quota on suggestions. Consider a "skip if `computed_at` < 24h" guard before the AI call. | `ChurnPredictor.php:81-101` |
| Ch-3 | INFO | "Last run" indicator pulls `ChurnPrediction::max('computed_at')` so the dashboard surfaces freshness. Good. | `ChurnDashboardController.php:52` |

---

## 6. Performance Dashboard (`/admin/performance`)

### Reachable & renders on empty DB?
- **YES.** Every monitor method degrades gracefully:
  - `aiServiceStats()` returns `totals: ['calls'=>0,...]` and empty `per_task` when `ai_usage_logs` is empty.
  - `queueLag()` includes the four logical FLiK queues (`default`, `transcoding`, `ai-realtime`, `ai-batch`) with `pending:0/health:green` even when `jobs` table is empty (`PerformanceMonitor.php:184-195`).
  - `cacheStats()` runs 5 set/get/forget round-trips and reports avg/p95 latencies. Catches `Throwable` and sets `healthy:false` with an error string instead of throwing.
  - `dbStats()` enumerates a curated list of "hot" tables and silently skips any that don't exist (line 322). Avoids `information_schema` scans (some shared hosts deny privileges).
  - `slowQueries(20)` prefers `telescope_entries` if installed, otherwise tails the last 512 KB of `storage/logs/laravel.log` for slow-query markers, and falls back to a single `"Telescope or query log not enabled"` string.

### AI latency p95 surfaced?
- **YES.** Per-task p95 computed by `percentile()` (lines 429-438) using `sort()` + `ceil(p/100 * n) - 1` — capped at 5000 latency samples per task (line 74). Overall p95 from the union of all latencies (line 105). Health bands: green / yellow / red mapped from error rate AND p95 (lines 459-468) — `red` at `>=5% error OR p95 >= 10s`, `yellow` at `>=1% OR p95 >= 4s`.

### Polls live counts?
- **NO — cached snapshot only.** Controller caches the full payload for 5 min (`PerformanceDashboardController.php:24,42-54`). The view has no `setInterval` / Alpine `x-init` polling — admins must hit `?refresh=1` (which redirects via line 34) to bust. The task spec asked "polls live counts?" — the answer is **no, this is intentional**, server-side cached aggregate. If true live polling is required, the controller would need a separate JSON endpoint and the view would need an Alpine + `fetch()` loop.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| Pf-1 | LOW | `max(1, max(array_column($queue['per_queue'], 'pending')))` at `performance/dashboard.blade.php:34` works because the controller always seeds the four known queues, but it's still fragile to a `per_queue:[]` shape. If `queueLag()`'s `$known` seeding is ever removed, this line crashes with `max(): Argument #1 ($value) must contain at least one element`. Wrap with `array_merge(..., [0])` per the geo pattern. | `performance/dashboard.blade.php:34` |
| Pf-2 | INFO | Five-second cache-stats probe runs on every render — fine for an admin endpoint but adds 5 round-trips to the cache backend. Cached-payload TTL is 5 min so the probe runs at most once per 5 min per user. |  |
| Pf-3 | INFO | No "auto-refresh every 30 s" toggle. If live monitoring is a goal, add `?live=1` that injects a meta refresh or Alpine poll. |  |

---

## 7. AI Usage (`/admin/ai-usage`)

### Reachable & renders?
- **YES.** `AiUsageController::index` reads `ai_usage_logs` + `ai_providers`. Empty DB → all stats zero; sparkline shows 14 days of zero bars; tables empty.

### 14-day sparkline?
- **YES.** Daily spend computed for the last 14 days via `Cache::query` group-by-`DATE(created_at)`, zero-filled by the controller loop (lines 119-129). View renders bars scaled to `max($daily, 'cost')` (`ai-usage/index.blade.php:4`).

### Per-provider + per-task breakdowns?
- **YES.** Per-provider uses LEFT JOIN on `ai_providers` so providers with zero usage still appear. Per-task is grouped by `task_type` (line 96). Recent 50 calls listed with provider eager-loaded.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| AU-1 | LOW | `max(array_column($daily, 'cost')) ?: 0.0001` in the view (line 4) protects against div-by-zero but `max()` of an empty array still throws — the controller's 14-iteration loop guarantees `$daily` is non-empty so it's safe in practice. Still, consistent with the geo guard would be `max(array_merge(..., [0.0001]))`. | `ai-usage/index.blade.php:4` |
| AU-2 | INFO | No CSV export. Consistent with the "Lite" framing, but adding one is a 20-line copy of the audit-log exporter. |  |

---

## 8. Sentiment Dashboard (`/admin/sentiment[/{movie?}]`)

### Reachable & renders on empty DB?
- **YES.** Every aggregate uses `selectRaw` with conditional sums — `null` rows are coalesced to 0 (`overallStats()`, lines 67-93). Trend is zero-filled across the 30-day window (`trendByDate()`, lines 207-222).
- Optional `?movie` route param is route-model-bound; `($movie && $movie->exists)` check (line 38) treats un-persisted bindings as "global" view.

### Per-movie + per-sentiment + recent examples?
- **YES.** `perMovieStats()` joins `movies` and groups by `movie_id`/`title`/`slug`, capped at 50 rows. `recentExamples()` returns last 10 positive + last 10 negative analysed comments (eager-loaded with user+movie).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| Se-1 | LOW | The dashboard depends on `comments.sentiment_analyzed_at` being populated by `AnalyzeCommentSentiment` job (CommentSentimentAnalyzer). If the AI provider is offline, the queue stalls and the dashboard shows declining coverage — there's no "queue lag" indicator on the page. |  |

---

## 9. AI Insights — Content Gap (`/admin/insights/content-gap`)

### AI actually generates output?
- **YES, when an AI provider is configured.** `ContentGapAnalyzer::analyze()` ALWAYS returns a populated payload — `catalog_stats`, `demand_stats`, `gap_signals` are pure SQL aggregations. The AI step is in its own `try/catch` (lines 82-89) so a failure populates `ai_error` but leaves the rest intact. The view (`insights/content-gap.blade.php:57-61`) renders a yellow "AI tidak tersedia" notice when `$aiError` is set.
- Recommendations are strict-JSON parsed via `parseRecommendations()` (lines 437-480) with priority clamped to `high|medium|low`, max 5 entries, and entries with empty `gap_description` and `recommendation` filtered out — robust against partial AI hallucinations.

### Schema feature-detection?
- **YES.** `detectMovieCountryColumn()` (lines 225-234) probes for `country` / `country_code` / `origin_country` / `production_country` and gracefully omits `by_country` when none exist (current schema has none — the CLAUDE.md notes this). Same for `movie_subtitles` table for `by_language`.

### Cached?
- 24 h cache via `admin.insights.content_gap.v1`. `?refresh=1` busts.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| IG-1 | LOW | `byDecadePortable()` chunks the entire `movies` table by 500 rows (lines 192-211) to compute decade buckets — fine at 10k films, gets slow at 1M. Consider a single `release_year >> 4` SQL group when on MySQL/Postgres. Documented as "small dataset for admin report" in the comment. | `ContentGapAnalyzer.php:185-219` |

---

## 10. AI Insights — Pricing Optimizer (`/admin/insights/pricing`)

### AI actually generates output?
- **YES, when configured.** Same pattern as content-gap: aggregate first, AI step in `try/catch`, `ai_error` field for partial degradation. Per-plan stats (`collectPlanStats`, lines 129-199) compute conversion %, early-churn % (cancellation within 90 days), LTV (= `avg(amount) × cycles_lived`), and optional `discount_signups_share` if a discount column exists (feature-detected).

### Sanity checks on AI recommendations?
- **YES.** `parseRecommendations` (lines 406-476):
  - Drops recommendations for non-existent plan slugs (filters hallucinated plans).
  - Clamps recommended price to `[current * 0.4, current * 1.6]` to prevent insane AI suggestions.
  - Validates `confidence ∈ {high, medium, low}`.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| Pr-1 | LOW | "Conversion %" is computed as `subscriptions_on_plan / total_paid_subscriptions_in_window` (line 158), NOT `subscriptions / unique_visitors` — the comment on line 24 acknowledges this is a "coarse proxy" without funnel attribution data. Surfaced honestly in the AI prompt's `catatan` field (line 374). Working as designed. |  |
| Pr-2 | LOW | `computeLtv` falls back to "one billing cycle" when no cancelled subs exist (line 270) — undercounts for healthy products in early life. Acceptable signal floor. |  |

---

## 11. A/B Test Framework (`/admin/ab-tests/*`)

### CRUD + report?
- **CATASTROPHICALLY BROKEN.** Multiple **schema ↔ service ↔ model ↔ controller ↔ view** mismatches that combine into 4 separate 500 paths:

#### Schema (migration `2026_05_10_030008_create_ab_experiments_table.php`)
- Columns: `name` (UNIQUE), `description`, `variants` (JSON), `traffic_split` (JSON), `status` ENUM(`draft|active|paused|completed`), `primary_metric` (required NOT NULL), `started_at`, `ended_at`, `timestamps`.
- **No `slug`, no `hypothesis`, no `winner_variant` columns.**

#### Model (`App\Models\AbExperiment`)
- Defines `STATUS_ACTIVE = 'active'`, NOT `STATUS_RUNNING`.
- Exposes `normalizedVariants()` from the (variants, traffic_split) pair.
- **Does NOT expose:** `variantKeys()`, `isRunning()`, `slug`, `hypothesis`, `winner_variant`.

#### Controller (`AbTestController`)
- `store` validates `slug`, `hypothesis`, writes `slug=>$slug`, `hypothesis=>...`, `status => STATUS_RUNNING` — none of these columns exist; the constant doesn't exist. SQL insert throws (MySQL strict mode) or silently drops (SQLite). Even when insert "succeeds" on SQLite, the model can never re-read those columns.
- `act()` references `STATUS_RUNNING` 4× — `UndefinedConstantError` on the first call.
- `show()` calls `$framework->report($experiment->slug)` — slug is null because column doesn't exist.

#### Service (`AbTestFramework`)
- Looks up experiments by `where('slug', $slug)` — column missing.
- Calls `$experiment->variantKeys()` — method missing.
- Calls `$experiment->isRunning()` — method missing.
- Writes assignments to `experiment_id` (schema is `ab_experiment_id`) with field `assigned_at` (column missing) and reads `conversion_value` (column missing).
- Calls `AbAssignment::query()->forExperiment($experiment->id)` — the scope IS defined on the assignment model (line 61) and uses `ab_experiment_id`, so it works, but only for the LOOKUP path; the `create()` path writes the wrong column name.

#### View (`admin/ab/show.blade.php`)
- Reads `$exp->slug`, `$exp->hypothesis` — null because columns missing.
- Renders `$exp->status === 'running'` checks — model is `'active'`, so the running badge never shows.
- Reads `$v['conversions']` per variant (service emits `converted`).
- Reads `$v['weight']` per variant (service doesn't emit `weight` in the `report()` payload at all).
- Multiplies `conversion_rate * 100` (service already returns it as a percentage), so the leader badge shows `(rate * 100)%` — e.g. an actual 4.5% conversion renders as "450.00%".

### Index page rendering?
- **YES on empty DB.** With zero experiments, `@forelse ... @empty` (line 53) handles the case. So `/admin/ab-tests` is reachable with no records.

### Create flow?
- **BROKEN.** Submitting the form attempts to INSERT a row with `slug`, `hypothesis`, `status='running'` — column-doesn't-exist error or silent drop + immediate downstream failure.

### Statistical significance?
- **NOT IMPLEMENTED.** The show view conditionally renders `$report['significance']` (line 71) but the service never emits that key. Always blank. Z-test / chi-square would need to be added to `AbTestFramework::report()`.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| AB-1 | **BLOCKER** | `AbTestFramework` references columns (`slug`, `experiment_id`, `assigned_at`, `conversion_value`) and methods (`variantKeys`, `isRunning`, `winner_variant`) that exist on NEITHER the schema NOR the model. `assign`, `track`, and `report` are all dead. **Fix:** either (a) write a migration adding the missing columns + a model patch, or (b) rewrite the service against the existing `(name, variants, traffic_split, primary_metric)` schema. (a) is the cleaner long-term fix. | `Services/Analytics/AbTestFramework.php`, `Models/AbExperiment.php`, `database/migrations/2026_05_10_030008_*` |
| AB-2 | **BLOCKER** | `AbTestController::store/act` write/read `slug`, `hypothesis`, `STATUS_RUNNING`, `winner_variant` — schema and model don't have any of these. Submitting Create throws an SQL exception (or silently drops fields and later 500s on read). Lifecycle actions throw `UndefinedConstantError: AbExperiment::STATUS_RUNNING`. **Fix:** align controller with the schema (use `STATUS_ACTIVE`, drop `slug`/`hypothesis`/`winner_variant`, OR add those columns to a new migration). | `AbTestController.php:71,95,101,106,160,178,189`, `AbExperiment.php:35-45` |
| AB-3 | **BLOCKER** | `admin/ab/show.blade.php` reads `$v['conversions']` (service emits `converted`), `$v['weight']` (not emitted), `$exp->slug` / `$exp->hypothesis` (not on model), and double-multiplies `conversion_rate * 100` (already a pct). On the rare path where an experiment row exists, the show view renders garbled numbers. **Fix:** match the keys/columns whichever direction AB-1/AB-2 fixes settle on. | `ab/show.blade.php:42,61,63,64` |
| AB-4 | MEDIUM | No `AbTestFramework` consumer in the rest of the codebase — `Grep AbTestFramework|->assign|->track` finds only the controller + service + migration files. So the framework being dead has zero user-facing blast radius today; it only breaks the admin CRUD. Worth fixing before any actual A/B experiment is run. | (project-wide search) |
| AB-5 | LOW | The form posts `start_now=1` → `STATUS_RUNNING`; if the column issue is fixed by using the model's `STATUS_ACTIVE`, the index/show views' literal `'running'` string comparisons also need to become `'active'`. | `ab/index.blade.php:40`, `ab/show.blade.php:9` |
| AB-6 | LOW | "Statistical significance" panel is conditionally rendered (`@if(!empty($report['significance']))`) but `AbTestFramework::report()` never produces that key. Always blank. Either implement a 2-sample z-test (or Fisher's exact for small N) and emit `significance`, or remove the panel + the prompt-spec requirement. | `ab/show.blade.php:71`, `AbTestFramework.php:226-240` |

---

## Cross-cutting findings

1. **Permission map is correct** — `analytics.{revenue,geo,cohort,funnel,performance,churn,insights}` are all defined and seeded onto the `admin` role. Sidebar gates match route gates.
2. **Caching is consistent** — every heavy dashboard uses a versioned cache key (`:v1` / `:v2`) and a `?refresh=1` flag to bust. TTLs are pragmatic: 5 min (Performance), 10 min (Revenue), 6 h (Geo, Cohort), 24 h (Insights).
3. **AI services degrade gracefully** — every Task class catches `Throwable` from `AiClient::chat()` and either returns `[]` / `null` or sets an `ai_error` field. Dashboards render statistics even when no AI provider is configured.
4. **Defensive Schema checks** — repeatedly use `Schema::hasTable()` and `Schema::hasColumn()` before reading. Fresh installs, partial seeds, and minimal dev environments render zero/empty rather than crash. `RevenueAnalytics`, `GeoAnalytics`, `CohortAnalyzer`, `FunnelAnalyzer`, `PerformanceMonitor`, `ContentGapAnalyzer`, `PricingOptimizer` all follow the pattern.
5. **Zero feature tests for any analytics dashboard.** `tests/Feature/Analytics/`, `tests/Feature/Admin/Ab*`, `tests/Feature/Admin/Cohort*`, `tests/Feature/Admin/Funnel*` — none exist. The three blockers above would all have been caught by a 5-line smoke test per route. Strongly recommend a `tests/Feature/Admin/AnalyticsRoutesSmokeTest.php` that hits every dashboard URL as an admin and asserts `assertSuccessful()`.

---

## Summary table

| Dashboard | Reachable | Renders empty DB | Renders w/ data | Verdict |
|-----------|-----------|------------------|-----------------|---------|
| Revenue (`/admin/revenue`) | YES | YES | YES (chart growth-bug fixed) | **PASS** |
| Geo (`/admin/geo`) | YES | YES (array_column fix verified) | YES | **PASS** |
| Cohorts (`/admin/cohorts`) | YES | YES | YES | **PASS** (CSV export route is dead — see C-1) |
| Cohorts CSV export route | YES | **NO (500)** | n/a | **FAIL** — method `export` doesn't exist |
| Funnel (`/admin/funnel`) | YES | **NO (500)** | **NO (500)** | **FAIL** — `engagementFunnel` method doesn't exist |
| Churn (`/admin/churn`) | YES | YES | YES (risk-segmented) | **PASS** |
| Performance (`/admin/performance`) | YES | YES | YES (p95 surfaced) | **PASS** (cached snapshot — no live polling) |
| AI Usage (`/admin/ai-usage`) | YES | YES | YES (14-day sparkline) | **PASS** |
| Sentiment (`/admin/sentiment`) | YES | YES | YES | **PASS** |
| Insights — Content Gap | YES | YES | YES (AI w/ `ai_error` fallback) | **PASS** |
| Insights — Pricing | YES | YES | YES (sanity-clamped) | **PASS** |
| A/B index (`/admin/ab-tests`) | YES | YES | n/a (can't create) | PARTIAL — list page renders empty |
| A/B create (`/admin/ab-tests/create`) | YES (form) | n/a | n/a | PARTIAL — form renders, submit breaks |
| A/B store (`POST /admin/ab-tests`) | reaches controller | **SQL error / silent drop** | n/a | **FAIL** |
| A/B show (`/admin/ab-tests/{id}`) | reaches controller | **NO (500)** | **NO (500)** | **FAIL** — slug column missing, framework `report()` throws |
| A/B act (start/pause/resume/conclude) | reaches controller | **UndefinedConstantError** | n/a | **FAIL** — `STATUS_RUNNING` doesn't exist |

---

## Top fixes to ship before release

In priority order:

1. **`AbTestController` + `AbTestFramework` + `AbExperiment` model alignment** — pick ONE of (a) add `slug`, `hypothesis`, `winner_variant`, `assigned_at`, `conversion_value` columns + rename `ab_experiment_id` → `experiment_id` (huge migration), OR (b) rewrite the service+controller+view against the existing schema. Option (b) is the smaller patch but loses the slug-stable identifier the service was designed for. Until this lands, the entire A/B surface is unusable.
2. **`FunnelDashboardController.php:34`** — rename `$this->analyzer->engagementFunnel($days)` → `signupToSubscribed($days)`. One-line fix.
3. **`FunnelDashboardController.php:38-54` + `funnel/dashboard.blade.php:42`** — replace `percent_from_previous` / `percent_from_top` with `conversion_pct` / `from_top_pct`, OR add aliases in the service so both shapes work.
4. **`routes/web.php:791`** — either change the `'export'` method reference to `'index'` (and accept the `.csv` URL trips through the `index` handler with `?export=csv`) or add a public `export()` shim on `CohortDashboardController` that forwards into `exportCsv($matrix, $type)`.
5. **Optional, recommended** — add `tests/Feature/Admin/AnalyticsRoutesSmokeTest.php` that GETs each `/admin/<analytics>` URL as a seeded `admin@gmail.com` and asserts 200. Would have caught all three blockers above pre-merge.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Services\Analytics\RevenueAnalytics.php`
- `D:\AI\velflix\velflix\app\Services\Analytics\GeoAnalytics.php`
- `D:\AI\velflix\velflix\app\Services\Analytics\CohortAnalyzer.php`
- `D:\AI\velflix\velflix\app\Services\Analytics\FunnelAnalyzer.php`
- `D:\AI\velflix\velflix\app\Services\Analytics\AbTestFramework.php`
- `D:\AI\velflix\velflix\app\Services\Performance\PerformanceMonitor.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\ChurnPredictor.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\ContentGapAnalyzer.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\PricingOptimizer.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\CohortInsightGenerator.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\RevenueDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\GeoDistributionController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\CohortDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\FunnelDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\PerformanceDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\ChurnDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\AiUsageController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\SentimentDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\AiInsightsController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\AbTestController.php`
- `D:\AI\velflix\velflix\app\Models\ChurnPrediction.php`
- `D:\AI\velflix\velflix\app\Models\AiUsageLog.php`
- `D:\AI\velflix\velflix\app\Models\AbExperiment.php`
- `D:\AI\velflix\velflix\app\Models\AbAssignment.php`
- `D:\AI\velflix\velflix\resources\views\admin\revenue\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\geo\distribution.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\cohorts\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\funnel\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\performance\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\churn\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\insights\content-gap.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\insights\pricing.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\ai-usage\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\sentiment\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\ab\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\ab\create.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\ab\show.blade.php`
- `D:\AI\velflix\velflix\routes\web.php` (analytics admin routes, lines 683-808)
- `D:\AI\velflix\velflix\config\admin_menu.php` (sidebar entries lines 220-365)
- `D:\AI\velflix\velflix\database\seeders\RolePermissionSeeder.php` (lines 66-72, 143-145)
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_010001_create_ai_usage_logs_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_020020_create_churn_predictions_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_030008_create_ab_experiments_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_030009_create_ab_assignments_table.php`
