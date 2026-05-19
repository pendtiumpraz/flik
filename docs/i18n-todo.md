# i18n — Outstanding Translation Work

The multi-language UI shipped with: locale resolution middleware, the
`<x-lang-switcher />` component, `resources/lang/{id,en,ar}.json` starter
dictionaries, RTL CSS shim, on-demand AI translation (`TextTranslator`)
with a `translation_cache` table, and the `/admin/translations` coverage
dashboard.

This file tracks the work that was **deliberately deferred** so the
landing PR stays reviewable. None of it blocks day-to-day usage — every
untranslated string falls through to the literal English/Indonesian text
already in the view.

## What still needs translating

### Header / nav (partially done)
- `resources/views/components/header.blade.php` — top-level nav items and
  the user dropdown wired through `__()`. The Discover sub-menu items
  ("Discover by Mood", "Family Night", "Year in Review", "Bandingkan Film",
  "Cari dengan Foto", etc.) are still literal Indonesian text. Migrate
  each `<span>` to `{{ __('Discover by Mood') }}` and add the matching
  keys to all three `lang/<code>.json` files.

### Mobile menu (same file)
- The mobile collapsible mirrors the desktop nav and has the same
  outstanding strings — `<x-icon … /> Discover`, `Smart Watchlist`,
  `Notifications`, etc. Do desktop + mobile in the same pass to keep them
  in sync.

### Common page chrome
- Auth views: `resources/views/auth/login.blade.php`,
  `resources/views/auth/register.blade.php`,
  `resources/views/auth/forgot-password.blade.php`. Form labels +
  buttons + error toasts.
- Footer (`resources/views/components/footer.blade.php` if it exists)
  — copyright line, legal links.
- Cookie banner (`resources/views/components/cookie-banner.blade.php`)
  — "Accept", "Reject", "Cookie Settings" copy.
- Flash messages (`resources/views/components/flash.blade.php`) —
  controllers flash literal strings; migrate the callers to
  `->with('status', __('Saved'))`.

### Catalog views
- `resources/views/movies/index.blade.php` — section labels (Trending,
  Popular, Top 10), filter labels, empty state.
- `resources/views/movies/show.blade.php` — Watch Now, Add to Watchlist,
  Cast, Synopsis, Reviews tabs. The **synopsis body** is auto-translated
  by `Movie::synopsisForLocale(app()->getLocale())` — no manual work
  needed for catalog content, only for the chrome around it.
- `resources/views/components/velflix-card.blade.php` — hover overlay
  buttons + duration formatting.

### Engagement
- Watchlist (`/my-list`), Rewards (`/rewards`), Notifications, Profile,
  Plans / Subscribe checkout. All ship literal English copy today.

### Admin panel
- `resources/views/components/admin/layout.blade.php` — sidebar section
  labels ("Content", "System", "Intelligence", ...) currently come from
  `config('admin_menu.php')`. Either run them through `__()` in the
  layout, or move to a labels translation map. The latter keeps the
  config file ASCII and lets translators work without touching PHP.
- Every dashboard view under `resources/views/admin/**`. Lower priority
  — admins are a smaller audience and almost all are bilingual.

## RTL — known issues to test
- `text-left` / `text-right` classes are flipped by the CSS shim in
  `layout.blade.php`. Inline `style="text-align:left"` is **not** flipped
  — find-and-replace those.
- Flickity carousels: re-init may be needed when locale switches at
  runtime. Currently a full page reload happens via `POST /locale/{code}`,
  so this is not an active bug.
- Movie cards on the home grid: the gold ribbon (`Top 10` badge) is
  absolutely positioned with `right: 0`. The shim flips this to `left: 0`
  for Arabic — visually correct, but the gradient direction should
  probably mirror too. Cosmetic.
- The Shaka Player UI ships its own LTR controls. Shaka has a built-in
  RTL config (`player.configure({ui: {direction: 'rtl'}})`) — wire it in
  `resources/js/flik-player.js` when the player mounts.

## Process notes
- **Add a key to all three locale files at once.** Missing keys show up
  red in `/admin/translations` and the coverage dashboard tracks them.
- Use `__('Key')` for short labels; use `@lang('Key')` for body copy with
  `:placeholders`. Both end up at the same place.
- Source keys should be the **English string** (Laravel JSON convention).
  This keeps fallback rendering sensible: missing key → returns the key
  itself, which reads as English.
- For dynamic content (movie synopsis, AI reviews) DO NOT add static
  keys — pipe through `TextTranslator::translate()` instead so the AI
  cache earns its keep.
