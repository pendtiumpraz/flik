# Audit 14 — Admin Operations

**Scope:** Bulk actions on /admin/movies, TMDB import wizard, console commands (flik:*), Console/Kernel scheduler, Worker queue dashboard, Backup procedure, AI provider testing, Admin sidebar nav.
**Date:** 2026-05-20
**Branch:** `main`
**Verdict:** PASS WITH NOTES — the operations surface is broad and well-engineered (Horizon-lite queue dashboard, encrypted backup pipeline, two-phase TMDB importer, single-endpoint bulk actions with per-action permission sharpening). No release-blockers found in this domain. The notable gaps are (a) **four `ai:*` legacy console commands missing the `flik:` prefix**, (b) the **scheduler is missing entries the README/CLAUDE.md implies exist** (no scheduler hook for `flik:schedule:remind`, no expire-pending/gift-expire/spoiler-batch/push-broadcast/email-campaign sweep — those commands are intentionally manual or don't exist), and (c) **`Console\Kernel::$commands` is empty** and the scheduler-discovery still relies on the legacy Laravel 10 layout (consistent with the rest of the project per CLAUDE.md, but worth flagging).

---

## 1. Bulk movie actions (`/admin/movies`)

### Toolbar appears on selection?
- **YES.** `resources/views/admin/movies/index.blade.php:121` mounts a sticky gold-bordered `.bulk-toolbar` driven by Alpine `movieBulk()` factory. It renders `x-show="selected.length > 0 || useSearch"` so it appears the moment a row checkbox is ticked or the "Select all matching X" mode is activated.
- Per-row + header checkboxes wire into the same Alpine `selected[]` set (`index.blade.php:176-194`). Selected rows get `tr.is-selected` highlight; non-selected rows fade with `tr.is-dimmed`.

### All 9 actions reachable?
- **YES — all 9 actions are wired end-to-end.** Single endpoint `POST /admin/movies/bulk` → `MovieBulkController::apply()` switches on `action`. The validator's `in:` list and the toolbar buttons match perfectly.

| # | Action | Toolbar wire | Controller method | Required ability |
|---|--------|--------------|-------------------|------------------|
| 1 | `delete` | `confirmDelete` modal → `bulkAction('delete')` | `doDelete()` (detaches `genre_movie` + `cast_movie` first, chunked 100) | `movies.delete` |
| 2 | `feature` | `bulkAction('feature')` | `doFlag('is_popular', true)` | `movies.update` |
| 3 | `unfeature` | `bulkAction('unfeature')` | `doFlag('is_popular', false)` | `movies.update` |
| 4 | `trend` | `bulkAction('trend')` | `doFlag('is_trending', true)` | `movies.update` |
| 5 | `untrend` | `bulkAction('untrend')` | `doFlag('is_trending', false)` | `movies.update` |
| 6 | `add-genre` | dropdown + `bulkAction('add-genre', { genre_id })` | `doAddGenre()` (`syncWithoutDetaching` — idempotent) | `movies.update` |
| 7 | `ai-tags` | `bulkAction('ai-tags')` | `doDispatch(TagMovie::class, 'ai-batch')` | `movies.update` |
| 8 | `ai-synopsis` | `bulkAction('ai-synopsis')` | `doDispatch(GenerateMovieSynopsis::class, 'ai-batch')` | `movies.update` |
| 9 | `export-csv` | `exportCsv()` (hidden form POST so browser handles file dialog) | `doExportCsv()` (streamed, BOM-prefixed UTF-8) | `movies.view` |

### Defence-in-depth checks
- **Hard cap:** `MovieBulkController::MAX_IDS = 1000` — anything past that is bounced (validator `max:1000` + array_slice in `resolveTargetIds`). "Select all matching" path re-runs the index query with the same cap.
- **Per-action ability sharpening** (`MovieBulkController.php:82-85`): route gate is `can:movies.update`, but the handler re-authorises against the action's required ability. So a user with `movies.update` but not `movies.delete` can't smuggle in `action=delete`.
- **Chunked writes:** delete/flag/add-genre all `array_chunk($ids, 100)` — avoids 1000-id `IN(...)` lists.
- **Audit:** every action calls `safeAudit('admin.movies.bulk_'.$action, …)` (wraps in try/catch — never breaks the request). IDs array is capped at 200 in the audit row so the JSON column doesn't bloat.
- **CSRF:** XHR posts include `X-CSRF-TOKEN` header; the CSV download uses a hidden form with `_token`.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| BLK-1 | LOW | The toolbar's "Select all matching X" mode (`useSearch=true`) re-runs `Movie::where('title', 'like', '%' . $search . '%')` inside the controller — but the visible admin index supports more filters in practice (sort, genre filter). When `useSearch` mode is engaged the bulk action targets only the title-match set, **not** the full filtered set the operator is staring at. Document this in the toolbar tooltip or mirror every filter the index supports. | `MovieBulkController.php:170-178`, `admin/movies/index.blade.php:104` |
| BLK-2 | LOW | `doDispatch()` manually deletes pivots before `Movie::whereIn->delete()`, but the project has no `onDelete('cascade')` on `genre_movie` / `cast_movie` (Phase 0 baseline). If anyone later adds the cascade, the manual delete becomes a double-tap (harmless) — but conversely if anyone forgets the manual delete on a new pivot (e.g. future `tag_movie`), orphan rows accumulate. Consider an Eloquent `deleting` model event so cascade lives in one place. | `MovieBulkController.php:191-197` |
| BLK-3 | INFO | `doAddGenre()` iterates each movie individually via `get()->each(...)` so it can call `syncWithoutDetaching` per model. For a 1000-movie batch that's 1000 `INSERT IGNORE`-style pivot calls. Fine at current scale; if it becomes hot, switch to a single `DB::table('genre_movie')->insertOrIgnore(...)` over the cartesian. | `MovieBulkController.php:225-243` |

---

## 2. TMDB import wizard

### 3-step Alpine flow (search → preview → import)?
- **YES.** `admin/tmdb-import/index.blade.php` mounts `tmdbWizard()` Alpine factory with three phases:
  1. **Search** — debounced 400ms typeahead via `GET /admin/tmdb-import/search?q=&type=movie|tv&year=` → controller `search()` returns slimmed-down hits (12 max, title/year/poster/overview/vote_average).
  2. **Preview** — clicking a hit (or pasting an ID + clicking "Preview") calls `GET /admin/tmdb-import/preview?tmdb_id=&type=` → returns the full normalised envelope (genres flagged existing-vs-new, cast top 30 with profile thumbs, directors, trailer YouTube key, extra posters/backdrops, `already_imported` boolean + existing edit URL).
  3. **Import** — checkboxes for `download_images` / `translate_synopsis` / `overwrite_fields` / `import_seasons` (TV only), two buttons: **Import Now** (synchronous `MovieImporter::import()` returning movie URL) or **Queue Import** (dispatches `ImportFromTmdb` job on `ai-batch` with 60s backoff, 600s timeout, 2 tries).

### Bulk variant
- **YES — `admin.tmdb.bulk` page** at `/admin/tmdb-import/bulk`. Operators paste up to 100 IDs (`TmdbImportController::BULK_LIMIT`), pick options, click "Queue All". `bulkImport()` staggers dispatch at **2s intervals** (`$delaySeconds += 2`) to avoid hammering TMDB's per-second rate cap.

### Defence-in-depth
- **TMDB client** (`TmdbClient.php`) routes all outbound HTTP through `SafeHttp` → `SsrfGuard`, so a future config change pointing `BASE_URL` at an internal host can't pivot us. Cached 24h per `(type, id)`; busted by `MovieImporter::import()` on successful import.
- **Auth strategy:** v4 Bearer wins over v3 api_key, falls back gracefully when neither is set. `enabled()` is the canonical gate — the wizard view shows a "Configure TMDB_KEY" banner when false, JSON endpoints return `enabled: false` (200) on search and `503` on preview/import.
- **Importer idempotency:** `Movie::firstOrNew(['tmdb_id' => …])` — re-imports update the same row. `overwrite_fields` defaults to false, so blank-fill semantics on re-import never trample manually edited metadata.
- **Image mirroring** happens OUTSIDE the DB transaction (HTTP downloads can stall) and uses `SafeHttp` even for TMDB CDN URLs (defence-in-depth — protects against a hypothetical cache poison). Failures fall back to absolute TMDB URLs; the Movie accessors handle both transparently.
- **Translation** path uses optional `TextTranslator`; failure logs a warning and keeps the source English overview — never blocks the import.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| TMDB-1 | MEDIUM | `MovieImporter::syncSeasons()` is half-wired — it creates Season rows but the `import_episodes` branch unconditionally short-circuits with a comment ("future PR"). The wizard exposes an `import_episodes` checkbox in `TmdbImportController::normaliseOptions` (and validates it), but the option is **silently ignored** in the import path. Either drop the option from the UI or finish the `/tv/{id}/season/{n}` fetch. | `MovieImporter.php:614-628`, wizard view `admin/tmdb-import/index.blade.php` exposes options up to `import_seasons` only |
| TMDB-2 | LOW | The wizard does not expose `import_episodes` (only `import_seasons`), so this is a UI/controller mismatch rather than a user-visible bug — but `TmdbImportController` still validates and forwards the option. Confusing for future maintainers. | `TmdbImportController.php:194-196`, `index.blade.php:196-202` |
| TMDB-3 | LOW | `MovieImporter::mirrorImage()` writes to `Storage::disk('public')` only — bypasses the `CdnStorageContract` abstraction. For an S3/Bunny-first deployment, posters/backdrops imported from TMDB live on a different disk than uploaded ones. Acceptable today (the Movie accessors handle absolute URLs vs storage paths), but worth aligning if/when local disk becomes optional. | `MovieImporter.php:636-667` |
| TMDB-4 | LOW | `MovieImporter::resolveCastModel()` checks `whereIn('tmdb_id', $tmdbIds)` then a `whereIn('name', $names)` fallback — but `Cast::firstOrNew(['name' => $name])` later in the per-row loop runs **inside the transaction**. A concurrent import of the same actor (e.g. two operators running the wizard at once on different films) can race on the unique-name constraint; one will throw an integrity violation. Wrap in `firstOrCreate` (DB-side `INSERT … ON DUPLICATE KEY UPDATE` semantics via Laravel's atomic operation) or add a `whereName` upsert. | `MovieImporter.php:561-583` |
| TMDB-5 | INFO | `TmdbClient::cachedGet()` caches successful results 24h but also caches `null` returns (404 path) for the same 24h. A typo'd TMDB id therefore stays in cache for a day. Probably fine. | `TmdbClient.php:308-312` |

---

## 3. Console commands (`flik:*`)

### Inventory — 33 commands registered (auto-loaded via `Kernel::commands()->load(__DIR__.'/Commands')`)

Grouped by namespace:

| Namespace | Commands |
|-----------|----------|
| `flik:ai:*` | `flik:ai:tag-all`, `flik:ai:synopsis-all`, `flik:ai:seo-all`, `flik:ai:weekly-digest`, `flik:ai:bts`, `flik:ai:highlights`, `flik:ai:cinematography`, `flik:ai:quiz`, `flik:ai:spoilers` |
| `flik:cast:*` | `flik:cast:enrich-bios` |
| `flik:detect:*` | `flik:detect:intro-outro` |
| `flik:churn:*` | `flik:churn:predict` |
| `flik:recommendations:*` | `flik:recommendations:recompute` |
| `flik:report:*` | `flik:report:daily` |
| `flik:trending:*` | `flik:trending:recompute` (4 windows: 1h / 24h / 7d / 30d, controlled via `--window=`) |
| `flik:blog:*` | `flik:blog:publish-scheduled` |
| `flik:streak:*` | `flik:streak:grant-freeze-credits` |
| `flik:schedule:*` | `flik:schedule:remind` |
| `flik:transcode` | `flik:transcode` |
| `flik:geo:*` | `flik:geo:update` |
| `flik:backup` / `flik:backup:restore` | `flik:backup`, `flik:backup:restore` |
| `flik:security:*` | `flik:security:daily-digest`, `flik:security:reencrypt-pii` |
| `flik:privacy:*` | `flik:privacy:cleanup-exports` |
| `flik:push:*` | `flik:push:test`, `flik:push:generate-vapid-keys` |
| `flik:pwa:*` | `flik:pwa:generate-splash` |
| `flik:admin:*` | `flik:admin:test-notif` |
| `flik:doctor` | `flik:doctor` |
| `flik:tmdb:*` | `flik:tmdb:health` |
| `flik:dev:*` | `flik:dev:seed-test-series` |
| **LEGACY (no `flik:` prefix)** | `ai:pick-thumbnails`, `ai:generate-trivia`, `ai:generate-short-summary`, `ai:suggest-trailers`, `ai:extract-quotes` |

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| CMD-1 | MEDIUM | **Five `ai:*` console commands miss the `flik:` prefix** — `ai:pick-thumbnails`, `ai:generate-trivia`, `ai:generate-short-summary`, `ai:suggest-trailers`, `ai:extract-quotes`. CLAUDE.md's "Console" highlights section calls them `flik:ai:pick-thumbnails`, etc. — the docs and the actual signatures disagree. Rename for consistency (and so `php artisan list | grep flik:` shows them) or update CLAUDE.md to match reality. | `app/Console/Commands/AiPickThumbnails.php:21`, `AiGenerateTrivia.php:23`, `AiGenerateShortSummary.php:22`, `AiSuggestTrailers.php:22`, `AiExtractQuotes.php:24` |
| CMD-2 | LOW | `AiSendWeeklyDigest.php:24` docblock advertises `weeklyOn(1, '08:00')` but `Kernel::schedule()` registers it at `weeklyOn(1, '06:00')`. Minor doc drift. | `app/Console/Commands/AiSendWeeklyDigest.php:24` vs `app/Console/Kernel.php:33` |
| CMD-3 | INFO | `Console\Kernel::$commands` is `[]` (empty) — discovery is solely via `$this->load(__DIR__.'/Commands')` plus `routes/console.php`. Fine, but means a misspelled namespace or a class outside `app/Console/Commands` is silently invisible. | `app/Console/Kernel.php:15-17` |

---

## 4. Scheduler (`app/Console/Kernel::schedule()`)

### Registered entries
17 scheduled commands, all with `withoutOverlapping()->onOneServer()`:

| Cadence | Command | Notes |
|---|---|---|
| Daily 01:00 (Jakarta) | `flik:backup` | Full pipeline (dump → archive → encrypt → upload → prune) |
| Daily 02:30 | `flik:recommendations:recompute` | RecommendationEngine batch |
| Daily 03:00 | `flik:churn:predict` | All paid users |
| Daily 03:30 | `flik:trending:recompute --window=30d` | |
| Daily 04:30 | `flik:privacy:cleanup-exports --days=7` | GDPR stale-data sweep |
| Daily 07:00 | `flik:report:daily` | AI narrative to super_admins |
| Daily 08:00 (Jakarta) | `flik:security:daily-digest` | |
| Weekly Sun 04:00 | `flik:geo:update` | MaxMind GeoLite2 refresh |
| Weekly Mon 06:00 | `flik:ai:weekly-digest` | |
| Weekly Sat 01:00 | `flik:ai:tag-all` | Only missing tags |
| Weekly Sat 02:00 | `flik:cast:enrich-bios --limit=20` | |
| Weekly Sat 03:00 | `flik:detect:intro-outro --queue --limit=50` | |
| Monthly 1st 04:00 (Jakarta) | `flik:streak:grant-freeze-credits` | |
| Hourly :05 | `flik:trending:recompute --window=24h` | |
| Every 10 min | `flik:trending:recompute --window=1h` | |
| Every 6 hours | `flik:trending:recompute --window=7d` | |
| Every 5 min | `flik:blog:publish-scheduled` | Status flip scheduled → published |

### Coverage gaps
The task brief mentions a longer list — most of those entries simply don't have a backing command, and the ones that do are not scheduled.

| # | Severity | Missing scheduler entry | Backing command exists? |
|---|----------|------------------------|--------------------------|
| SCH-1 | MEDIUM | `flik:schedule:remind` — the command is built to be **safe to run every 5 minutes** per its own docblock (idempotent via `reminder_sent_at` stamp), but `Kernel::schedule()` never registers it. Friday-night "Save for later" reminders will never fire automatically. | YES — `SendScheduleReminders.php` |
| SCH-2 | MEDIUM | `flik:ai:spoilers` — batch comment spoiler-detection. No scheduler entry, so spoiler verdicts only land for new comments (via the `DetectSpoilerOnComment` listener → `DetectSpoilerInComment` job). Historical comments are never swept. Add a weekly schedule if backfill matters. | YES — `DetectAllSpoilers.php` |
| SCH-3 | LOW | `flik:ai:bts`, `flik:ai:highlights`, `flik:ai:quiz`, `flik:ai:cinematography`, `flik:ai:seo-all`, `flik:ai:synopsis-all` — none are scheduled. Operators must run them manually. Acceptable (these are "fill the catalog when adding content" tools) but document the expectation. | YES |
| SCH-4 | LOW | `expire-pending`, `gift-expire` (mentioned in the audit brief) — **no command exists** for either. Subscription pending-expiry and gift code expiration are presumably handled at request time (model scopes) rather than via a sweeper. Worth confirming the design intent. | NO |
| SCH-5 | LOW | Push broadcast / email campaign automation — `BroadcastPushMessage` and `SendCampaignEmail` jobs are dispatched in response to admin clicks, not on a schedule. There is no automated "send tomorrow at 9am" cron. The admin UI may handle this via `delay()` at dispatch time, but if a true scheduler-driven campaign feature is intended, it's not here yet. | NO |

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| SCHED-1 | MEDIUM | `flik:report:daily` runs at 07:00 (no `timezone()` qualifier) — falls back to `APP_TIMEZONE`. CLAUDE.md / `.env.example` suggest `APP_TIMEZONE=Asia/Jakarta` but Laravel's default is UTC, so unset env = 07:00 UTC = 14:00 Jakarta. Pin the timezone explicitly the way `flik:backup` and `flik:security:daily-digest` do. | `app/Console/Kernel.php:30` |
| SCHED-2 | LOW | `flik:recommendations:recompute` and `flik:churn:predict` similarly lack explicit `timezone()`. | `Kernel.php:28-29` |

---

## 5. Worker / Queue dashboard (`/admin/queues`)

### Renders + retry/forget/flush?
- **YES.** `QueueDashboardController` + `QueueMonitor` deliver a Horizon-lite dashboard:
  - **Main view** (`/admin/queues`) — KPI cards (Pending all queues, Failed total, Oldest pending age, Failures last hour), per-queue table (pending / reserved / oldest pending / health badge / "Retry all failed" button), 24h failures sparkline (Chart.js CDN), workers panel (best-effort Horizon directory scan).
  - **Live polling** — `GET /admin/queues/live` returns JSON snapshot every 5s (Alpine `setInterval`); pauses when tab hidden.
  - **Failed list** (`/admin/queues/failed`) — filterable (queue dropdown + payload/exception text search), paginated, supports per-row Retry + Delete, plus client-side bulk Retry / bulk Delete (one POST per uuid so audit rows stay granular).
  - **Flush** — `POST /admin/queues/flush` requires the admin to re-type their password (`confirm_password`), audit-logs both the denied attempts (`queue.failed.flush_denied`) and the successful flushes (`queue.failed.flush`) with `count_flushed` recorded **before** the wipe.

### Implementation correctness
- All mutations go through `Artisan::call('queue:retry'|'queue:forget'|'queue:flush')` — never raw SQL. Driver-agnostic.
- `queue:retry` accepts an array; bulk-retry on a queue resolves UUIDs first then hands them to the framework in one call.
- `QueueMonitor::queueDepths()` is a single aggregate query (`SUM(CASE WHEN reserved_at IS NULL …)`) over all known queues — O(1) regardless of queue count.
- `QueueMonitor::oldestPending()` uses `MIN(available_at)` against the canonical Laravel `jobs` table — correct.
- Every operation handles missing `jobs` / `failed_jobs` tables gracefully via `Schema::hasTable` — fresh installs won't 500 the dashboard.
- The `2026_05_10_130001_ensure_jobs_table.php` + `_130002_ensure_failed_jobs_table.php` migrations create the canonical tables if they don't already exist (guarded with `Schema::hasTable`).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| Q-1 | MEDIUM | `QueueMonitor::throughputLast24h()` **only reports failures**, not successes. The class docblock explicitly acknowledges this ("Without a Horizon-style ledger we can't reconstruct completed throughput"). The UI label is "Failures last hour" / "Failures (24h)" — accurate — but operators expecting a "jobs processed" metric won't find it. Document or wire a job-event listener writing to a `processed_jobs` log table. | `QueueMonitor.php:122-136` |
| Q-2 | LOW | `QueueMonitor::queueWorkers()` only checks `storage/framework/horizon` and reports an empty result with an informational note. No `supervisorctl` / `ps` / health-check fallback. Operationally fine (the note is honest), but the dashboard's "Workers" panel is dead until Horizon is installed. | `QueueMonitor.php:225-258` |
| Q-3 | LOW | Driver mismatch warning: `QueueDashboardController::retry` expects failed-job IDs as UUID strings (`database-uuids` driver) — if a deployment switches `FAILED_JOB_DRIVER` to `database`, the IDs become integers and the retry button silently passes a UUID-shaped string to `queue:retry`, which then errors. Defensively detect the driver or document the constraint. | `QueueDashboardController.php:128-150` |
| Q-4 | INFO | Bulk-retry/bulk-delete fires N HTTP requests in a `for…await` loop client-side. For 200 selected jobs that's 200 round-trips — fine but slow. A single `retry-many` / `forget-many` endpoint would be cheaper, at the cost of audit granularity. | `resources/views/admin/queues/failed.blade.php:153-185` |

---

## 6. Backup procedure (`BackupService` + `flik:backup`)

### End-to-end pipeline
- **YES — all 5 steps wired:** `dump` → `archive` → `encrypt` → `upload` → `prune`. The `RunBackup` command orchestrates them; each step writes to a structured `$report['steps'][]` array with bytes + duration so the admin notification renders meaningful timing info.

| Step | Implementation |
|---|---|
| 1. dump | `BackupService::dump()` dispatches on `database.default` driver. mysqldump uses `--defaults-extra-file=<tempfile>` (password NOT on argv); pg_dump uses `PGPASSWORD` env; sqlite is a file copy. **Dev fallback:** if the dump binary is missing, writes a placeholder `.sql` and warns — so the rest of the pipeline can be exercised E2E. |
| 2. archive | `BackupService::archive()` → tar.gz of the SQL + media dirs (default `storage/app/public/movies`). Per-dir size cap via `backup.max_media_bytes_per_backup` (default 5 GB). Symlinks on POSIX, recursive copy on Windows. **PharData fallback** when tar binary missing. |
| 3. encrypt | `BackupService::encrypt()` — AES-256-CBC + PBKDF2 (10k iters) + `Salted__` magic header — bit-for-bit compatible with `openssl enc -aes-256-cbc -pbkdf2 -salt`. Prefers the openssl binary (key passed via `-pass env:`, never argv); pure-PHP fallback streams in 1 MiB chunks with CBC IV chaining. |
| 4. upload | `BackupService::upload()` — pushes the `.enc` to a `CdnStorageContract` disk (`bunny` / `s3`) via `putStream` (streaming, no full-file buffer). Remote path is `backups/YYYY-MM-DD/<basename>`. |
| 5. prune | `BackupService::prune($keepDays)` — local glob by `db_*` + `filemtime`; remote walks `backups/YYYY-MM-DD/` directories that lexicographically precede the cutoff. Errors collected (not raised). |

### Defence-in-depth
- **Password never on argv.** mysqldump uses a defaults-extra-file with `chmod 0600`. openssl uses `-pass env:FLIK_BACKUP_KEY`. pg_dump uses `PGPASSWORD`.
- **Decrypt path** is the inverse — `BackupService::decrypt()` accepts both binary and pure-PHP paths. `RestoreBackup` (`flik:backup:restore`) requires `--force` for unattended runs and prints a destructive-action banner.
- **Encryption key gating.** `encrypt()` throws (loudly) if `BACKUP_ENCRYPTION_KEY` is unset — no silent "encrypted with empty key" trap.
- **Pipeline failure** raises (not swallowed); the admin notification fires from `RunBackup::handle`'s catch block.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| BAK-1 | LOW | `BackupService::pruneRemote()` calls `method_exists($remote, 'listFiles')` to detect Bunny. `S3StorageService` may not expose `listFiles`, in which case remote prune for S3 is a silent no-op (returns 0). The Bunny-vs-S3 path isn't symmetric. Either add `listFiles` to the `CdnStorageContract` or branch explicitly. | `BackupService.php:507-541` |
| BAK-2 | LOW | The `flik:backup` schedule (daily 01:00 Jakarta) writes a NEW notification on success AND failure (`maybeNotify`), but there's no rate-limit/dedupe — a flapping pipeline (e.g. transient Bunny 5xx) will spam super_admin inboxes. Backoff/digest the failure notification. | `RunBackup.php` |
| BAK-3 | INFO | `BackupService::dumpMysql` writes `--no-tablespaces --set-gtid-purged=OFF` — explicit MySQL 8.0+ flags. Older MariaDB binaries reject `--set-gtid-purged`. Acceptable since the project targets MySQL 8 / MariaDB 10.6+, but worth flagging. | `BackupService.php:128-137` |

---

## 7. AI provider Test Connection (`/admin/ai-settings` → per-row "Test")

### Wired correctly?
- **YES.** Route `POST /admin/ai-settings/{aiProvider}/test` → `AiProviderTestController::test()` → `ProviderTester::test($provider)`.
- The Test button on each row (`resources/views/admin/ai-settings.blade.php:137`) fires an XHR; success/failure result renders inline with latency + model + first response chars.
- `ProviderTester` deliberately **does NOT reuse `AiClient::chat()`** — it doesn't mutate the provider row (`last_used_at`, `total_tokens_used`) and it tests the row the admin selected even if inactive. Sends a tiny "Reply with: OK" probe with `max_tokens=16, temperature=0` so the test cost is ~$0.0001.
- **SSRF guard** wraps the endpoint URL before the HTTP call (`ProviderTester.php:90-93`) — admin's arbitrary-URL field is the most attractive SSRF surface in the panel; the explicit guard prevents pivoting to `169.254.169.254` etc.
- **Provider dispatching** — handles `openai` / `anthropic` / `google` / `deepseek` / `groq` / `mistral` / `openrouter` / `custom` payload shapes and parses each response format (Anthropic's `content[0].text`, Google's `candidates[0].content.parts[0].text`, OpenAI-compatible `choices[0].message.content`).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| AIT-1 | LOW | When `provider.api_key` is empty, the tester throws "API key is empty for this provider." — but for `provider=google` the key is embedded in the URL itself. The early-return shape is correct here, but if Google ever offers a header-auth alternative the check shape will need updating. | `ProviderTester.php:63-65, 78-79` |
| AIT-2 | INFO | Error body is truncated to 400 chars (`ERROR_BODY_PREVIEW`) — fine for the UI but if a provider returns a structured JSON error, the truncation can clip mid-field and obscure the actual reason. Pretty-print + truncate would be friendlier. | `ProviderTester.php:28, 107-110` |

---

## 8. Admin sidebar nav (`config/admin_menu.php`)

### Entries present
- **YES — manifest covers every dashboard listed in CLAUDE.md.** Sections: `overview`, `content`, `system`, `intelligence`, `marketing`, `analytics`, `security`, `distribution`. Total 41 menu items.

| Section | Items |
|---------|-------|
| Overview (2) | Dashboard, Notifications |
| Content (7) | Movies, Genres, Cast, Banners, Help Articles, Help Categories, TMDB Import |
| System (10) | Users, Roles & Permissions, Menu Matrix, API Keys, Queues, Health, Maintenance, Pitch Deck, Translations, Feature Flags, Settings |
| Intelligence (6) | AI Providers, AI Usage, Audit Logs, Sentiment Dashboard, Comment Queue, Director Analyses |
| Marketing (8) | Content Gap, Pricing Optimization, Email A/B Subjects, Email Campaigns, Push Broadcasts, CS Reply Drafter, Push Notifications, Promo Codes |
| Analytics (7) | Revenue, Geo Distribution, Cohort, Funnel, A/B Tests, Churn Risk, Performance |
| Security (1) | WAF Banned IPs |
| Distribution (1) | Encoding Jobs (proxied to movies index — encoding columns live on `movies`) |

### Contract & graceful degradation
- Manifest enforces `label, icon, permission, route` on every entry. `permission = null` means "any admin gate".
- Sidebar Blade component **calls `Route::has(...)` before rendering** each item — features in flight without a route are silently skipped, not 500'd. (Per CLAUDE.md's stated contract.)
- The same manifest is consumed by `/admin/menu-matrix` (`MenuMatrixController`) so the visual audit mirrors the sidebar 1:1.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| NAV-1 | LOW | "Push Broadcasts" and "Push Notifications" are both Marketing-section items with similar names but different permissions (`marketing.email_ab` vs `push.send`) and different routes (`admin.push-broadcasts.index` vs `admin.push.index`). Either consolidate or rename so operators don't accidentally use the wrong one. | `config/admin_menu.php:283-292, 300-307` |
| NAV-2 | LOW | "Encoding Jobs" in the Distribution section proxies to `admin.movies.index` because encoding columns live on the `movies` table. There's no dedicated encoding queue view (despite the dedicated `encoding_jobs` migration / table). Either build a real dashboard or rename the label so the implementation matches expectations. | `config/admin_menu.php:390-395` |
| NAV-3 | LOW | The Security section has a single entry (WAF Banned IPs). The Audit Logs / Sentiment / Comment Queue sit under Intelligence even though Audit Logs is a security tool — categorisation drift. Cosmetic. | `config/admin_menu.php:372-383` |

---

## Summary table

| Area | Reachable | Renders | Auth | Verdict |
|------|-----------|---------|------|---------|
| Bulk movie actions (9 actions) | YES | YES (sticky Alpine toolbar) | per-action ability sharpening | PASS |
| TMDB wizard (search → preview → import) | YES | YES (3-step Alpine flow) | `movies.create` | PASS (TMDB-1: episode-import option is UI-but-not-impl) |
| TMDB bulk import | YES | YES | `movies.create` | PASS |
| Console commands (`flik:*`) | 33 commands | n/a | super_admin via host shell | PASS (CMD-1: 5 legacy `ai:*` commands miss `flik:` prefix) |
| Scheduler entries | 17 registered | n/a | system | PASS (SCH-1: `flik:schedule:remind` not scheduled) |
| Queue dashboard (`/admin/queues`) | YES | YES (live polling, 24h sparkline) | `system.queues` | PASS (Q-1: throughput is failures-only) |
| Backup pipeline (`flik:backup`) | YES | n/a (CLI) | system | PASS (BAK-1: S3 remote-prune asymmetry) |
| AI provider Test Connection | YES | YES (inline result) | `ai.providers.configure` | PASS |
| Admin sidebar nav | YES | YES (degrades via `Route::has`) | per-item permission | PASS (NAV-2: "Encoding Jobs" proxies elsewhere) |

---

## Top fixes to ship before release

1. **CMD-1** — Rename the 5 legacy `ai:*` commands to `flik:ai:*` (or update CLAUDE.md to match reality). Pure naming consistency; one-line PR per file.
2. **SCH-1** — Add `$schedule->command('flik:schedule:remind')->everyFiveMinutes()` to `Kernel::schedule()` so "Save for Friday Night" actually fires (the command is built specifically for this cadence).
3. **TMDB-1** — Either remove the `import_episodes` option from `TmdbImportController::normaliseOptions` OR finish the `/tv/{id}/season/{n}` integration in `MovieImporter::syncSeasons()`. Currently a half-implemented feature with a validated-but-ignored option.
4. **SCHED-1** — Pin `timezone('Asia/Jakarta')` on `flik:report:daily`, `flik:recommendations:recompute`, `flik:churn:predict` (currently they run on whatever `APP_TIMEZONE` is, defaulting to UTC).
5. **Q-3** — Document (or detect) the `database-uuids` driver assumption in `QueueDashboardController::retry()` — switching to the plain `database` failed-job driver silently breaks the retry button.

---

## Files reviewed (absolute paths)

- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MovieBulkController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\TmdbImportController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\QueueDashboardController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\AiProviderTestController.php`
- `D:\AI\velflix\velflix\app\Services\Tmdb\TmdbClient.php`
- `D:\AI\velflix\velflix\app\Services\Tmdb\MovieImporter.php`
- `D:\AI\velflix\velflix\app\Services\Queue\QueueMonitor.php`
- `D:\AI\velflix\velflix\app\Services\Backup\BackupService.php`
- `D:\AI\velflix\velflix\app\Services\Ai\ProviderTester.php`
- `D:\AI\velflix\velflix\app\Jobs\ImportFromTmdb.php`
- `D:\AI\velflix\velflix\app\Jobs\BroadcastPushMessage.php`
- `D:\AI\velflix\velflix\app\Jobs\SendCampaignEmail.php`
- `D:\AI\velflix\velflix\app\Jobs\DetectSpoilerInComment.php`
- `D:\AI\velflix\velflix\app\Console\Kernel.php`
- `D:\AI\velflix\velflix\app\Console\Commands\RunBackup.php`
- `D:\AI\velflix\velflix\app\Console\Commands\RestoreBackup.php`
- `D:\AI\velflix\velflix\app\Console\Commands\AiPickThumbnails.php`
- `D:\AI\velflix\velflix\app\Console\Commands\PredictChurn.php`
- `D:\AI\velflix\velflix\app\Console\Commands\SendScheduleReminders.php`
- `D:\AI\velflix\velflix\app\Console\Commands\AiSendWeeklyDigest.php`
- `D:\AI\velflix\velflix\app\Console\Commands\TmdbHealth.php`
- (all 33 `app/Console/Commands/*.php` enumerated for the inventory table)
- `D:\AI\velflix\velflix\config\admin_menu.php`
- `D:\AI\velflix\velflix\routes\web.php` (admin queue + tmdb + bulk routes)
- `D:\AI\velflix\velflix\routes\console.php`
- `D:\AI\velflix\velflix\resources\views\admin\movies\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\tmdb-import\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\tmdb-import\bulk.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\queues\dashboard.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\queues\failed.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\ai-settings.blade.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_130001_ensure_jobs_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_130002_ensure_failed_jobs_table.php`
- `D:\AI\velflix\velflix\database\migrations\2019_08_19_000000_create_failed_jobs_table.php`
