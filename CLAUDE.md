# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project identity (read this first)

The project has three names floating around — they all refer to the same app:
- **Brand**: "FLiK — Rumah Sinema Indonesia" (Indonesian streaming platform). Used in README, views, and `APP_NAME`.
- **Composer package**: `flik/flik`.
- **Code namespace / class names**: still use `Velflix` (e.g., `VelflixController`, `SearchVelflix` Livewire component, `velflix.index` route name, `resources/views/components/velflix-card.blade.php`). Working directory is also `velflix/`.

When adding code, follow the existing convention in the area you're editing — don't rename `Velflix*` symbols just for consistency. The `velflix` → `flik` rename has not been completed and a half-rename will break references.

## Stack & version reality

The README lists Laravel 9.x / PHP 8.0+. **That is stale.** Actual requirements (from `composer.json`):
- **PHP `^8.2`**
- **Laravel `^12.0`** (recently upgraded — see commits `2a9da15`, `ceeab7d`, `34305c2`)
- Livewire `^3.5`
- NativePHP Mobile `^3.0` (Android builds)

Note that the `bootstrap/app.php` still uses the **pre-Laravel 11 layout** (binding kernels manually). The framework was upgraded in-place but the minimal-app refactor was not done. `app/Http/Kernel.php`, `app/Console/Kernel.php`, and the classic provider files in `app/Providers/` are still authoritative.

The CI workflow (`.github/workflows/php-lint.yml`) pins PHP 8.1, which is **inconsistent with composer's 8.2 requirement**. Be aware that local installs will fail on PHP 8.1.

## Common commands

```bash
# Setup
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link

# Dev
php artisan serve            # PHP server on :8000
npm run dev                  # Vite dev server (HMR)
npm run build                # Production assets

# Tests
./vendor/bin/phpunit                                   # full suite
./vendor/bin/phpunit --filter=AuthenticationTest       # single test class
./vendor/bin/phpunit tests/Feature/AuthenticationTest.php
npx cypress open                                       # E2E (config: cypress.config.js)

# Lint & static analysis (same checks CI runs)
./vendor/bin/pint --test --preset laravel              # formatting check (drop --test to fix)
./vendor/bin/psalm
./vendor/bin/phpstan analyse

# Mobile (NativePHP Android)
./native <command>           # wraps `php artisan native:<command>`
```

**Default seeded accounts**: `admin@gmail.com` / `password` (admin) and `user@gmail.com` / `password`.

## Architecture

### Request flow
Single-file routing in `routes/web.php` — public routes, `auth` group, and an `auth + can:admin` group prefixed `/admin/` are all defined there. The `admin` Gate is registered in `app/Providers/AuthServiceProvider.php` as `(bool) $user->is_admin`. There is no `routes/api.php` usage worth speaking of; the project is server-rendered Blade + Livewire + Alpine.

### Controllers & domains
- `VelflixController` — public catalog (`/movies`, `/movie/{id}`). Eager-loads genres/cast and shapes data into arrays for views.
- `AdminController` — single fat controller for the entire admin panel CRUD (movies, genres, casts, users, banners). All admin routes hit methods on this one class.
- `PaymentController` — Midtrans Snap integration. The `isEnabled()` static gate (`config('services.midtrans.server_key')` non-empty) is the canonical check used across the app.
- Auth split into `LoginController` (Google OAuth via Socialite), `SessionsController` (email/password), `RegisterController`.
- Engagement: `WatchlistController`, `RatingController`, `CommentController`, `WatchHistoryController` (resume playback), `RewardsController` (gamification), `NotificationController`, `ProfileController`, `NewsletterController` (Mailchimp).

### Models & domain map
The schema spans 18 migrations under `database/migrations/`. Key clusters:
- **Catalog**: `Movie` ↔ `Genre` (`genre_movie` pivot), `Movie` ↔ `Cast` (`cast_movie` pivot with `character`, `order`).
- **Engagement**: `Watchlist`, `WatchHistory`, `Rating`, `Comment` (nested via `topLevel()` scope + `replies` relation).
- **Subscriptions/Payments**: `SubscriptionPlan`, `Subscription` (with `active()` scope and Midtrans payment columns).
- **Gamification**: `UserLevel` (XP/level), `Coin` (ledger; balance via `Coin::balanceFor($userId)`), `Achievement` + `user_achievements` pivot.
- **System**: `Banner`, `Notification`.

`Movie::castMembers()` is the relation name (not `casts()` — that name conflicts with Eloquent's `$casts` property in Laravel 12; renamed in commit `34305c2`). Don't reintroduce `casts()`.

### Frontend
- Blade views in `resources/views/`, with shared layout components in `resources/views/components/`.
- Livewire 3 for interactivity (currently just `SearchVelflix` for live movie search).
- Alpine.js + Tailwind for the OTT-premium dark theme (gold `#C5A55A` on `#0a0a0a`).
- Vite entry points: `resources/css/app.css` and `resources/js/app.js` (see `vite.config.js`).
- Video.js powers playback; progress is tracked server-side via `WatchHistoryController::updateProgress`.
- PWA: `public/manifest.json` and `public/sw.js` (network-first service worker).

### ENV-driven feature gating (important pattern)
Several features auto-disable when env vars are unset rather than hard-failing. **Preserve this pattern** when adding integrations:
- **Midtrans**: empty `MIDTRANS_SERVER_KEY` → checkout button shows "Coming Soon", webhook still mounted.
- **Storage backends**: `FILESYSTEM_DISK` selects between `public`/`s3`/`azure`/`alibaba`. `Movie::getVideoFullUrlAttribute()` branches on `video_disk` per row, and poster/backdrop accessors handle both URL strings and storage paths transparently.
- **Google OAuth, TMDB, Mailchimp, Pusher**: similar — features degrade gracefully when keys are missing.

Note: `config/filesystems.php` reads `FILESYSTEM_DRIVER`, but `.env.example` and the README document `FILESYSTEM_DISK`. When in doubt, set both.

### Public assets are tracked in git
Per commit `77c15a6`, `public/build/` is committed (it was removed from `.gitignore`). Don't re-add it to `.gitignore` without checking why — the previous fix was deliberate.

## Conventions worth knowing

- `User::setPasswordAttribute` auto-bcrypts — never call `bcrypt()` again before assigning.
- Ratings/comments/watchlist all assume the route-model-bound `auth()->user()` and won't be reachable unauthenticated (route group middleware).
- The `MASTER_PLAN.md` and `PROGRESS_REPORT.md` files at the repo root are product/planning docs, not runtime config — safe to ignore for code work unless the user references them.
