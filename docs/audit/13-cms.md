# Audit 13 — CMS Domain (Blog, Help, Legal, Pitch, Banners)

**Scope:** Editorial blog (BlogPost/BlogCategory + RSS), Help Center articles + FAQ search + feedback, Pitch Deck markdown reader, Legal pages (Privacy / ToS / Refund), Cookie banner, Banner CRUD, and AI copy-assist endpoints powering both blog and help editors.
**Date:** 2026-05-20
**Branch:** `main`
**Verdict:** FAIL — public blog detail/category pages and RSS feed all **500 on first request** because three Blade views (`blog/show`, `blog/category`, `blog/rss`) are missing from `resources/views/blog/`, even though the controller methods are wired and routed. The rest of the domain (Help Center, Legal, Cookie banner, Pitch Deck, Banner admin, AI assist) is in good shape with only minor issues.

---

## 1. Editorial Blog — Public (`/blog`, `/blog/{slug}`, `/blog/feed.xml`)

### Reachable & renders
- `/blog` index — **YES.** Route `blog.index` at `routes/web.php:503` → `BlogController::index`. View `resources/views/blog/index.blade.php` exists and renders featured spotlight + grid + category chips + RSS link + search.
- `/blog/{post:slug}` show — **NO (500).** Route `blog.show` at `routes/web.php:507` → `BlogController::show` returns `view('blog.show', …)` (`BlogController.php:110`). **`resources/views/blog/show.blade.php` does NOT exist.** Every detail-page request will throw `View [blog.show] not found`. The controller logic itself is solid (preview gating for `blog.manage` holders, view-count debounce per session+post for 1h, eager-loads `author`/`category`/`movies(limit 6)`, calls `BlogService::relatedPosts`).
- `/blog/category/{slug}` — **NO (500).** Route `blog.category` → `BlogController::byCategory` returns `view('blog.category', …)` (`BlogController.php:126`). **`resources/views/blog/category.blade.php` does NOT exist.**
- `/blog/feed.xml` RSS — **NO (500).** Route `blog.rss` → `BlogController::rss` renders `view('blog.rss', …)` (`BlogController.php:146`). **`resources/views/blog/rss.blade.php` does NOT exist.** The `Content-Type: application/rss+xml; charset=UTF-8` header is correct, but the view render throws before the response leaves the controller.

The card partial `resources/views/blog/partials/post-card.blade.php` is the **only** view file shipped under `resources/views/blog/` aside from `index.blade.php`. The index page renders fine in isolation, but every card on it links into a 500.

### Markdown / lifecycle correctness
- `BlogPost::setBodyAttribute` (model) renders markdown through `Str::markdown` with `html_input=escape` + `allow_unsafe_links=false` on every save and stamps `body_html` + `reading_minutes` (200 wpm, floor 1 min) so the public detail page wouldn't need to re-parse on each request. Falls back to `<pre>e($md)</pre>` on render failure rather than 500ing the row.
- Slug autogen in `booted()` is unique-against-`withTrashed` + `id != current`, race-safe within a single request.
- `BlogPost::scopePublished` correctly requires both `status='published'` AND `published_at <= now()`. So a published post with future `published_at` is hidden by the public listing — small product question (intentional pre-publish staging window?).

### Admin CRUD + AI assist + related-movies picker
- `/admin/blog/posts` resource — **YES** (`routes/web.php:982`, `can:blog.manage`). Index, create, edit, update, destroy, restore (soft-deleted), force-publish all present (`BlogPostController.php`). Index supports `q` + `status` filtering with status counters.
- Shared `_form.blade.php` powers create/edit. Has: tabbed markdown editor with `marked.js` preview, toolbar (B/I/H2/H3/list/quote/code/link), datetime-local for `scheduled_for`, category select, cover-image URL/storage path with live preview, featured toggle, SEO title+description, SortableJS-powered related-movies picker (debounced search against `route('search.autocomplete')`).
- Action buttons: Save Draft / Publish Now / Save Schedule / Archive. Controller `applyAction()` correctly maps:
  - `publish` → status=published + published_at=now (if null), scheduled_for=null
  - `schedule` + future date → status=scheduled + scheduled_for=date
  - `schedule` + past date → publish immediately (sensible UX)
  - `schedule` without a date → falls back to draft (sensible)
  - `archive` → status=archived
- AI assist endpoints (`BlogAiController` at `routes/web.php:969-974`, all `can:blog.manage`, JSON contract `{ok, titles|outline|enriched}`):
  - `suggestTitles(topic)` — 5 alternatives
  - `outline(brief)` — markdown outline (H2 + bullets)
  - `enrich(draft)` — polish + suggested in-text movie mentions
  All wrap `BlogCopyAssistant`, which is fail-soft (returns `[]` / empty string / original draft on AI provider error so the editor never loses work).

### Scheduled-post auto-publish cron
- **YES, wired correctly.** `App\Console\Kernel::schedule()` (lines 98-105) calls `flik:blog:publish-scheduled` `everyFiveMinutes()->withoutOverlapping()->onOneServer()`.
- Command `App\Console\Commands\PublishScheduledBlog` delegates to `BlogService::publishScheduled()` which `chunkById(50)` walks `scheduled + scheduled_for <= now`, sets `status=published` + `published_at = published_at ?? scheduled_for ?? now`, saves. Per-row `try/catch` logs failures via `Log::warning` instead of aborting the batch.
- Caveat: This only works if the host actually has a `* * * * * php artisan schedule:run` crontab entry — not the app's responsibility to enforce, but worth flagging in deployment docs.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| B-1 | **BLOCKER** | `resources/views/blog/show.blade.php` is missing. `BlogController::show` 500s on every request. **Fix:** create the view (controller passes `$post` with `author`, `category`, `movies` eager-loaded, plus `$related` Collection). | `BlogController.php:110`, `resources/views/blog/` |
| B-2 | **BLOCKER** | `resources/views/blog/category.blade.php` is missing. `BlogController::byCategory` 500s. **Fix:** create the view (controller passes `$category`, `$posts` paginator, `$categories` for sidebar). | `BlogController.php:126` |
| B-3 | **BLOCKER** | `resources/views/blog/rss.blade.php` is missing. `BlogController::rss` 500s, breaking the RSS feed advertised in the index page. **Fix:** create an RSS 2.0 Blade template (controller passes `$posts`, `$site`, `$siteUrl`, `$feedUrl`, `$now`). The `<a href="{{ route('blog.rss') }}">RSS</a>` link in `blog/index.blade.php:74` directly exposes this. | `BlogController.php:146` |
| B-4 | LOW | No site-nav surface links into `/blog`. Footer (`components/footer.blade.php`) has Help / Privacy / ToS / Refund links but no Blog. Discoverable only via direct URL or RSS subscribers. | `resources/views/components/footer.blade.php`, header components |
| B-5 | LOW | `BlogService::relatedPosts` inlines `$movieIds` and `$post->category_id` into a raw SQL expression via `implode(',', array_map('intval', $movieIds))`. Safe (intval enforced), but a `DB::raw` with bindings would be more idiomatic. No SQLi risk. | `BlogService.php:83-93` |
| B-6 | LOW | View-counter debounce keys on `$request->session()->getId()` which rotates on auth-state change. A user who logs in between page-loads will increment twice. Acceptable for a vanity counter; flagged for completeness. | `BlogController.php:102` |

---

## 2. Help Center — Public (`/help`, `/help/{slug}`, `/help/search`)

### Reachable & renders
- `/help` index — **YES.** Renders category grid + per-category preview list (eager-loaded to avoid N+1) + popular articles. `articles_count > 0` filter hides empty categories.
- `/help/category/{slug}` — **YES.** View exists.
- `/help/{slug}` show — **YES.** View exists with breadcrumb, header (last_reviewed_at + helpful%), body, feedback widget, related rail, contact CTA. Preview gating for `help.manage` holders mirrors the blog.
- `/help/search` — **YES.** Form + results.
- `/help/api/suggest` — **YES.** JSON typeahead via `HelpController::searchSuggest` → `HelpSearch::suggest` (title-prefix LIKE; tight `throttle:search`).
- `POST /help/{slug}/feedback` — **YES.** `throttle:10,60` (10 votes/hr/IP).

### FULLTEXT search (or LIKE fallback)
- **YES.** Migration `2026_05_10_190002_create_help_articles_table.php:70-77` adds `FULLTEXT(title, body)` via raw `ALTER TABLE` wrapped in `try/catch` (so a storage engine that rejects it doesn't crash the migration). Only fires on `DB::connection()->getDriverName() === 'mysql'`.
- `HelpArticle::search($q)` (`HelpArticle.php:184-220`) feature-detects driver: `mysql`/`mariadb` → `MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)` with `MATCH (?) DESC` ordering; everything else → LIKE with `%`/`_` escape + title-first ordering. SQLite test suite is covered by the LIKE path.
- One small inconsistency: the migration check is `=== 'mysql'` only (won't add FULLTEXT on MariaDB), but the runtime search checks both `'mysql' || 'mariadb'`. On MariaDB the runtime path tries to use a FULLTEXT index that wasn't created — query still returns rows (MATCH AGAINST without an index falls back to scan + scoring) but it's a performance footgun on prod MariaDB.

### Feedback wiring (helpful / not-helpful)
- **YES, with proper dedupe.**
  - Auth users: composite unique `(help_article_id, user_id)` index in the migration. Controller pre-check returns "already voted" message instead of relying on a 500 from a unique-violation race.
  - Anonymous users: SHA-256(`request->ip() + config('app.key')`) stored in `ip_hash`, deduped via controller lookup (`index (help_article_id, ip_hash)` exists for the lookup but is not unique because MySQL lacks portable partial unique indexes — documented in the migration comment).
  - Counters incremented atomically (`recordHelpful`/`recordNotHelpful` use `increment()`, no `updated_at` touch).
  - Per-IP rate limit at the route layer (`throttle:10,60`) prevents brute-force vote stuffing.
  - Drafts return a friendly "Artikel tidak tersedia" via `feedbackResponse` rather than 404 leaking draft existence.

### Admin CRUD + AI assist
- `/admin/help/articles` resource — **YES** (`routes/web.php:1009`, `can:help.manage`). Index, create, edit, update, destroy, force-publish; per-status counts; category filter.
- Articles form (`_form.blade.php`): markdown editor with `marked.js` preview, toolbar, category select, sort_order, **tag chip input** (comma-split serialised client-side, controller normalises to array with 40-char limit + uniqueness), datetime-local `last_reviewed_at`, AI assist sidebar (`suggestTitle` / `draftAnswer` / `improveArticle`).
- `HelpArticleAssistant` is fail-soft (same pattern as `BlogCopyAssistant`).
- `/admin/help/categories` resource — **YES.** Index lists categories with `withCount(['allArticles as articles_total'])`. `articles_count` on the model is maintained by `HelpArticle::saved/deleted/restored` hooks via `HelpCategory::recountArticles()` so the public landing page renders without N+1 COUNT queries.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| H-1 | LOW | FULLTEXT index is only created on `'mysql'`, but the runtime `HelpArticle::search` treats `'mariadb'` identically. On MariaDB the query plans degrade to a scan. **Fix:** change the migration driver check to `in_array($driver, ['mysql','mariadb'], true)`. | `2026_05_10_190002_create_help_articles_table.php:70`, `HelpArticle.php:195` |
| H-2 | LOW | `HelpCategoryController::destroy` relies on `nullOnDelete` to detach articles. Articles' `category_id` becomes null silently — `HelpArticle::saved` hooks don't fire on a SQL-level `SET NULL`, so a stale `articles_count` may remain on the destroyed category's row (the row is gone, so this is harmless) but **other** categories aren't recounted either. In practice fine because each article was only in one category. Comment in the controller is accurate. |  |
| H-3 | LOW | Anonymous-vote dedupe key is `sha256(ip + app.key)`. A user behind shared NAT (campus / corporate / Telkomsel) gets one vote for everyone on that NAT. Acceptable trade-off vs. tracking cookies, but worth product-team awareness. | `HelpController.php:182` |
| H-4 | LOW | `help.show` autocomplete typeahead is a separate JSON route (`/help/api/suggest`) but `_form.blade.php` editor uses a CDN-loaded `marked.js@12.0.0`. Same as the blog editor — flagged for the CSP/dep-pinning sweep. | `_form.blade.php:197` |
| H-5 | INFO | `HelpArticleFeedback::created_at` is a manual `created_at` column with `$timestamps = false` on the model. Controller manually sets `now()` in `create()`. Diverges from Laravel convention but documented. | `HelpArticleFeedback.php:25`, `HelpController.php:211` |

---

## 3. Pitch Deck (`/admin/pitch-deck`, `/admin/pitch-deck.md`)

### Rendered?
- **YES.** Route `admin.pitch-deck` → `AdminController::pitchDeck` returns `view('admin.pitch-deck', compact('assumptions'))`. View exists at `resources/views/admin/pitch-deck.blade.php` (large self-contained slide deck with sticky toolbar, slide-vs-scroll mode toggle, gold theme). The `$assumptions` array is rendered into the financial slides.
- `AdminController::pitchDeckMarkdown` serves the raw `PITCH_DECK.md` from `base_path()` with `Content-Type: text/plain; charset=utf-8`. File exists at repo root. `abort_unless(file_exists($path), 404)` is the only guard — fine because both routes are gated by `can:admin` at the route group level (`routes/web.php:677-678`).
- Worth noting: the scope brief expected "Markdown-rendered pitch deck" but the actual implementation is a **hand-authored Blade slide deck** (not markdown-rendered). The `.md` endpoint just serves the file raw. No CommonMark parser is wired into the admin Pitch view — the rich slides are entirely Blade. Matches CLAUDE.md description; flagged for the audit reader who expected `Str::markdown` here.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| P-1 | LOW | `pitchDeckMarkdown` reads with `file_get_contents` and serves inline; no caching headers — fine for an admin-only doc. | `AdminController.php:642-651` |

---

## 4. Legal Pages (`/privacy-policy`, `/terms`, `/refund-policy`)

### Reachable
- **ALL THREE — YES, public, no auth.**
  - `/privacy-policy` → `legal.privacy` (`LegalController::privacy` → `legal/privacy.blade.php`)
  - `/terms` → `legal.terms` (`legal/terms.blade.php`)
  - `/refund-policy` → `legal.refund` (`legal/refund.blade.php`)
- All three views exist, all use the shared `<x-legal-page>` component, all bilingual (Bahasa Indonesia default with English toggle via `x-show="lang === 'id'"` / `'en'`).
- `LegalController::LAST_UPDATED = '2026-05-13'` constant feeds the `$updatedAt` prop. Bumping this is the documented signal to also bump `CONSENT_VERSION` in `resources/js/cookie-consent.js` so users get re-prompted — flagged in the file header comment.
- Note: `/privacy` (no `-policy` suffix) is **deliberately reserved** for the auth-gated GDPR/user-data hub (`UserDataController` at `routes/web.php:355`). Clear comment block at `routes/web.php:47-53` explains the split. No collision.
- Linked from footer (`components/footer.blade.php:19/22/23`) and from the cookie banner.

### Issues
None — copy is well-structured against UU PDP 27/2022 + GDPR for Privacy, UU ITE 19/2016 + UU PDP for Terms, and aligned with the Midtrans subscription flow for Refund.

---

## 5. Cookie Banner

### Mounted in layout?
- **YES.** `<x-cookie-banner />` mounted in `resources/views/components/layout.blade.php:231` (every page that uses the base layout). Bottom-fixed Alpine component with three category toggles (Necessary always-on, Analytics, Marketing) plus Accept All / Reject All / Save Preferences.
- Persistence + cross-script event dispatch lives in `resources/js/cookie-consent.js` (loaded via the global app bundle; banner calls `window.FlikConsent.acceptAll()`/`rejectAll()`/`save()`/`get()`).
- Footer link "Cookie Settings" calls `window.FlikConsent.reopen()` (footer.blade.php:27), which fires `flik:cookies-reopen` → banner shows again.
- Schema-version pattern is in place: `CONSENT_VERSION` in the JS bundle is meant to be bumped alongside `LegalController::LAST_UPDATED` to force re-prompts on policy changes. Comment in `LegalController.php:26-30` and `cookie-banner.blade.php:8-12` both reference this; pre-flight before a privacy-policy update should verify both got bumped.
- Fails open if the bundle doesn't load (banner stays hidden, page works without consent persistence). Explicit comment at `cookie-banner.blade.php:142-148`.

### Issues
None blocking.

---

## 6. Banner Admin CRUD (`/admin/banners`)

### Works?
- **YES.** Index/store/toggle/destroy all wired (`routes/web.php:625-632`). Single-page CRUD in `resources/views/admin/banners/index.blade.php` with the add-form left of a listing.
- `Banner` model has `Active` scope (is_active + starts_at <= now + ends_at >= now ordered by sort_order) and a `Position` scope for homepage placement. Schema: `position` enum ('hero', 'sidebar', 'popup', 'footer'), `is_active`, `starts_at`/`ends_at`, `sort_order`.
- AdminController::storeBanner validates title/image_url/position/dates/sort_order.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| BN-1 | LOW | All banner routes are gated by `can:movies.update`, **NOT** the `banners.manage` permission defined in `RolePermissionSeeder.php:38`. The seeded permission is **dead code** — granting `banners.manage` to a role without `movies.update` will NOT actually unlock banner admin. Comment in routes file ("catalog taxonomy → movies.update") acknowledges this, but the dedicated permission is misleading. **Fix:** either rename routes to `can:banners.manage`, or remove `banners.manage` from the seeder. | `routes/web.php:625-632`, `RolePermissionSeeder.php:38` |
| BN-2 | LOW | `AdminController::storeBanner` writes `is_active = $request->boolean('is_active', true)` style fields via `Banner::create([...])`, but `position` validation in the controller is just `'string'` — does not enforce the enum (`hero`/`sidebar`/`popup`/`footer`). An admin who fat-fingers a value will write an invalid enum row that may break the homepage selector. | `AdminController.php:489-516` |
| BN-3 | LOW | No `starts_at`/`ends_at` ordering check — admin can create a banner where end is before start. The `Active` scope handles this gracefully (returns nothing), but it's a UX papercut. |  |
| BN-4 | INFO | No update (edit) route for banners — only create/toggle/destroy. Editing a typo requires destroying + re-creating. Matches the legacy admin pattern; flagged for product. |  |

---

## 7. AI Copy-Assist Services (`BlogCopyAssistant`, `HelpArticleAssistant`)

### Reachable & fail-soft
- **YES** for both.
- Both services follow the documented `App\Services\Ai\Tasks\*` convention: DI'd `AiClient`, strict-JSON system prompts with `Str::markdown` fallback, `Log::warning` on provider failure, fail-soft returns (empty array / original input).
- Controllers (`BlogAiController`, `HelpAiController`) validate inputs (min/max chars), return `{ ok, ... }` JSON contract, and rely on the assistant's fail-soft behaviour so a provider outage surfaces as an empty result in the editor rather than a 500.
- Task types passed to `AiClient::chat()`:
  - `blog.copy.titles`, `blog.copy.outline`, `blog.copy.enrich`
  - `help.assistant.suggest_title`, `help.assistant.draft_answer`, `help.assistant.improve`
  All show up in `/admin/ai-usage` grouped by task — good observability.
- Decode helpers (`decodeStringList`) tolerate stray markdown fences + brace-extraction, cap items at 200 chars, filter non-string entries.

### Issues
None blocking — these are exemplary implementations of the project convention.

---

## 8. Seeders (`BlogSeeder`, `HelpSeeder`)

### Status
- **`HelpSeeder.php` — EXISTS.** 4 categories (Getting Started / Subscriptions / Playback / Account) + 6 published articles in Bahasa Indonesia. All idempotent (`firstOrCreate` by slug). Wired into `DatabaseSeeder.php:73`.
- **`BlogSeeder.php` — DOES NOT EXIST.** Listed in the audit scope but no such file in `database/seeders/`. `DatabaseSeeder` does not reference it either. Consequence: fresh installs land on a `/blog` index that renders the "Tidak ada artikel ditemukan" empty state with no demo content, and `/admin/blog/posts` has nothing to edit.
- Given the missing public views (issues B-1/B-2/B-3), the seeder would just produce 500-pages-with-data anyway, so this is not the most urgent gap — but it remains a documentation/scope drift worth flagging.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| S-1 | LOW | `BlogSeeder.php` is referenced in the audit brief and CLAUDE.md tooling notes, but does not exist on disk. Either create one mirroring `HelpSeeder` (categories + 3-5 starter posts), or update docs to reflect that the blog ships empty. | `database/seeders/` |

---

## Summary table

| Area | Reachable | Renders | Auth | AI fail-soft | Verdict |
|------|-----------|---------|------|--------------|---------|
| `/blog` index | YES | YES | guest-OK | n/a | PASS |
| `/blog/{slug}` show | YES (route) | **NO — view missing** | guest-OK + preview gate | n/a | **FAIL** |
| `/blog/category/{slug}` | YES (route) | **NO — view missing** | guest-OK | n/a | **FAIL** |
| `/blog/feed.xml` RSS | YES (route) | **NO — view missing** | guest-OK | n/a | **FAIL** |
| Blog admin CRUD + AI | YES | YES | `can:blog.manage` | YES | PASS |
| Scheduled-post cron | YES | n/a | scheduler | n/a | PASS |
| `/help` index, category, show, search, suggest | YES | YES | guest-OK + preview gate | n/a | PASS |
| Help FULLTEXT search | YES | YES | n/a | n/a (LIKE fallback) | PASS (MariaDB miss flagged) |
| Help feedback (auth + anon dedupe) | YES | YES | mixed; rate-limited | n/a | PASS |
| Help admin CRUD + AI | YES | YES | `can:help.manage` | YES | PASS |
| Pitch deck (HTML + .md) | YES | YES | `can:admin` | n/a | PASS |
| `/privacy-policy`, `/terms`, `/refund-policy` | YES | YES | guest-OK | n/a | PASS |
| Cookie banner | YES | YES | n/a (client-side) | n/a | PASS |
| Banner admin CRUD | YES | YES | `can:movies.update` (perm mismatch) | n/a | PASS (dead-code permission) |
| `HelpSeeder` | YES | YES | n/a | n/a | PASS |
| `BlogSeeder` | **NO — file missing** | n/a | n/a | n/a | **FAIL** |

---

## Top fixes to ship before release

1. **Create `resources/views/blog/show.blade.php`** — controller already passes `$post` (with `author`, `category`, `movies` eager-loaded) and `$related` Collection. Until this exists, every blog post link from the index, RSS, or external share is a 500.
2. **Create `resources/views/blog/category.blade.php`** — controller passes `$category`, `$posts` paginator, `$categories`. The chip nav on `/blog/index` points straight at this missing view.
3. **Create `resources/views/blog/rss.blade.php`** — controller passes `$posts`, `$site`, `$siteUrl`, `$feedUrl`, `$now`. RSS link is rendered on the index page and surfaced in `Content-Type: application/rss+xml`.
4. **Fix the Help FULLTEXT migration driver check** (`2026_05_10_190002`): replace `=== 'mysql'` with `in_array($driver, ['mysql','mariadb'], true)` so MariaDB prod gets the same index the runtime expects.
5. **Resolve the `banners.manage` permission mismatch**: either re-route `/admin/banners*` to `can:banners.manage` or drop the dead permission from `RolePermissionSeeder`. Current state misleads anyone reading the role config.
6. **Tighten `AdminController::storeBanner` validation** to enforce the `position` enum (`Rule::in(['hero','sidebar','popup','footer'])`).
7. **Add a `BlogSeeder`** (or remove it from the scope/docs).
8. **Add a header/footer nav link to `/blog`** so the editorial surface is discoverable.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Models\BlogPost.php`
- `D:\AI\velflix\velflix\app\Models\BlogCategory.php`
- `D:\AI\velflix\velflix\app\Models\HelpArticle.php`
- `D:\AI\velflix\velflix\app\Models\HelpCategory.php`
- `D:\AI\velflix\velflix\app\Models\HelpArticleFeedback.php`
- `D:\AI\velflix\velflix\app\Models\Banner.php`
- `D:\AI\velflix\velflix\app\Services\Blog\BlogService.php`
- `D:\AI\velflix\velflix\app\Services\Help\HelpSearch.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\BlogCopyAssistant.php`
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\HelpArticleAssistant.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\BlogPostController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\BlogCategoryController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\BlogAiController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\HelpArticleController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\HelpCategoryController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\HelpAiController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\BlogController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\HelpController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\LegalController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\AdminController.php` (banners + pitchDeck methods)
- `D:\AI\velflix\velflix\app\Console\Kernel.php`
- `D:\AI\velflix\velflix\app\Console\Commands\PublishScheduledBlog.php`
- `D:\AI\velflix\velflix\resources\views\blog\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\blog\partials\post-card.blade.php`
- `D:\AI\velflix\velflix\resources\views\help\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\help\category.blade.php`
- `D:\AI\velflix\velflix\resources\views\help\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\help\search.blade.php`
- `D:\AI\velflix\velflix\resources\views\help\partials\search-bar.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\privacy.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\terms.blade.php`
- `D:\AI\velflix\velflix\resources\views\legal\refund.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\blog\posts\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\blog\posts\create.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\blog\posts\edit.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\blog\posts\_form.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\blog\categories\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\help\articles\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\help\articles\create.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\help\articles\edit.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\help\articles\_form.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\help\categories\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\banners\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\admin\pitch-deck.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\cookie-banner.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\layout.blade.php` (cookie-banner mount)
- `D:\AI\velflix\velflix\resources\views\components\footer.blade.php` (legal + help links)
- `D:\AI\velflix\velflix\routes\web.php` (legal + help + blog + admin CMS routes)
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_180001_create_blog_categories_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_180002_create_blog_posts_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_180003_create_blog_post_movie_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_190001_create_help_categories_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_190002_create_help_articles_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_190003_create_help_article_feedback_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_03_07_100007_create_system_tables.php` (banners table)
- `D:\AI\velflix\velflix\database\seeders\HelpSeeder.php`
- `D:\AI\velflix\velflix\database\seeders\DatabaseSeeder.php`
- `D:\AI\velflix\velflix\database\seeders\RolePermissionSeeder.php` (blog.manage / help.manage / banners.manage)
- `D:\AI\velflix\velflix\PITCH_DECK.md`
