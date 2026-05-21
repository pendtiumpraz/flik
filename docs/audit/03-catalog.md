# Audit #3 — Movies/TV Catalog
**Date**: 2026-05-20
**Scope**: Movie CRUD, TV series (seasons + episodes), genres, casts, banners, multi-language subtitles, multi-asset thumbnails, video upload, slug routing.
**Method**: Read-only inspection of models, controllers, services, routes, views, migrations.

---

## Inventory

### Models
- `app/Models/Movie.php` — Catalog row + series parent (content_type='movie'|'series'). Slug auto-generation in `booted()`, slug-based route binding, multi-asset rotation, series helpers (`isSeries()`, `firstUnwatchedEpisode()`, `nextEpisodeAfter()`), 51-line `$fillable` doc warning about excluded distribution/AI cols.
- `app/Models/Season.php` — `(movie_id, season_number)` unique, ordered episodes(), `nextEpisode()` helper, `episode_count` derived counter excluded from `$fillable`.
- `app/Models/Episode.php` — `(season_id, episode_number)` unique, denormalised `movie_id`, `released()` scope, `code` accessor ("S2E5"), `generated_summary*` excluded from `$fillable`.
- `app/Models/Genre.php` — slug + name; 2 fillable cols.
- `app/Models/Cast.php` — actor/director heuristic (`character LIKE %director%|%sutradara%`), bio/wikipedia_url/birth_date/nationality, `profile_image` accessor (TMDB-aware).
- `app/Models/Banner.php` — position/active/sort_order with `Active()` scope (time-window aware).
- `app/Models/MovieAsset.php` — slider/poster/backdrop with time-bucket rotation.
- `app/Models/MovieSubtitle.php` — language_code, variant, status, RTL accessor, integration with `LanguageCatalog`.
- `app/Models/WatchHistory.php` — `episode_id` nullable, `completed` excluded from `$fillable` (server-derived).

### Controllers
- `app/Http/Controllers/VelflixController.php` — public `/movies` + `/movie/{slug}`, 367 lines, hero/trending/coming-soon/top-10/A-Z + filter ladder.
- `app/Http/Controllers/AdminController.php` — fat admin (movies/genres/casts/users/banners/AI providers/pitch deck), 652 lines.
- `app/Http/Controllers/Admin/SeasonController.php` — nested resource (165 lines, scoped bindings, auto-promote `content_type='series'` on first season).
- `app/Http/Controllers/Admin/EpisodeController.php` — nested resource (218 lines, counter sync inside DB::transaction, optional AI fill).
- `app/Http/Controllers/Admin/SubtitleController.php` — index + generate + translate + destroy + setDefault + translateDialect + kidSafeFilter + addSpeakerTags.
- `app/Http/Controllers/Admin/MovieUploadController.php` — uploadMaster (single + chunked), startTranscode, encodingStatus.
- `app/Http/Controllers/Admin/MovieBulkController.php` — one endpoint, 9 actions (delete/feature/unfeature/trend/untrend/add-genre/ai-tags/ai-synopsis/export-csv).
- `app/Http/Controllers/Admin/TmdbImportController.php` — search + preview + import + bulk-import (+ Re-sync from edit page).
- `app/Http/Controllers/EpisodeWatchController.php` — per-episode player route.
- `app/Http/Controllers/MediaController.php` — signed-URL serve for private-disk posters/backdrops/sliders.

### Services
- `app/Services/Ai/Subtitle/SubtitleGenerator.php` — gpt-4o-mini-transcribe → WebVTT.
- `app/Services/Ai/Subtitle/SubtitleTranslator.php` — DeepSeek V4 Flash batch translate (30 cues/batch) + auto-harakat for Arabic-variant.
- `app/Services/Ai/Subtitle/DialectTranslator.php` — `jv|su|min|bug|bjn` (Indonesian-only source enforced in view).
- `app/Services/Ai/Subtitle/ProfanityFilter.php` — kid-safe variant (50 cues/batch).
- `app/Services/Ai/Subtitle/SpeakerIdentifier.php` — `[NAMA]:` prefix from cast list (40 cues/batch).
- `app/Services/Ai/Subtitle/LanguageCatalog.php` — ~80+ BCP-47 codes grouped per region.
- `app/Services/Ai/Subtitle/ArabicHarakatService.php`, `WebVttHelper.php`.
- `app/Services/Ai/Tasks/EpisodeSummarizer.php` — 50-word blurb (`force:true` overwrite, idempotent).

### Migrations (catalog block)
- Phase 0: `2026_03_07_000001..200001` (genres, movies, casts, pivots, video_path).
- TV series: `2026_05_10_070001..070004` (content_type, seasons, episodes, watch_histories.episode_id with `nullOnDelete`).
- TMDB: `2026_05_10_180001` (tmdb_id unique, imdb_id, runtime_minutes, tagline, imported_from/imported_at).
- Subtitles, assets, etc. live in the `2026_05_10_0000*`/`010*` blocks per CLAUDE.md.

### Views
- `resources/views/components/movies/show.blade.php` (detail page, includes `<x-series.episode-list>` at line 283).
- `resources/views/components/series/episode-list.blade.php` (season tabs, per-episode progress + still + AI blurb).
- `resources/views/episodes/watch.blade.php` (full-screen Video.js player + autoplay-next + breadcrumb).
- `resources/views/admin/movies/{index,form,upload,ai-reviews}.blade.php`.
- `resources/views/admin/{seasons,episodes,subtitles}/*` — full per-feature pages.

### Routes (catalog-relevant)
- Public: `/movies`, `/movie/{watch}` (slug), `/watch/episode/{episode}` (auth), `/media/{poster,backdrop,slider}/{movie}` (signed).
- Admin: `/admin/movies` CRUD, `/admin/movies/bulk`, `/admin/movies/{movie}/seasons[/{season}/episodes]` (Route::resource minus show, scoped), `/admin/movies/{movie}/subtitles` (5 actions + 3 variants), `/admin/movies/{movie}/{upload-master,start-transcode,encoding-status}`, `/admin/tmdb-import/*`.

---

## ✅ Working (with file:line evidence)

- **`Movie::castMembers()` relation is correctly named** (`app/Models/Movie.php:136`) — comment notes the rename from `casts()` (Laravel 12 collision with `$casts`). Every consumer uses the new name: `AdminController:227`, `MovieImporter:320,322`, `SpeakerIdentifier:108`, `SeoMetaGenerator:90`, `PublicCastController:155`, `XrayController:45`.
- **Slug auto-generation + uniqueness** (`app/Models/Movie.php:66-79`) — `booted()` hook seeds slug from title with `-2`,`-3`… suffixing; `getRouteKeyName()` returns `'slug'`. Public `/movie/{watch}` resolves by slug (`routes/web.php:180`).
- **TV series end-to-end is wired**:
  - Admin nested resource exists at `routes/web.php:641-650` with `scopeBindings()` enforced.
  - `SeasonController::store` auto-promotes `content_type='series'` on first season (`app/Http/Controllers/Admin/SeasonController.php:85-88`) and keeps `total_seasons` in sync.
  - `EpisodeController::store` recomputes `seasons.episode_count` + `movies.total_episodes` inside a `DB::transaction` (lines 98-110).
  - Detail page renders the series component conditionally (`resources/views/components/movies/show.blade.php:283`).
  - `EpisodeWatchController::show` resolves `next` via `Movie::nextEpisodeAfter()` (line 31), which rolls over into the next season's first episode (`Movie.php:526-545`). The Alpine `episodePlayer` factory in `episodes/watch.blade.php:51-60` receives `nextUrl` for autoplay.
  - `WatchHistory.episode_id` is `nullOnDelete` (`2026_05_10_070004:34`) so deleting an episode does NOT wipe parent-movie history.
- **Subtitle pipeline is fully reachable from admin** (`resources/views/admin/subtitles/index.blade.php`):
  - Generate → `routes/web.php:655-656` → `SubtitleGenerator` (FFmpeg → OpenAI transcribe → WebVTT → DB row). SSRF guard on the transcription endpoint (`SubtitleGenerator.php:171`).
  - Translate (~80 langs) → `:657-658` → `SubtitleTranslator` (DeepSeek batch + Arabic harakat post-pass at lines 80-86).
  - Dialect (`jv|su|min|bug|bjn`) → `:755-756` → `DialectTranslator` (form gated to `language_code === 'id'` at `subtitles/index.blade.php:217-228`).
  - Kid-safe → `:757-758` → `ProfanityFilter`.
  - Speaker tags → `:759-760` → `SpeakerIdentifier` (validates source belongs to movie at line 40-44).
  - Set default + delete + storage cleanup all reachable.
- **File upload security on master video** (`MovieUploadController::uploadMaster`):
  - `FileUploadValidator::validateVideo` does magic-byte sniff via `finfo` (`FileUploadValidator.php:220`), MIME/extension consistency, size cap (5 GB default).
  - `VirusScanner` invoked after validator passes (`MovieUploadController.php:88`).
  - `SafeFilename::generate()` rewrites to `{prefix}{uuid}.{ext}` — never persists client name (`SafeFilename.php:44-52`).
  - Chunked uploads defer magic-byte + virus scan to the **assembled** file (`MovieUploadController.php:282-307`) — correct, since per-chunk bytes can't be sniffed.
  - Filename safety check still runs on each chunk (`MovieUploadController.php:70-77`).
- **Signed URLs for private images**: `Movie::resolveAssetUrl()` (line 217-244) returns `URL::temporarySignedRoute(..., 2h, ...)` for paths starting with `private/`. `MediaController` is gated by `signed` middleware per route comment (`MediaController.php:18-20`).
- **Bulk actions wired**: `MovieBulkController::apply` handles 9 actions, 1000-ID hard cap, per-action ability re-check, audit logging, both AJAX + form-post responses. Front-end Alpine component in `admin/movies/index.blade.php:386-530` covers select-all-visible + select-all-matching-search. CSV export streams with UTF-8 BOM.
- **TMDB import wizard is reachable** (`/admin/tmdb-import`, `/bulk`) — search, preview, import (sync+queue), re-sync from movie edit form (`admin/movies/form.blade.php:11-24`).
- **Pivot detach on delete**: `AdminController::destroyMovie` detaches genres + casts before delete (`AdminController.php:223-232`). `MovieBulkController::doDelete` does the same in chunks (lines 190-196). Episode/season cascade is FK-driven (`onDelete cascade` in migrations).
- **Routing uses `Route::scoped()` for nested resources** — guarantees a Season URL only resolves a season belonging to the URL's movie (defence in depth + explicit `ensureBelongs()` in both controllers).
- **A11y / RTL hooks** for subtitles: `is_rtl` accessor + `dir="rtl"` attribute applied in subtitle list (`admin/subtitles/index.blade.php:44`).

---

## ⚠️ Inactive / not wired

- **No edit endpoints for Cast** — `AdminController` exposes `storeCast` + `destroyCast` only (lines 283-307). The bio/wikipedia_url/birth_date/nationality columns added in `2026_05_10_020014` are fillable in the model but unreachable from any admin form. Operators can only edit casts via TMDB re-sync OR `flik:ai:enrich-cast-bio` (which writes `bio` via `forceFill`). No way to manually edit a typo in a name.
- **No edit endpoints for Genre** — `storeGenre` + `destroyGenre` only (`AdminController.php:243-266`). A misspelled genre name cannot be corrected through the UI; only delete-and-recreate. This re-orphans all linked films.
- **No edit endpoints for Banner** — `storeBanner`, `toggleBanner`, `destroyBanner` only (`AdminController.php:489-535`). Once created, a banner's image/dates/link are immutable through the admin UI.
- **No cast↔movie attach UI** — admin movie form (`resources/views/admin/movies/form.blade.php`) only checkboxes genres (line 99-110). Cast is never attached/detached from the admin form; the **only** path that populates `cast_movie` is `MovieImporter::sync` (TMDB) at `MovieImporter.php:320,322`. A hand-created movie row will display "(no cast)" forever unless re-imported via TMDB.
- **No file upload for poster/backdrop/slider** — admin movie form takes URL strings only (`form.blade.php:71-83`). The `validateImage` method on `FileUploadValidator` (with GD re-encoding + EXIF strip) is *defined* but unused by the catalog domain. The `MovieAsset` multi-thumbnail rotation table has zero admin upload UI; rotation works at the Model accessor level but there's no way to populate the table via the admin panel — only direct DB insert / future tooling.
- **No file upload for episode video** — `episodes/_form.blade.php:63-80` only takes string `video_path` + a disk-picker dropdown. The chunked-upload + magic-byte + virus-scan pipeline `MovieUploadController` provides for movies is NOT extended to episodes.
- **No manual subtitle upload** — `SubtitleController` exposes only AI generation/translation. Admins cannot upload a hand-crafted `.vtt`/`.srt` file even though `FileUploadValidator::validateSubtitle` exists (with `<script>` / `javascript:` URI rejection).
- **`Cast::getRouteKeyName()` returns `'id'`** but routes use the string-form integer (`PublicCastController` patterns). Admin cast routes use `{cast}` — works, but `Cast::slug` accessor (derived from name) is only used as a 301-redirect breadcrumb in `PublicCastController::show`, not registered as a binding.
- **`Movie::scopeByGenre` matches a single slug only** — public `/movies?genre=action&genre=drama` would only filter on the LAST value (Laravel request behaviour). `VelflixController::index` doesn't loop, so multi-genre filtering is unreachable from the URL despite `filterOptions.genres` looking like it could.
- **`Genre::getRouteKeyName` is not overridden** — Genre falls back to `id`, but no route uses `Route::model(Genre::class)`-style binding anyway; admin Genre routes take `{genre}` and never appear in user-facing URLs.
- **`flik:dev:seed-test-series` command referenced in migration comment** (`2026_05_10_070001_add_content_type_to_movies.php:14`) — not verified whether this artisan command exists; if absent, the docstring lies. (Not blocking; just stale doc surface.)

---

## 🔧 Needs improvement

### 🔴 Critical

1. **Master video upload pipeline is bypassed by legacy `storeMovie/updateMovie`.** `AdminController::storeMovie` line 135 still does `$upload->storeAs('videos', $safeName, 'public')` — a **publicly-accessible** disk via `/storage/videos/...`. This is the OLD upload path used from the admin movie form (`form.blade.php` has a `<input type="file" name="video_file">` at line 115). It bypasses the *new* `MovieUploadController` chunked + transcoding pipeline. Side effects:
   - Files land in `storage/app/public/videos/` symlinked into webroot — accessible without DRM/auth/geo-block. Bypasses everything in the `App\Services\Drm\*` stack.
   - Sets `video_path` + `video_disk='public'` but never stamps `encoding_status` — film silently never enters the transcoding queue.
   - User-facing detail view branches on `encoding_status === 'ready' && hls_manifest_path` (`components/movies/show.blade.php:32`) — so a legacy-uploaded video falls into the `else` branch which (per CLAUDE.md) is the Video.js direct-MP4 player. This means an admin who uses the obvious "Video File" form input gets an unencrypted MP4 playable to anyone with the URL. The "Upload Master Video" link in the row dropdown (`admin/movies/index.blade.php:276-283`) is the secure path, but the form-level input is the discoverable one.
   - Two ways to upload + the wrong one is the visible default. Either remove the file input from the form or route it through the same validator + queue.

2. **Episode-level playback has zero DRM/transcoding integration.** `EpisodeWatchController::show` line 30 builds `$videoSrc` directly from `$episode->video_path` (or falls back to `$movie->video_full_url`). No HLS manifest check, no signed token, no concurrent-stream lock. The `App\Services\Drm\*` stack and `PlaybackController` (`/playback/{movie}/...`) only protect movie-level playback; series episodes are unprotected. Given that series watching is the primary use-case of TV catalog, this is a load-bearing gap.

3. **Public `/movie/{watch}` performs a `ViewTracker::record` write on every detail-page hit** (`VelflixController::show:258`). The 30-min dedupe note is good, but the call still hits the DB before the view renders. If a scraper bot pre-warms 5K detail pages, that's 5K writes. No rate limit on the public detail page in `routes/web.php:180`. (Lower severity if `ViewTracker` already does its own throttle — verify out of band.)

### 🟡 Moderate

4. **Subtitle generation downloads remote video without size cap.** `SubtitleGenerator::resolveVideoPath` line 112 does `file_put_contents($local, fopen($url, 'r'))` for S3/Bunny-disked videos. No streamed size cap, no signed-URL check. A bunny-disk URL pointing at an attacker-controlled 50 GB blob (if an upstream service ever got compromised) would OOM the worker.

5. **`SpeakerIdentifier` cost can be unbounded.** `addSpeakerTags` runs on a full subtitle; a 3-hour film with 2000 cues = 50 batches @ DeepSeek pricing. No per-call cost preview / confirmation beyond the JS `confirm()`. `subtitles/index.blade.php:251` shows the cast count but not estimated tokens/$$.

6. **`SubtitleTranslator::translateCues` silently fills missing rows with `''` on mismatched count** (`SubtitleTranslator.php:172-189`). If DeepSeek drops a row, the corresponding cue becomes empty in the .vtt — viewers see a blank caption at that timestamp. Probably need a retry on partial responses, or at minimum bookmark the failed indices and warn the admin.

7. **`AdminController::storeMovie` validation is shallow.** `'overview' => 'required|string'` — no max length cap (could ingest a 10MB string), no genres-array `.exists` rule (validated `'genres' => 'array'` only at line 102, then `sync()` will silently drop bad IDs but the operator gets no feedback). Compare with `SeasonController` which uses `'max:5000'` on overview and `'exists:roles,id'` style checks elsewhere — `AdminController` predates that convention.

8. **`Cast::scopeOfRole` heuristic is fragile** (`Cast.php:119-148`) — pivot character `LIKE %irector%` matches "Director", "directors", but also "redirector" or any 8-char substring. Low-impact today (no such characters in real data) but a TMDB import that happens to fetch a character name containing those letters would mis-classify the cast member. The class docblock acknowledges this debt ("if seed data ever evolves to use a dedicated `role` column on the pivot we should switch to that").

9. **`SubtitleController::generate` accepts arbitrary `$request->input('language', 'id')`** (`SubtitleController.php:38`) — no `in:` rule. A bad string just passes through to `SubtitleGenerator` which falls back to `'name' => $sourceLang` (`SubtitleGenerator.php:60`). The cue file is still produced, but `MovieSubtitle.language_code` could end up storing "💩" or "../etc/passwd". Should validate against `LanguageCatalog::exists()`.

10. **Multi-asset thumbnail rotation has no admin UI.** `Movie::currentAssetUrl()` cycles among active `movie_assets` rows per type — but there's no admin page to upload/manage those rows. The whole feature is dead behind the model until someone wires CRUD for `MovieAsset`. The legacy `poster_path`/`backdrop_path` columns continue to do the work.

11. **TMDB import + AI-fill episodes button is buried.** `EpisodeController::store` takes an `ai_fill=1` checkbox (`EpisodeController.php:79`) that triggers `EpisodeSummarizer` per row — but TMDB bulk import doesn't offer "import all seasons + episodes + summarize" as a single button. The options shape (`options.import_seasons`, `options.import_episodes`) exists in the validator (`TmdbImportController.php:194-195`) but no UI surfaces it on the bulk-import page. (Need to spot-check `tmdb-import/bulk.blade.php` to be sure.)

### 🟢 Minor

12. **Genre / Cast / Banner delete confirms via native browser `confirm()`** — the bulk actions modal is much nicer. Inconsistent UX.
13. **Episode form's `video_disk` select hardcodes `['public', 's3', 'azure', 'alibaba', 'bunny']`** (`episodes/_form.blade.php:76`) — not derived from `config('filesystems.disks')`. A new disk added later won't show up.
14. **Admin movies page bulk toolbar always renders `<select>` of all genres** (`admin/movies/index.blade.php:138`) — for a catalog with 200+ genres this would be ugly. Currently fine, but `<select size="1">` becomes a usability cliff at scale.
15. **`VelflixController::mapMovie` shape duplication** — same `mapMovie` shape appears in 5 different controllers (search, recommendations, etc.). Pulled into a `MoviePresenter`/resource would shrink each.
16. **`Movie::getRouteKeyName` returns `'slug'`** but `Movie::findOrFail($id)` is used in a couple of places (e.g. `WatchHistory::movie()` BelongsTo defaults to `id`) — works because pivot/FK still use `id`. Worth a comment so future devs don't try to "fix" it.
17. **`Banner::scopePosition` enforces no enum** — controller validates `in:hero,sidebar,popup,footer` (`AdminController.php:496`) but the model is loose. A direct factory/seed could violate.
18. **`Episode::getStillUrlAttribute` doesn't sign private-disk URLs** (`Episode.php:87-97`) — the docblock at lines 17-21 admits this debt. If an admin uploads an episode still via private disk path, the still won't be reachable.
19. **`SubtitleTranslator::translate` overrides cue count with the SOURCE cue count** (line 118) — if a translator generates extra/fewer cues, the count is wrong. Minor cosmetic issue.
20. **Slug regeneration is implicit on title rename** — `Movie::booted()` only fills slug when empty. Renaming a movie doesn't regenerate the slug, so a film titled "The Bad Guys" later renamed to "The Bad Guys 2" keeps the original slug. Probably intentional (URL stability) but undocumented.

---

## 📊 Satisfaction Score: 6.5/10

**Justification**:
- TV series end-to-end works (admin → DB → user picker → autoplay) — that alone is mid-MVP territory.
- Subtitle pipeline (gen + translate + 3 variants) is genuinely competitive; 80+ language catalog is over the line for an Indonesian streamer.
- File-upload security on master video is hardened — defence in depth (size + filename + magic-byte + virus scan + UUID rename). One of the strongest surfaces audited.
- Bulk actions UI is polished (sticky toolbar, modal confirm, select-all-matching).
- TMDB import wizard reachable and dedupes on `tmdb_id`.

But:
- **The "obvious" video upload path in the admin movie form bypasses the secure pipeline entirely** — a content editor following the visible UI ends up with a publicly accessible MP4 outside the DRM stack. This is a competitor-non-starter.
- **Episode playback has no DRM** — TV series is the headline use case and it's wide open.
- **Cast attachment to movies is TMDB-only** — a hand-created movie row cannot have cast attached.
- **No image upload, no manual subtitle upload, no MovieAsset CRUD** — the schema is ahead of the UI by 3-4 features.
- **Genre/Cast/Banner cannot be edited** — only created and deleted. Real catalog ops will hit this in week one.

Pulls the score off "solid MVP" (7) to "working but polish-required" with notable gaps (6.5).

---

## 🎯 Top 3 Priorities

1. **Unify movie video upload** — Remove the file input from `admin/movies/form.blade.php` OR route it through `MovieUploadController::uploadMaster`. Same for `episodes/_form.blade.php`. Then gate `EpisodeWatchController::show` on encoding_status / signed playback tokens so episodes inherit the same DRM/geo flow as movies. Single biggest defect in this domain.
2. **Add edit endpoints for Cast/Genre/Banner + admin Cast-attach UI on movie form** — Each is ~30 LOC of controller + view. The schema and relations are ready; the admin surface is just missing. Cast attachment in particular is a hard-blocker for hand-curated catalogs.
3. **Manual subtitle + image upload** — `FileUploadValidator::validateSubtitle` and `validateImage` already exist (with EXIF strip + script-tag rejection). Wire them: `SubtitleController::upload` (`POST /admin/movies/{movie}/subtitles/upload`) and a poster/backdrop file picker on the movie form (writing through `MovieAsset` + the legacy `poster_path` columns).

---

## Bonus: missing-but-expected features

- **Series-level subtitles** — `MovieSubtitle.movie_id` is the only FK. There's no `episode_id` on `movie_subtitles`, so per-episode subtitles cannot exist. Real-world TV catalogs need per-episode subs.
- **Per-episode encoding pipeline** — `episodes` table has `hls_manifest_path` + `intro_*_seconds` columns (`2026_05_10_070003:53,58-60`) but no `EpisodeUploadController` analogue. The schema is staged for per-episode HLS but no controller exists.
- **Soft-delete + trash recovery** — `Movie::destroyMovie` is hard delete + cascade. No way to recover from a mis-click on bulk delete (1000 IDs per call).
- **Catalog import provenance audit** — `imported_from` + `imported_at` columns exist but there's no admin dashboard surface filtering by them.
- **Per-movie publish/draft state** — there's no `is_published`/`published_at` column. A movie is visible the moment it's created. Real CMS workflows need a draft state.
- **Localization of `overview`** — `Movie::synopsisForLocale` translates on the fly via `TextTranslator` but stores nothing back to a per-locale column. Translations are cached in `translation_cache` table; good for cost, but admins can't override the AI translation.
- **Genre slug edit** — a non-issue today but slug renames cascade-break any saved `/movies?genre=...` link.
- **Series finale banner / season-summary view** — Season has overview but no AI synopsis generator analogue to `EpisodeSummarizer`.
- **Episode `released()` scope is defined but unused** (`Episode.php:129-135`) — the series detail picker shows unreleased episodes. Should filter via `released()` for the public surface.
- **Bulk season/episode delete** — `SeasonController::destroy` works one at a time; deleting a 20-season series requires 20 clicks (and 20 transactions).
- **Search-within-series** — no UI to find an episode by title across all series; the AdvancedSearch surface filters at the movie level only.

---

## Files referenced
- `D:\AI\velflix\velflix\app\Models\Movie.php`
- `D:\AI\velflix\velflix\app\Models\Season.php`
- `D:\AI\velflix\velflix\app\Models\Episode.php`
- `D:\AI\velflix\velflix\app\Models\Cast.php`
- `D:\AI\velflix\velflix\app\Models\Genre.php`
- `D:\AI\velflix\velflix\app\Models\Banner.php`
- `D:\AI\velflix\velflix\app\Models\MovieAsset.php`
- `D:\AI\velflix\velflix\app\Models\MovieSubtitle.php`
- `D:\AI\velflix\velflix\app\Models\WatchHistory.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\VelflixController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\AdminController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\EpisodeWatchController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\MediaController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\SeasonController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\EpisodeController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\SubtitleController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MovieUploadController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\MovieBulkController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\TmdbImportController.php`
- `D:\AI\velflix\velflix\app\Services\Security\FileUploadValidator.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\SubtitleGenerator.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\SubtitleTranslator.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\DialectTranslator.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\ProfanityFilter.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\SpeakerIdentifier.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Subtitle\LanguageCatalog.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\EpisodeSummarizer.php`
- `D:\AI\velflix\velflix\app\Support\SafeFilename.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_070001_add_content_type_to_movies.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_070002_create_seasons_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_070003_create_episodes_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_070004_add_episode_id_to_watch_histories.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_180001_add_tmdb_id_to_movies.php`
- `D:\AI\velflix\velflix\resources\views\components\movies\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\series\episode-list.blade.php`
- `D:\AI\velflix\velflix\resources\views\episodes\watch.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\movies\{index,form,upload}.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\subtitles\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\seasons\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\episodes\{index,_form}.blade.php`
- `D:\AI\velflix\velflix\routes\web.php`
