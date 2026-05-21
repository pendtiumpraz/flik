# FLiK — Satisfaction Report

**Auditor**: Claude Opus 4.7 (20-agent swarm)
**Date completed**: 2026-05-21
**Methodology**: Each of 20 domains audited by an independent agent reading source code, routes, views, migrations, and configs. No execution — static read-only audit. Scores anchored: 10 = competitor-grade, 7 = solid MVP, 5 = working-but-needs-polish, 3 = scaffold only, 1 = broken.

---

## TL;DR

**Overall satisfaction**: **6.2 / 10**

FLiK has shipped an extraordinary breadth of features in a short time — the catalog of capabilities rivals Netflix in scope (DRM, multi-language subtitles, 30+ AI tasks, RBAC, analytics, social, push, i18n, PWA). But the **depth** lags the breadth: roughly **20–25% of shipped features are dead code** — built but never wired into UI, never scheduled, or referencing nonexistent methods/views/columns. Several payment, playback, and analytics surfaces will 500 on first request in production.

The good news: nearly every gap is a **small, surgical fix** (add a route, write a missing view, fix a method name) — not a rewrite. With ~2 weeks of focused integration work, this product moves from 6.2 → 8.5.

---

## Per-domain scorecard

| # | Domain | Score | Status |
|---|---|---|---|
| 1 | Auth & Login | 6/10 | Solid foundation, 2FA unreachable, OAuth bypasses 2FA |
| 2 | RBAC & Permissions | 7/10 | Works, but Menu Matrix broken, no audit on toggleAdmin |
| 3 | Movies/TV Catalog | 6.5/10 | TV+subtitles strong, but insecure parallel upload path |
| 4 | DRM/Playback | **3/10** 🔴 | Pipeline DOES NOT WORK — wrong method signatures across 4 services |
| 5 | AI Wave 1 | 7/10 | Core works; SEO meta never rendered, sentiment listener missing |
| 6 | AI Wave 2/3 | 7/10 | Most work; X-Ray has zero writers (empty), Soundtrack orphan |
| 7 | Recommendations | 6/10 | Batch works; cold-start quiz has zero effect on output |
| 8 | Discovery/Search | 7/10 | Smart bar works (auth only), legacy Livewire orphan + broken |
| 9 | Engagement | 7/10 | All reachable; two parallel streak systems silently fight |
| 10 | Social | 5/10 | Lists+Follow solid; Watch Party 500s (3 missing files) |
| 11 | Subscriptions/Payment | **3/10** 🔴 | midtrans/midtrans-php NOT IN composer.json — every checkout 500s |
| 12 | Notifications | 7/10 | All work; gift email view missing, scheduled_at dead column |
| 13 | CMS | 5/10 | Help+Legal solid; blog/show/category/rss views MISSING (500s) |
| 14 | Admin Operations | 8/10 | Best-scoring domain; 5 commands missing flik: prefix, schedule.remind missing |
| 15 | Security Infrastructure | 8/10 | Live curl-verified WAF+CSP+headers; GeoBlock applied to ZERO routes |
| 16 | Privacy & Compliance | 7/10 | End-to-end wired; SecurityAlertService severity map mismatched (alerts silently dead) |
| 17 | Analytics Dashboards | 5/10 | 8/11 work; Funnel + Cohort export + entire A/B suite 500 |
| 18 | Trending | 7/10 | All correct except recency boost broken (Carbon 3 signed diff) |
| 19 | Mobile/PWA/i18n | 6/10 | Wiring correct; ALL PWA icons + splashes missing on disk |
| 20 | DevOps/CI/Testing | 6/10 | Tooling mature; Feature Flags + Settings admin DEAD CODE (no routes/views) |

**Median score: 6.5** · **Lowest: 3** · **Highest: 8**

---

## 🔴 RELEASE BLOCKERS (must fix before any real traffic)

These cause 500 errors on first request from a normal user/admin:

| # | What breaks | Fix LOC | Domain |
|---|---|---|---|
| 1 | **Every Midtrans checkout 500s** — `midtrans/midtrans-php` not in composer.json yet controllers `use \Midtrans\Snap` | `composer require midtrans/midtrans-php` | #11 |
| 2 | **DRM pipeline does nothing** — TranscodingPipeline calls FfmpegTranscoder/HlsSegmenter/HlsEncryptor with **named args that don't exist**. UploadToBunny calls `uploadDirectory()` that doesn't exist. Manifest endpoints `/drm/segment/*` + `/drm/playlist/*` not routed. Net: no movie ever reaches `encoding_status=ready`, watch view always falls back to raw mp4. | 4 jobs need signature alignment + 2 new routes | #4 |
| 3 | **Funnel dashboard 500s** — controller calls `engagementFunnel()`, service method is `signupToSubscribed()` | rename | #17 |
| 4 | **A/B test full suite broken** — controller/view reference 6 columns + 3 const that exist on NEITHER schema NOR model | 1 migration + model alignment | #17 |
| 5 | **Cohort CSV export 500s** — route maps to nonexistent `export` method | add public method | #17 |
| 6 | **Blog detail/category/RSS 500** — `blog/show.blade.php`, `blog/category.blade.php`, `blog/rss.blade.php` MISSING | write 3 views | #13 |
| 7 | **Watch Party chat 500s** — calls `$this->safeBroadcastEvent()` which doesn't exist | rename to `safeBroadcast` | #10 |
| 8 | **Watch Party create/join 500** — `resources/views/watch-party/{create,join}.blade.php` MISSING | write 2 views | #10 |
| 9 | **Episode playback has NO DRM** — `EpisodeWatchController::show` serves raw `episode->video_path`. TV series wide open. | enforce manifest+JWT same as movies | #3 |
| 10 | **Insecure parallel video upload path** — `AdminController::storeMovie` stores raw mp4 to public disk, bypasses encryption queue. Visible from the obvious "Add Movie" form. | remove old path OR redirect to MovieUploadController | #3 |

---

## ⚠️ INACTIVE — built but never wired (top 15)

| # | Feature | Evidence | Fix |
|---|---|---|---|
| 1 | **2FA UI** | Setup/challenge/verify routes exist but ZERO entry points in views. `'2fa'` middleware alias never applied | Add link to profile Security card + apply middleware globally |
| 2 | **Cold-start recommender** | `ColdStartRecommender` 140 LOC quiz-aware service — zero callers. Quiz writes UserPreference rows never read by RecommendationEngine | Wire into `RecommendationEngine::coldStartFallback` |
| 3 | **Onboarding redirect** | New users never routed to `/onboarding` — no middleware, no observer, no banner | Add redirect after Registered event |
| 4 | **X-Ray actor overlay** | `MovieSceneActor` table has zero writers. JS polls every 5s and gets empty results | Add AI job to seed scene actors per movie |
| 5 | **SoundtrackAnalyzer** | Full 250-LOC service, zero callers anywhere | Wire to movie detail page OR delete |
| 6 | **SEO meta `<x-movie-seo>`** | Component generated + stored but never rendered on page `<head>` | Mount in movie detail layout |
| 7 | **AnalyzeCommentSentiment** | Job dispatched from nowhere; sentiment dashboard permanently empty | Listen to Comment::created |
| 8 | **GeoBlock middleware** | Alias registered, applied to ZERO routes | Apply to playback.* routes |
| 9 | **ForensicWatermarker** | Fully implemented, never invoked anywhere | Wire into PlaybackManifestGenerator |
| 10 | **Feature Flags admin** | Controller + model + service + helpers + seeder + permission ALL exist — but NO ROUTES + NO VIEWS | Add ~50 LOC routes+views |
| 11 | **Settings admin** | Same as above — full backend, missing routes+views | Add ~50 LOC routes+views |
| 12 | **AdminControllers for gifts + referrals** | Both unrouted (no entry in routes/web.php) | Add 4 routes |
| 13 | **`/referrals` link** | Dashboard reachable only by typing URL | Add to user dropdown |
| 14 | **`/r/{code}` capture** | Cookie-capture works but no shareable link surfaced in UI | Add share buttons to /referrals dashboard |
| 15 | **`flik:schedule:remind`** | Command exists for "Save for Friday Night" — not in scheduler | Add `every5Minutes()` entry |

---

## 🔧 NEEDS IMPROVEMENT (top 15)

| # | Issue | Domain | Severity |
|---|---|---|---|
| 1 | **MenuMatrixController references wrong column** (`roles.slug` vs `name`) + wrong pivot (`role_permission` vs `permission_role`) — silently falls back to misleading hardcoded list | #2 | 🔴 |
| 2 | **`toggleAdmin` flips `users.is_admin` (super_admin equivalent) without audit log** — privilege escalation back door | #2 | 🔴 |
| 3 | **OAuth account-linking crash** — clicking "Sign in with Google" with existing email/password user → unique-constraint 500 | #1 | 🔴 |
| 4 | **Session fixation** — RegisterController doesn't `regenerate()` session after login; logout doesn't `invalidate()` | #1 | 🟡 |
| 5 | **Two parallel streak systems** — legacy RewardsController + new StreakService can fire same day, silently overwrite display column | #9 | 🟡 |
| 6 | **Trending recency boost permanently 1.0** — Carbon 3 signed diff means recency never discriminates between 30min and 30days | #18 | 🔴 |
| 7 | **SecurityAlertService severity map uses WRONG event names** — every fired event maps to 'low', filtered by default 'high' floor → Slack/Discord alerts silently dead even for critical | #16 | 🔴 |
| 8 | **TrailerSuggester OOMs on 4GB videos** — `file_put_contents($local, $stream)` instead of `stream_copy_to_stream` | #5 | 🟡 |
| 9 | **ThumbnailPicker bypasses UsageTracker** — every Gemini call invisible on cost dashboard | #5 | 🟡 |
| 10 | **WAF allowlist too broad** — `admin.*` AND `admin/*` exempted from body inspection (justification was admin pitch-deck markdown, but exempts banner/user/movie POST too) | #15 | 🟡 |
| 11 | **PWA icons + splashes all missing on disk** — only `README.txt` in `public/icons/`. Install UI fallbacks badly | #19 | 🟡 |
| 12 | **SetLocale priority chain dead after visit 1** — session pin overrides user.preferred_locale + Accept-Language permanently | #19 | 🟡 |
| 13 | **PII export omits phone/address/national_id_hash** — policy text promises "seluruh data" | #16 | 🟡 |
| 14 | **AdminController toggleAdmin no self-revocation guard** — super_admin can lock themselves out | #2 | 🟡 |
| 15 | **Refund flow missing** — no reverse path for referral rewards, no Midtrans refund integration | #11 | 🟡 |

---

## 📊 Test coverage reality

| Domain | Test files | Verdict |
|---|---|---|
| Auth | 2 (one uses weak `'testpassword'` that would fail StrongPassword) | Critical gap |
| Authorization | 4 | OK |
| Engagement | 1 (StreakServiceTest only) | Critical gap |
| Security (SSRF, HtmlSanitizer) | 2 unit | OK for what they cover |
| Admin operations | 1 (MovieBulkActionTest) | Sparse |
| Doctor / Notifications / Push / Email / Trending / Lists / Watch Party / Cast / Blog / Help / Promo / Gift / Referral / Feature Flags / Settings / Maintenance | **0** | Critical gap |

**Total**: ~13 test files for ~135 controllers. Coverage ratio ≈ 10%.

---

## 🎯 Recommended priority order

### Phase 1: Stop the 500s (1 week)
1. `composer require midtrans/midtrans-php` (#11 RELEASE BLOCKER)
2. Fix DRM pipeline method signatures (#4 RELEASE BLOCKER — biggest fix, ~4 days)
3. Write 5 missing blade views: blog/show, blog/category, blog/rss, watch-party/create, watch-party/join
4. Fix Funnel + Cohort + A/B controllers (rename methods, align schema)
5. Add 4 missing admin routes (gifts, referrals, feature-flags, settings) + views
6. Generate PWA icons + splash screens

### Phase 2: Activate inactive features (3-4 days)
7. Apply GeoBlock middleware to playback routes
8. Wire AnalyzeCommentSentiment listener
9. Wire ColdStartRecommender into RecommendationEngine
10. Mount `<x-movie-seo>` on movie detail
11. Add Feature Flags + Settings admin views
12. Fix SecurityAlertService severity map alignment
13. Add 2FA entry point + apply `'2fa'` middleware to admin routes
14. Add referral dashboard link to user dropdown

### Phase 3: Polish + harden (1-2 weeks)
15. Add session regenerate on login/register/logout
16. Add OAuth account-linking lookup by email fallback
17. Fix trending recency boost (`getTimestamp()` instead of `diffInSeconds`)
18. Consolidate streak systems (delete legacy)
19. Narrow WAF allowlist
20. Fix SetLocale priority chain
21. Add ForensicWatermarker invocation
22. Backfill MovieSceneActor data (X-Ray)
23. Add 30+ feature tests for critical paths

### Phase 4: Nice-to-have (ongoing)
- Test coverage to 40%+
- Refund flow
- Email scheduled_at cron
- Admin presence indicator
- Soundtrack analyzer wire-up OR removal

---

## Per-domain detailed reports

Each domain has its own audit file in `docs/audit/`:

- [01-auth-login.md](audit/01-auth-login.md)
- [02-rbac.md](audit/02-rbac.md)
- [03-catalog.md](audit/03-catalog.md)
- [04-drm-playback.md](audit/04-drm-playback.md)
- [05-ai-wave1.md](audit/05-ai-wave1.md)
- [06-ai-wave2-3.md](audit/06-ai-wave2-3.md)
- [07-recommendations.md](audit/07-recommendations.md)
- [08-search.md](audit/08-search.md)
- [09-engagement.md](audit/09-engagement.md)
- [10-social.md](audit/10-social.md)
- [11-payment.md](audit/11-payment.md)
- [12-notifications.md](audit/12-notifications.md)
- [13-cms.md](audit/13-cms.md)
- [14-admin-ops.md](audit/14-admin-ops.md)
- [15-security-infra.md](audit/15-security-infra.md)
- [16-privacy.md](audit/16-privacy.md)
- [17-analytics.md](audit/17-analytics.md)
- [18-trending.md](audit/18-trending.md)
- [19-mobile-pwa-i18n.md](audit/19-mobile-pwa-i18n.md)
- [20-devops-ci.md](audit/20-devops-ci.md)

---

## Anchor sentences for the post-mortem

> "Built more than was wired."
> "Shipping breadth has dramatically outpaced integration depth."
> "Most fixes are surgical, not architectural."
> "DRM, payments, and analytics need a focused integration sprint before any real traffic."
> "Test coverage is the single biggest unaddressed risk."
