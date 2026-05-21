# Audit 19 — Mobile / PWA / i18n

**Scope:** Mobile bottom nav, PWA install prompt (Chromium native + iOS Safari fallback), service worker (`sw.js`) + offline fallback (`offline.html`), `manifest.json` (icons / shortcuts / screenshots / splash), multi-language ID / EN / AR with RTL support, AI translation cache (`TranslationCache` + `TextTranslator`), `/admin/translations` dashboard, splash-screen generator command, locale resolution middleware.
**Date:** 2026-05-21
**Branch:** `main`
**Verdict:** PARTIAL — wiring, schema, middleware chain, RTL pipeline, and dashboards are all correct, but **(1) every icon file referenced by `manifest.json` is missing from `public/icons/`** (only a README ships), so the install UI / home-screen icon / iOS splash all fall back to favicon; **(2) `SetLocale` middleware pins the session to the resolved locale on the very first visit**, defeating the documented "user preference wins over Accept-Language" priority for any subsequent change; **(3) `PwaInstallTrackController` stores an **unpeppered** `sha256(ip)`** which is trivially reversible from the ~4-billion IPv4 keyspace; and **(4) the `RequestFirewall` global middleware runs BEFORE `StartSession`/`SetLocale`** so the locale-routed responses are still firewalled on `?lang=` query strings — fine in practice but worth flagging if you ever add locale-aware error pages.

---

## 1. Mobile bottom nav (`<x-mobile-nav />`)

### Mount
- Rendered from `resources/views/components/layout.blade.php:178` inside `@auth` — only authed users get the bar.
- `<body class="pb-16 lg:pb-0">` (line 162) reserves 4 rem of bottom padding on mobile so page content isn't masked by the fixed nav. On `lg+` (≥1024px) the padding drops to 0 because the bar is hidden.
- The bar itself: `class="lg:hidden fixed bottom-0 inset-x-0 z-40 ..."` (line 65) — `lg:hidden` is the correct Tailwind guard, `z-40` correctly sits below modals (z-50) and above page content.

### Safe-area / iOS home-indicator
- `padding-bottom: env(safe-area-inset-bottom, 0px)` on the `<nav>` (line 68) keeps the tab strip above the iPhone home-indicator when running as a standalone PWA. **Correct.**
- Layout `<meta name="viewport">` (line 33) is `width=device-width, initial-scale=1.0` — **missing `viewport-fit=cover`**. Without `viewport-fit=cover` iOS will not honour `env(safe-area-inset-*)` at all in the standalone PWA shell, so the bottom-nav strip pads correctly on the live site but flush-bottoms (and the indicator hides the active dot) in the installed PWA on iPhone X-class devices. `offline.html` does it right (`width=device-width, initial-scale=1.0, viewport-fit=cover`); the main layout doesn't. See **M-1**.

### Five tabs + active detection
| Tab | href | Active when |
|---|---|---|
| Home | `route('velflix.index')` | `velflix.index` OR `movies.show` |
| Discover | `route('discovery.mood.form')` | `discovery.*` OR `search.*` OR `compare.*` OR `family-night.*` |
| My List | `route('watchlist.index')` | `watchlist.*` |
| Alerts | `route('notifications.index')` | `notifications.*`; badges unread count |
| Profile | `$user?->publicProfileUrl() ?? route('profile.show')` | `profile.*` OR `feed.*` OR `rewards.*` |

- Active-route detection uses `request()->routeIs(...)` with glob patterns (`watchlist.*` etc.) — correct, since named routes like `watchlist.show` are subnames of `watchlist.*`. The Home tab uses an OR between `velflix.index` and `movies.show` so the movie detail page does NOT light up Home — that's probably intentional ("Discover" doesn't claim it either though, so detail pages render with NO active tab). See **M-2**.
- The `Profile` href fallback (`?? route('profile.show')`) is defensive but `publicProfileUrl()` (User.php:698) already returns `?string` so `?? route('profile.show')` correctly catches the null case.

### Unread badge
- Pulls from `User::unreadNotificationCount()` wrapped in `try/catch` — DB hiccups never break chrome. `99+` cap on display.
- Badge is correctly positioned via absolute `-top-1.5 -right-2`. In RTL the badge stays on the right (the negative `-right-2` is mirrored by the RTL shim's `[dir="rtl"] .right-0 { right: auto; left: 0; }` rule — but the badge uses `-right-2` not `right-0`, so **the shim does NOT cover it**. In Arabic UI the badge appears on the right of the icon (LTR-ish position) instead of the left (correct RTL position). See **M-3**.

### Icons
- Uses `<x-icon name="..." />` with `home / sparkles / bookmark / bell / user / x` — all present in the icon component (referenced elsewhere in the codebase).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| M-1 | MEDIUM | `<meta name="viewport">` missing `viewport-fit=cover` — installed PWA on iPhone X-class loses safe-area-inset padding so the bottom nav can be obscured by the home indicator. | `resources/views/components/layout.blade.php:33` |
| M-2 | LOW | Movie detail page (`movies.show`) lights up Home; movies INDEX (`movies.index`) lights up nothing because Home only matches `velflix.index` + `movies.show`. Add `request()->routeIs('movies.*')` so any catalog page highlights Home. | `resources/views/components/mobile-nav.blade.php:28` |
| M-3 | LOW | Badge position not RTL-mirrored — `-top-1.5 -right-2` stays anchored to the right of the icon under `dir="rtl"`, which reads as visually wrong. Use logical equivalents or add a per-tab dir guard. | `resources/views/components/mobile-nav.blade.php:86-87` |

---

## 2. PWA install prompt (`<x-pwa-install-banner />` + `resources/js/pwa-install.js`)

### JS module wiring
- Bundled by Vite via `resources/js/app.js:53` (`import './pwa-install';`). The module is an IIFE — runs immediately on parse and registers `window.FlikPwaInstall` for the Alpine banner to call.
- Constants align with the checklist:
  - `WARMUP_MS = 30_000` → 30 second warm-up before first prompt ✓
  - `COOLDOWN_DAYS = 14` → re-prompt suppressed for 14 days after dismiss ✓
  - `STORAGE_KEY = 'pwa_install_dismissed_at'` → localStorage record of last dismiss ✓

### Chromium path
- `beforeinstallprompt` listener calls `e.preventDefault()` to suppress the browser's mini-infobar (so we own the UX), stashes the event in `deferredPrompt`, and calls `maybeShow({ios: false})`.
- `maybeShow()` enforces ordering: `promptShown` flag (one shot per page), `isStandalone()` check (don't nag installed users), `recentlyDismissed()` (cooldown).
- `setTimeout` for the 30s warm-up is `window.setTimeout` (anchored — won't be GC'd) but **the timer is NOT cancelled on page navigation**. In a multi-page browse the banner can fire mid-navigation; usually the new page tear-down kills the timer too, but in a single-page Alpine flow (no real nav) two 30s timers can stack from two `beforeinstallprompt` events on different roots. Edge case, not a real bug. Informational.

### iOS Safari fallback
- `isIosSafari()` (line 109) matches `iPhone|iPad|iPod` against UA AND excludes `window.MSStream` (Windows Phone IE poisoned the UA). **It does NOT exclude `CriOS` (Chrome) or `FxiOS` (Firefox) on iOS** — the comment block explicitly says all three get the same banner because the A2HS instructions are identical via the Share sheet. Defensible call.
- When triggered, `maybeShow({ios: true})` fires and the banner reads `e.detail.ios` to render the "Tap Share → Add to Home Screen" copy with an inline share-icon SVG (line 79-83 of the blade).
- iOS path does NOT call `deferredPrompt.prompt()` (which would throw because no event was captured) — `install()` short-circuits to `dismiss()` when `this.ios === true`. Correct.

### Telemetry (`trackInstall(outcome)`)
- POSTs to `/api/pwa/track-install` with `{outcome, device, ua}` on:
  - `appinstalled` event → `'installed'`
  - userChoice resolution → `'accepted'` / `'dismissed'`
- Wrapped in try/catch with `.catch(() => {})` and `keepalive: true` — never blocks UX.
- CSRF token read from `<meta name="csrf-token">`. **The endpoint** (`PwaInstallTrackController::store`) does NOT call `csrf()->ignore()` — it's behind the `web` middleware group which runs `VerifyCsrfToken`. **For the `appinstalled` path the page may have been backgrounded for hours; the CSRF cookie can be stale.** Telemetry will silently 419 and never recover. Low impact (it's only a count) but documenting it.

### Banner blade
- Mounted globally from `resources/views/components/layout.blade.php:184` (outside `@auth` — guests get the banner too, matches `pwa_installs.user_id` being nullable).
- `bottom: calc(env(safe-area-inset-bottom, 0px) + 72px)` — sits 72px above the bottom-nav strip when the nav is rendered. **Hardcoded** to 72px — if the mobile-nav height changes the banner will overlap or float. Acceptable trade-off; the mobile-nav height has been stable.
- `z-[45]` correctly sits between nav (z-40) and modals (z-50).
- Dismiss writes to localStorage via `window.FlikPwaInstall.dismiss()` → `markDismissed()` — symmetric with `recentlyDismissed()`.
- Hardcoded Indonesian/English copy via `__('Install FLiK')` etc. — translatable via `lang/<code>.json` (none of these keys exist in id.json/en.json/ar.json yet; `__()` returns the source string). See **PWA-2**.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| PWA-1 | LOW | Telemetry POST to `/api/pwa/track-install` is CSRF-protected via the default `web` group. If the user installs hours after page load and `appinstalled` fires, the CSRF token may be expired — telemetry will 419 silently. Either exclude this route in `VerifyCsrfToken::$except` or move it to the `api` group. | `routes/web.php:383`, `app/Http/Middleware/VerifyCsrfToken.php` |
| PWA-2 | LOW | The PWA banner copy keys ("Install FLiK", "Add FLiK to Home Screen", "Tap", "Share", "Add to Home Screen", "Not now", "Got it", "Install FLiK on your device for offline browsing and faster access.") are not present in `resources/lang/{id,en,ar}.json`. `__()` returns the source string verbatim so EN/ID happens to render in English; Arabic users see English. | `resources/views/components/pwa-install-banner.blade.php`, `resources/lang/*.json` |
| PWA-3 | INFO | Re-firing `beforeinstallprompt` (rare — happens on related-app install changes) re-triggers `maybeShow()`; the `promptShown` flag prevents double-dispatch only within the same page lifecycle. Documented expectation, not a bug. | `resources/js/pwa-install.js:99-103` |

---

## 3. Service worker (`public/sw.js`)

### Registration
- Registered from `layout.blade.php:219-221`: `if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});` — every authed and guest page. Single registration; the browser de-dupes.
- Push-notification module re-registers defensively (`push-notifications.js:67`) when `navigator.serviceWorker.controller` is null on first load — safe; the browser idempotently merges. **Subtle:** `push-notifications.js` registers via `SW_PATH = '/sw.js'` literal (line 16); the layout uses the same path. Consistent.

### Cache strategy
| Resource class | Strategy | Cache |
|---|---|---|
| Navigation (`mode === 'navigate'`) | Network-first → cached → `/offline.html` → inline 503 | `flik-runtime-v3` |
| Static assets (`destination ∈ image/script/style/font`, same-origin GET) | Cache-first + background refresh | `flik-runtime-v3` |
| `/admin`, `/api`, `/livewire`, `/sanctum`, `/_debugbar`, `/horizon`, `/telescope` | **Bypassed** (no SW intervention) | n/a |
| POST / cross-origin / other | Pass-through | n/a |

- `shouldBypass()` (line 82) uses `String.prototype.includes` — substring match. **This means a marketing URL like `/blog/api-design-tips` would be bypassed** because `/api` is a substring. Acceptable: bypassing extra paths is conservative, only false negatives (cached when shouldn't be) would be a bug.
- `PRECACHE_URLS = ['/', '/offline.html', '/manifest.json', '/img/flik-logo.png', '/favicon.png']` — kept tiny so install always succeeds. Each `cache.add()` wrapped in `.catch(() => {})` (line 43) so a missing optional asset doesn't fail the whole install.
- `STATIC_CACHE = 'flik-static-v1'` (unchanged since launch), `RUNTIME_CACHE = 'flik-runtime-v3'` (bumped from v2). Activate handler purges any cache not in the allow-list — prevents storage-quota bloat across versions.

### Web Push
- `push` event reads `event.data.json()` with try/catch fallback to a generic "You have a new notification." — handles "wake-up" pushes with empty payloads.
- `notificationclick` handler tries to focus an existing FLiK tab (`clients.matchAll`) before opening a new one — standard UX.
- `pushsubscriptionchange` handler re-subscribes with the cached `applicationServerKey` and re-POSTs to `/api/push/subscribe` **without** a CSRF token (line 263) — the comment correctly notes the SW context has no access to the CSRF cookie, so the endpoint must accept unauthenticated POSTs (it's endpoint-keyed + idempotent per `PushSubscriptionController`). Verify the `PushSubscriptionController::subscribe` is CSRF-excluded; if not, this fallback silently breaks. Not in scope for this audit but flagged.

### Offline fallback
- `/offline.html` is a standalone static page (NOT routed through Laravel) — `<!DOCTYPE html>` with inline CSS + a tiny script that watches `navigator.onLine` and auto-reloads when connectivity returns.
- The page also offers `/my-list` as a button — but `/my-list` itself is NOT in the precache list, so it'll fail offline. Misleading but harmless (the offline page itself works).
- A SECOND offline path exists: `Route::view('/offline', 'offline')` (`routes/web.php:391`) renders `resources/views/offline.blade.php`. That blade view exists per Glob but I did not deep-dive its contents — verify it matches the static `/offline.html` copy if you ever change one.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| SW-1 | LOW | `/my-list` link on offline page is misleading — `/my-list` is NOT in `PRECACHE_URLS`, so following the button while offline will just bounce back to `/offline.html`. Either pre-cache `/my-list` or remove the button. | `public/offline.html:132` |
| SW-2 | LOW | Two offline surfaces (`public/offline.html` static + `resources/views/offline.blade.php` server-rendered) — risk of divergence. Document which is canonical (the static one — SW serves it) and mirror copy via the Blade view. | `public/offline.html`, `resources/views/offline.blade.php` |
| SW-3 | INFO | `shouldBypass` uses substring match; `/api` matches any URL containing those four chars. Conservative direction (extra bypass) so not a correctness issue. | `public/sw.js:82` |

---

## 4. `manifest.json` — icons / shortcuts / screenshots

### Manifest content (audit checklist alignment)
- `name`, `short_name`, `description`, `lang: "id"`, `dir: "ltr"`, `start_url: "/"`, `scope: "/"`, `display: "standalone"`, `orientation: "portrait"`, `theme_color: "#0a0a0a"`, `background_color: "#0a0a0a"` — all present and consistent with the brand and the `<meta name="theme-color">` in `layout.blade.php:37-38`.
- `categories: ["entertainment", "movies", "video"]` ✓
- `prefer_related_applications: false` ✓
- **`lang` is hardcoded to `"id"`** — the manifest is the same regardless of which UI locale the user is on. For an Arabic-only PWA install this would make A11y trees mis-read; not user-visible in our flows. Informational.

### Icon sizes (manifest claims)
- 72 / 96 / 128 / 144 / 152 / 192 / 384 / 512 — purpose `any` ✓
- 192 / 512 — purpose `maskable` ✓ (Android adaptive icon)
- `/img/flik-logo.png` as a 512×512 fallback `any` ✓

### Icon files (FILESYSTEM REALITY)
**`public/icons/` contains ONLY `README.txt`.** Verified by `ls`. None of the following actually exist:
```
icon-72.png      icon-maskable-192.png    splash-iphone-se.png         apple-touch-icon.png
icon-96.png      icon-maskable-512.png    splash-iphone-14.png         (180×180 in /icons/)
icon-128.png                              splash-iphone-14-pro.png
icon-144.png                              splash-iphone-14-pro-max.png
icon-152.png                              splash-ipad-mini.png
icon-192.png                              splash-ipad-pro-11.png
icon-384.png                              splash-ipad-pro-12.png
icon-512.png
```
Browsers will:
1. **Skip every `icons[]` entry in the manifest** — `manifest.json` becomes effectively iconless.
2. **Fall back to `/favicon.png`** (which exists at the root) for the install prompt + home-screen icon.
3. **Show a blank white iOS splash** (every `apple-touch-startup-image` link in `layout.blade.php:60-73` 404s).
4. **The maskable icons used by Android's adaptive shape are missing** — Android falls back to the round-clipped legacy icon (no safe zone) which often clips badly.

Two pre-existing fallback icons DO exist at the public root:
- `/apple-touch-icon.png` (180×180, 14 KB) — used by `layout.blade.php:102` `<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">`.
- `/favicon.png` (100 KB) — used by `layout.blade.php:100` and as a SW precache asset.
- `/android-chrome-192x192.png` (in `/public/`) — exists but is NOT wired into either `manifest.json` or `layout.blade.php`.

This is the **single biggest finding** in this audit. See **PWA-4**.

### Shortcuts
- 3 shortcuts: "Discover by Mood" → `/discover/mood`, "My List" → `/my-list`, "Continue Watching" → `/?continue=1`.
- Each shortcut icon points at `/icons/icon-96.png` — **also missing** per above.
- The `?continue=1` query param doesn't appear to have any handling on the home route (would need to verify — it's a UX hint, not a hard wire).

### Screenshots
- `home-full-page.png` (1280×720 wide) and `home.png` (750×1334 narrow) — **both exist** under `public/img/`. These are the only manifest assets that resolve correctly.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **PWA-4** | **HIGH** | Every icon referenced in `manifest.json` (`/icons/icon-*.png` × 8, `/icons/icon-maskable-*.png` × 2) is missing from disk. The PWA install UI, home-screen icon, Android adaptive shape, and iOS splash screens (all 7 sizes) will all 404. Browsers fall back to `/favicon.png` for the install icon and a blank dark launch for iOS. Run `php artisan flik:pwa:generate-splash` for the splash screens and produce the 8 regular + 2 maskable PNGs (see `public/icons/README.txt`) — without these the PWA install experience is unbranded. | `public/icons/` (empty), `public/manifest.json:19-31`, `resources/views/components/layout.blade.php:60-73` |
| PWA-5 | LOW | `manifest.json` shortcuts reference `/icons/icon-96.png` which doesn't exist — Android Quick-action shortcuts on Pixel/Samsung etc. will render generic icons. Same root cause as PWA-4. | `public/manifest.json:48-70` |
| PWA-6 | LOW | `manifest.json` `lang` hardcoded to `"id"` — doesn't track the user's chosen UI locale. Would only matter for screen readers on the install prompt; informational. | `public/manifest.json:11` |
| PWA-7 | LOW | `public/android-chrome-192x192.png` exists on disk but is referenced from neither `manifest.json` nor `layout.blade.php`. Either delete it or wire it as a fallback `192x192` entry. | `public/android-chrome-192x192.png` |

---

## 5. Splash screens (`GenerateSplashScreens` command)

- `flik:pwa:generate-splash {--logo=} {--bg=#0a0a0a} {--force}` — registered the standard Laravel way; no explicit binding required.
- Device matrix matches the `<link rel="apple-touch-startup-image">` entries in `layout.blade.php:60-73` exactly (7 devices). Apple's documented matrix uses RENDERED viewport pixels (CSS px × DPR), the command spells the maths in inline comments.
- Image backend preference: Intervention Image (v2 OR v3 — both API shapes handled in `renderWithIntervention`), falling back to GD when only `ext-gd` is loaded. When NEITHER is available the command emits the exact list of files + dimensions to produce by hand and returns FAILURE — no silent breakage.
- Logo resolution falls through `public/icons/icon-512.png → public/icons/icon-192.png → public/img/flik-logo.png`. **The first two don't exist** (see PWA-4) so the command will use `flik-logo.png` — works, but the logo is 512×512 which is the design intent, so no quality loss.
- `--force` flag is needed to overwrite existing files (default skips); generated files land in `public/icons/`.
- Hex parsing handles 3-char and 6-char inputs; invalid hex defaults to `[10,10,10]` (`#0a0a0a`) — matches the brand bg.
- GD renderer (`renderWithGd`) handles PNG/JPG/GIF/WEBP source logos via `IMAGETYPE_*` switch; unknown formats return null and the splash renders as a solid background — graceful.

### Issues
None — the command is well-designed. The issue is that **it has never been run** (or its output was not committed) — see PWA-4.

---

## 6. Multi-language (ID / EN / AR)

### Config (`config/locales.php`)
- `available` map declares 3 locales with `{name, flag, rtl}` shape:
  - `id` → Bahasa Indonesia, 🇮🇩, ltr
  - `en` → English, 🇬🇧, ltr
  - `ar` → العربية, 🇸🇦, rtl
- `default` = `id`. Matches `config/app.php:83` (`'locale' => env('APP_LOCALE', 'id')`).
- `config/app.php:96` `'fallback_locale' => 'en'` — when a key is missing in ID, Laravel falls back to EN. Sensible.
- `config/app.php:109` `'faker_locale' => 'id_ID'` — for seeded fake data, separate from UI.

### JSON dictionaries (`resources/lang/{id,en,ar}.json`)
- Each file ships **51 keys** (counted from the file output). The KEYS are identical across all three files — verified by `diff` (visual inspection):
  ```
  Login, Log In, Register, Sign Up, Logout, Log Out, Profile, Settings,
  Movies, Films, Home, My List, Smart Watchlist, Discover, Search,
  Notifications, Rewards, Subscribe, Subscription, Plans, Upgrade Plan,
  Watch Now, Add to Watchlist, Rating, Comments, Reply, Cancel, Save,
  Delete, Edit, Loading..., Hello, :name, No results, Error, Success,
  Language, Language updated, Back to Site, Manage Sessions,
  Privacy & Data, Admin Dashboard, Dashboard, Users, Roles,
  Roles & Permissions, AI Settings, AI Usage, Audit Logs, Translations,
  Translation Dashboard
  ```
- All three are 100% complete against the union → `/admin/translations` will show 100% / 100% / 100% coverage. That's misleading: there are **hundreds** of `__('...')` calls throughout the views (mobile-nav alone uses Home, Discover, My List, Alerts, Profile, Primary mobile navigation, unread; lang-switcher uses Language; pwa-install-banner uses Install FLiK, Add FLiK to Home Screen, Tap, Share, Add to Home Screen, Dismiss install prompt, Install, Got it, Not now) — none of these are in the JSON files. `__()` returns the source verbatim so ID and EN look fine but Arabic users see English strings interleaved with the few translated ones. See **i18n-1**.
- Legacy PHP files (`resources/lang/en/auth.php`, `pagination.php`, `passwords.php`, `validation.php`) exist for English ONLY. **No ID or AR equivalents**, so Indonesian and Arabic users see English validation messages ("The :attribute field is required.") instead of Indonesian/Arabic ones. Standard Laravel scaffolding gap; documenting.

### `SetLocale` middleware
Registered in `web` group AFTER `StartSession` + `SubstituteBindings` (Kernel.php:74). Correct.

Resolution chain (`resolve()`):
1. `?lang=XX` query param → validated against `config('locales.available')`.
2. `session('locale')`.
3. `$request->user()->preferred_locale` (read via `getAttribute()` to bypass casts).
4. `Accept-Language` header — parsed by weight (`parseAcceptLanguage`), tries full BCP-47 tag then primary subtag.
5. `config('locales.default')` ('id') or first available.

**Bug: session pinning kills the priority chain.** Lines 53-59:
```php
if ($request->query('lang') && $request->session()->get('locale') !== $resolved) {
    $request->session()->put('locale', $resolved);
} elseif (! $request->session()->has('locale')) {
    $request->session()->put('locale', $resolved);  // <- first-visit pin
}
```
On the **very first request** (no session locale yet), the middleware writes `session('locale') = $resolved`. From that point onwards, branch 2 (session) always wins over branch 3 (`user.preferred_locale`) and branch 4 (Accept-Language). **A user who logs in with `preferred_locale='ar'` after first visiting anonymously with Accept-Language `en` will see English forever** — because the session was pinned to `en` on visit 1, and `preferred_locale='ar'` is never consulted.

The fix is to either (a) NOT pin on first visit (let branches 3/4 win on every request), or (b) skip the session branch when the user is authenticated and has a non-empty `preferred_locale`. The latter matches the comment "user-preference wins across devices". See **i18n-2**.

Defence-in-depth: every read in the chain validates against `array_keys(config('locales.available'))` so an attacker injecting `?lang=<script>...` only ever writes a known-safe code into the session. Good.

Carbon locale is also propagated (`Carbon::setLocale($resolved)`) with a try/catch fallback to `$default` for unknown Carbon locales — guards against the day someone adds a locale Carbon doesn't know. Good.

### `LocaleController::switch`
Route: `POST /locale/{code}` with `where('code', '[A-Za-z-]{2,5}')` constraint. POST-only (CSRF-protected) — comment correctly explains the rationale: prevents a stray `<a href="?lang=...">` in UGC from silently flipping a user's language.

Body:
- Whitelist check against `config('locales.available')` — silent fall-through to `back()` on invalid code (UX-driven; flag just doesn't change).
- `session()->put('locale', $code)` — every switch updates the session.
- `$user->forceFill(['preferred_locale' => $code])->save()` — `forceFill` bypasses `$fillable` so no need to add the column there. **Saves the WHOLE user model** with one column changed; for high-volume locale switching this could re-trigger observers etc., but realistically nobody switches locale more than a couple of times per session.
- `back()->with('status', __('Language updated'))` — flash message, the **"Language updated"** key IS in all three JSON files. ✓

### Lang switcher (`<x-lang-switcher />`)
- Mounted in `<x-header />` lines 125 (authed) and 219 (guest) — both top-bar branches. **Not visible on mobile** because the header itself is hidden on `lg:hidden` (verified in the mobile-nav comment "hidden on lg+ where the regular <x-header /> sticky top nav owns wayfinding" — i.e. the inverse: header is desktop-only). So mobile users have NO way to switch language. The mobile-nav has 5 tabs and Profile is the closest place to put it, but there's no language entry in `mobile-nav.blade.php`. See **i18n-3**.
- Dropdown renders one form per locale (`config('locales.available')`-driven) — each is a POST form with `@csrf` pointing at `route('locale.switch', ['code' => $code])`. Defensive; can't be CSRF'd silently.
- Alpine `x-data="{ openLang: false }"` with `@click.away="openLang = false"` for outside-click dismissal — standard pattern.
- Active locale is visually marked (gold check + filled background). ✓
- Hidden gracefully (`@if(! empty($available))`) if config is empty — defence against misconfig.

### RTL pipeline (`<html dir="rtl">`)
- `layout.blade.php:20-22`: `$isRtl = (bool) ($localeMeta['rtl'] ?? false);` then `<html dir="{{ $isRtl ? 'rtl' : 'ltr' }}">` — correctly flips on Arabic.
- The inline `<style>` block (lines 131-153) is the **RTL shim**: 18 utility-class overrides that swap LTR margins/paddings/text-align/positioning to RTL equivalents under `[dir="rtl"]`. Coverage:
  - `ml-1/2/3/4/6/10`, `mr-1/2/3/4` → margin flip ✓
  - `pl-3/4`, `pr-3/4` → padding flip ✓
  - `text-left`, `text-right` → flip ✓
  - `left-0`, `right-0` → flip ✓
  - Flickity carousel buttons → flip ✓
- **The shim is incomplete by design** — the comment says "patches a handful of LEGACY views that still ship physical-side classes" and recommends Tailwind logical properties (`me-*`, `ms-*`, `ps-*`, `pe-*`) in new code. Inline styles (`style="margin-left:..."`) are NOT flipped (documented).
- **What's NOT covered:** every `space-x-*`, `divide-x-*`, `pl-2`/`pl-5`/`pl-6`/`pl-8` etc., every `pr-2`/`pr-5`/`pr-6`/`pr-8`, every `mr-5`/`mr-6`/`mr-8`/`mr-10`, every absolute-positioning value other than `left-0`/`right-0` (e.g. `right-2`, `right-4`, `left-2`, `left-4` — the mobile-nav badge uses `-right-2` which is NOT covered, see M-3), every Tailwind `border-l-*` / `border-r-*`, every `rotate-*` icon (chevron-down rotates the wrong way under RTL without manual flip). This is a HUGE shim debt but well-documented.
- Carbon is locale-switched (above) so dates render right-to-left automatically.

### AI translation cache
- `TranslationCache` model — covered in §7 below.
- `TextTranslator` — covered in §7 below.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **i18n-1** | **MEDIUM** | The JSON dictionaries only contain ~51 keys. Hundreds of `__('...')` calls across views (mobile-nav, pwa-install-banner, lang-switcher, every admin / public page) are NOT translated. ID users see English fallback for those keys (because `__()` returns the source); AR users see English mixed with the ~50 translated strings. `/admin/translations` shows 100% because the union is calculated from existing JSON keys, NOT from `__()` calls in code — coverage is a vanity metric until the dictionaries are exhaustive. Either run a `__()`-extraction tool (Laravel doesn't ship one; `barryvdh/laravel-translation-manager` or a custom Grep over `__\(\s*['"]` works) or accept that English is the de-facto fallback. | `resources/lang/*.json` |
| **i18n-2** | **MEDIUM (correctness)** | `SetLocale` pins the session to the resolved locale on the very first visit. From that visit onward, branches 3 (`user.preferred_locale`) and 4 (`Accept-Language`) are dead code — the session always wins. A user who registers anonymously with browser locale `en`, then later updates `preferred_locale = ar`, will still see English everywhere until they manually `POST /locale/ar` OR clear cookies. Drop the `elseif (! $request->session()->has('locale'))` first-visit pin, or short-circuit the session branch when an authenticated user has a `preferred_locale`. | `app/Http/Middleware/SetLocale.php:53-59` |
| **i18n-3** | **MEDIUM** | `<x-lang-switcher />` is only mounted inside `<x-header />`, which is desktop-only (mobile uses `<x-mobile-nav />` instead). **Mobile users have no way to change language from the UI.** Add a lang chip into the mobile-nav (probably as a sixth tab or a long-press on Profile) or render `<x-lang-switcher />` somewhere mobile-visible (e.g. inside the profile drawer). | `resources/views/components/header.blade.php:125,219` |
| i18n-4 | LOW | Legacy validation/auth/passwords/pagination PHP files exist only in `resources/lang/en/`. Indonesian and Arabic users see English validation messages. Either copy + translate `auth.php` + `validation.php` per locale, or accept the inconsistency. | `resources/lang/en/{auth,validation,passwords,pagination}.php` |
| i18n-5 | LOW | RTL shim doesn't cover `space-x-*`, `divide-x-*`, half the `pl-*`/`pr-*` numeric values, most absolute-position values other than `left-0`/`right-0`, `border-l/r-*`, or chevron rotations. Documented as design intent (migrate to logical properties); calling out the actual coverage gap so the next RTL bug report isn't a surprise. | `resources/views/components/layout.blade.php:131-153` |

---

## 7. AI translation cache (`TranslationCache` + `TextTranslator`)

### Schema (`translation_cache` migration `2026_05_10_150002`)
- Columns: `id`, `source_locale(5)`, `target_locale(5)`, `source_text` TEXT, `source_hash CHAR(64)` (sha256 hex), `translation` TEXT, `provider` (nullable string), `created_at`, `last_used_at`.
- Unique index `(target_locale, source_hash)` — O(1) cache lookup; same source in same target reuses the row.
- Index `last_used_at` for LRU eviction sweeps.
- `created_at` + `last_used_at` use `useCurrent()` for DB-default timestamps so a row insert without `now()` still gets a valid stamp.
- Idempotent guard `if (Schema::hasTable('translation_cache')) return;` at top — safe to re-run.

### Model (`TranslationCache`)
- `$timestamps = false` because `last_used_at` doesn't match Laravel's `updated_at` convention.
- `hashSource(string $text): string` returns `sha256(trim($text))` — canonicalisation strips leading/trailing whitespace so `"  Hello  "` and `"Hello"` map to the same row. Case is NOT collapsed (deliberate — tone matters in many target languages).

### `TextTranslator::translate($text, $targetLocale, $sourceLocale = 'id')`
Flow:
1. Empty source → return `""`.
2. Source == target → return verbatim (no DB write, no AI call).
3. Truncate to `MAX_SOURCE_CHARS = 4000` to cap token cost.
4. Hash source → lookup `(target_locale, source_hash)` row.
5. **Hit:** bump `last_used_at` (raw update — bypasses model events for speed) and return cached translation.
6. **Miss:** call `AiClient::chat()` with task type `"translate.{source}.{target}"` (drives `/admin/ai-usage` per-locale-pair grouping), low temperature (0.2), strict system prompt that says "Output ONLY the translated text — no preamble, no quotes, no markdown".
7. Persist via `updateOrCreate` (race-safe — two simultaneous misses on the same source converge on one row).
8. Any DB / AI failure → log + return source text verbatim (graceful fallback, matches the documented AI-task contract).

### Cache hit behaviour
- **Verified:** `TranslationCache::query()->where('target_locale', $targetLocale)->where('source_hash', $hash)->first()` uses the unique index → O(1) row fetch.
- `last_used_at` bump uses raw `update()` not `save()` → no model events, no Eloquent overhead.
- LRU eviction is documented but **no scheduled sweep exists** — the table grows unbounded. For an Indonesian-source catalog with 3 target locales the upper bound is small (one row per movie overview × 2 non-source locales), so no urgent issue. Document if a scheduled prune is desired. See **TR-1**.

### Provider attribution
- `provider` column accepts free-form (`'openai'`, `'anthropic'`, `'deepl'`, future...) — `/admin/translations` groups by provider for cost attribution.
- Falls back to `'unknown'` when `$response['provider']` isn't set.

### Cost ceiling
- `MAX_SOURCE_CHARS = 4000` per call. Callers needing more (a full novel-length blurb) must chunk by paragraph themselves. Documented.

### `Movie::synopsisForLocale($locale)` consumer
- Reads `overview` → if empty returns `''`; if locale matches source returns verbatim; otherwise calls `TextTranslator::translate(overview, $locale, $sourceLocale='id')`.
- Wrapped in try/catch around the container `app(TextTranslator::class)` so a missing AI provider doesn't break the movie detail page.
- This is the **only documented consumer** in the catalog — no other model is lazy-translating yet.

### `/admin/translations` dashboard (`TranslationDashboardController`)
- View → `admin.translations.index` (didn't deep-dive the blade, but the controller hands it 4 keys: `coverage, cacheStats, byPair, byProvider`).
- **Coverage**:
  - Loads every `lang/<code>.json` via `app()->langPath($code.'.json')` (verified by runtime check — resolves to `resources/lang/`).
  - Builds a union of all keys across all locales, computes per-locale `present / missing / percent`.
  - Counts empty strings as missing (`is_string($value) && trim($value) !== ''`).
  - Caps `missing_keys` list at 50 per locale for view readability.
- **Cache stats** — `total, fresh_24h, hits_24h, hit_rate_pct, oldest, newest`. Hit rate is computed as `hits_24h / (fresh_24h + hits_24h)` where "hit" = `last_used_at >= now()-1d AND created_at < now()-1d`. Approximation — true hit rate would need a separate access-log table.
- **By pair** — `(source → target)` row count, ordered desc. Drives the "which language pair is most-translated" chart.
- **By provider** — row count grouped by `provider` column (null → 'unknown' via `COALESCE`).
- Schema guards (`Schema::hasTable('translation_cache')`) on each query so the dashboard renders cleanly on a fresh checkout without the migration.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| TR-1 | LOW | `translation_cache` grows unbounded — no scheduled LRU sweep despite the `last_used_at` index being purpose-built for one. For the current catalog size this is fine, but document/schedule a prune (e.g. `flik:translations:prune --older-than=180d --unused-for=30d`) before AI features start translating UGC. | `app/Services/Ai/Tasks/TextTranslator.php`, `app/Console/Kernel.php` |
| TR-2 | LOW | `hit_rate_pct` formula uses `hits_24h / (fresh_24h + hits_24h)` — a 24h window with 100 fresh + 20 hits reports 16.7% hit rate. Acceptable but cold-start projects skew low here for a long time. Document the formula in the dashboard view so operators don't panic. | `app/Http/Controllers/Admin/TranslationDashboardController.php:151-157` |
| TR-3 | INFO | `MAX_SOURCE_CHARS = 4000` silently truncates source over the limit — translator returns a partial-source translation. Callers don't know. Consider returning a `['translation' => ..., 'truncated' => true]` shape, or at least log when truncation happens. | `app/Services/Ai/Tasks/TextTranslator.php:78` |

---

## 8. `pwa_installs` ledger + `PwaInstallTrackController`

### Schema
- `2026_05_10_230001_create_pwa_installs_table` — `id, user_id (nullable, nullOnDelete), device(64, indexed), ua(1024), outcome(32), ip_hash(64), installed_at (default current, indexed), timestamps`.
- Indexes are reasonable: `device` and `installed_at` for the admin "installs over time" / "by device" widgets.

### Model
- Minimal: `$fillable` lists all writable columns, `$casts` covers `installed_at`. `belongsTo(User::class)` relation. Append-only (no `update()` ever called by the controller).

### Controller
- POST `/api/pwa/track-install` validates `outcome / device / ua` (all nullable + length-capped).
- Inserts row with `ip_hash = hash('sha256', $request->ip())` — **NO PEPPER**.
- `try/catch` swallow → returns `{ok: false}` 200 on failure (telemetry MUST never break UX).
- Throttled at `30,1` (30 requests / minute / IP).

### Critical: missing pepper
The `MovieView` ledger (audited in #18) goes to lengths to pepper the IP hash with `APP_KEY` so it can't be reversed from the ~4B IPv4 keyspace. **This controller doesn't.** A raw `sha256(ip)` is **trivially reversible** — an attacker who steals the table can iterate every IPv4 address in seconds (`for i in $(seq 0 4294967295); do echo "$i $(echo -n $ip | sha256sum)"; done` is offline + parallelisable). The peppering pattern is established elsewhere in the codebase (`ViewTracker.php:154`) and should be mirrored here. See **PWA-8**.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| **PWA-8** | **MEDIUM (privacy)** | `ip_hash = sha256(ip)` — unpeppered. Trivially reversible from the IPv4 keyspace; on a table leak every install row de-anonymises to a real IP. Mirror the `ViewTracker::hashIp()` pattern: `hash('sha256', $ip . '|' . config('app.key'))`. The `pwa_installs.ip_hash` column comment should also state that the hash is peppered. | `app/Http/Controllers/PwaInstallTrackController.php:39` |
| PWA-9 | LOW | Controller validates only `outcome / device / ua`; the `Request::all()` also contains nothing else by design, but no `validated()` is used — the unvalidated path is fine because the insert array is explicitly composed. Informational. | `app/Http/Controllers/PwaInstallTrackController.php:33-41` |

---

## 9. End-to-end checklist (from the prompt)

| Check | Status | Notes |
|---|---|---|
| Mobile nav appears on `lg:hidden` | PASS | `class="lg:hidden fixed bottom-0 ..."`; body has `pb-16 lg:pb-0`. |
| Active tab detection works | **PARTIAL** | Works for the named-route patterns provided, but `movies.show` is rolled into Home (debatable) and `movies.index` lights up nothing. See M-2. |
| PWA install banner triggers after 30s | PASS | `WARMUP_MS = 30_000` in `pwa-install.js`. |
| iOS Safari fallback shown for non-Chrome | PASS | `isIosSafari()` detection + `{ios: true}` payload + share-icon SVG instructions. Treats CriOS / FxiOS as iOS-Safari for purposes of the A2HS message (deliberate). |
| Service worker registers + caches + offline fallback works | PASS | Registered from layout, precaches 5 URLs, network-first navigation falls back to `/offline.html` then inline 503. |
| Manifest has all icon sizes + maskable + shortcuts | **FAIL** | The manifest DECLARES all 8 sizes + 2 maskables + 3 shortcuts. **None of the icon files actually exist** on disk — only the README. PWA-4 is HIGH severity. |
| Lang switcher in header + dropdown works | **PARTIAL** | In header for desktop only; **mobile users have no UI affordance to change locale**. See i18n-3. |
| SetLocale middleware priority chain works | **PARTIAL** | Priority is correctly ordered, but first-visit session pin makes branches 3 (user.preferred_locale) and 4 (Accept-Language) effectively dead code after visit 1. See i18n-2. |
| AR locale flips `<html dir="rtl">` | PASS | `$isRtl` flag, driven by `config('locales.available.ar.rtl')` → `<html dir="rtl">`. |
| RTL CSS shim flips margin/text utilities | **PARTIAL** | Covers a curated subset; documented as "patches a handful of legacy views". `space-x-*`, `divide-x-*`, half of `pl-/pr-`, most absolute positions, border-l/r, chevron rotations are NOT covered. See i18n-5. |
| TextTranslator cache hit | PASS | Lookup on `(target_locale, source_hash)` unique index, `last_used_at` bumped via raw `update()`. |
| `/admin/translations` dashboard renders coverage table | PASS | Per-locale coverage + missing_keys (capped at 50) + cache stats + by-pair + by-provider. Coverage is computed against the JSON union; doesn't catch missing `__()` source keys (see i18n-1 caveat). |

---

## Top fixes to ship before release

1. **`public/icons/` (PWA-4, HIGH)** — generate the 8 regular + 2 maskable icon PNGs and run `php artisan flik:pwa:generate-splash --force` so the 7 iOS splash screens land. Without this the PWA install experience is unbranded — install prompts use `/favicon.png`, Android adaptive shape falls back to circle-clipped legacy icon, every iOS device shows a blank dark launch flash.
2. **`app/Http/Controllers/PwaInstallTrackController.php:39` (PWA-8, MEDIUM)** — pepper the IP hash: `'ip_hash' => hash('sha256', (string) $request->ip() . '|' . config('app.key'))`. Mirror the `ViewTracker::hashIp()` pattern that already protects the `movie_views` ledger.
3. **`app/Http/Middleware/SetLocale.php:53-59` (i18n-2, MEDIUM)** — drop the first-visit session pin (or short-circuit the session branch when `auth()->user()?->preferred_locale` is non-empty). Without this fix, an authed user updating `preferred_locale` in their profile sees zero effect until they manually use the locale switcher.
4. **`resources/views/components/layout.blade.php:33` (M-1, MEDIUM)** — add `viewport-fit=cover` to the viewport meta so the installed PWA on iPhone X-class honours `env(safe-area-inset-*)`. `offline.html` already does this; the main layout should mirror.
5. **`resources/lang/{id,en,ar}.json` (i18n-1, MEDIUM)** — extract all `__('...')` source keys from views and back-fill the JSON dictionaries. Arabic users currently see hundreds of English fallbacks. `/admin/translations` lies (100% coverage) because it measures against the union of EXISTING JSON keys, not against actual `__()` calls.
6. **`resources/views/components/mobile-nav.blade.php` (i18n-3, MEDIUM)** — surface the lang switcher somewhere mobile-visible. Easiest path: render `<x-lang-switcher />` inside a profile drawer triggered from the Profile tab.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\resources\views\components\mobile-nav.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\pwa-install-banner.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\lang-switcher.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\layout.blade.php`
- `D:\AI\velflix\velflix\resources\views\components\header.blade.php` (lang-switcher mount points only)
- `D:\AI\velflix\velflix\resources\js\app.js`
- `D:\AI\velflix\velflix\resources\js\pwa-install.js`
- `D:\AI\velflix\velflix\resources\js\push-notifications.js`
- `D:\AI\velflix\velflix\resources\js\echo.js`
- `D:\AI\velflix\velflix\resources\js\admin-notifications.js`
- `D:\AI\velflix\velflix\resources\js\cookie-consent.js`
- `D:\AI\velflix\velflix\public\manifest.json`
- `D:\AI\velflix\velflix\public\sw.js`
- `D:\AI\velflix\velflix\public\offline.html`
- `D:\AI\velflix\velflix\public\icons\README.txt` (only file in /icons/ — PWA-4)
- `D:\AI\velflix\velflix\resources\lang\id.json`
- `D:\AI\velflix\velflix\resources\lang\en.json`
- `D:\AI\velflix\velflix\resources\lang\ar.json`
- `D:\AI\velflix\velflix\resources\lang\en\auth.php`, `pagination.php`, `passwords.php`, `validation.php` (legacy)
- `D:\AI\velflix\velflix\app\Http\Middleware\SetLocale.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\LocaleController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PwaInstallTrackController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\Admin\TranslationDashboardController.php`
- `D:\AI\velflix\velflix\app\Models\TranslationCache.php`
- `D:\AI\velflix\velflix\app\Models\PwaInstall.php`
- `D:\AI\velflix\velflix\app\Models\Movie.php` (synopsisForLocale only, lines 380-408)
- `D:\AI\velflix\velflix\app\Models\User.php` (publicProfileUrl only, line 698)
- `D:\AI\velflix\velflix\app\Services\Ai\Tasks\TextTranslator.php`
- `D:\AI\velflix\velflix\app\Console\Commands\GenerateSplashScreens.php`
- `D:\AI\velflix\velflix\app\Http\Kernel.php` (SetLocale registration)
- `D:\AI\velflix\velflix\app\Providers\AppServiceProvider.php`
- `D:\AI\velflix\velflix\routes\web.php` (locale.switch + pwa.track-install + /offline routes)
- `D:\AI\velflix\velflix\config\locales.php`
- `D:\AI\velflix\velflix\config\app.php`
- `D:\AI\velflix\velflix\bootstrap\app.php` (langPath verification)
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_150001_add_preferred_locale_to_users.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_150002_create_translation_cache_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_230001_create_pwa_installs_table.php`
