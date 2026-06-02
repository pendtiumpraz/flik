<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\VelflixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

// ━━━ Trending engine (DEV #2) — public, auth-optional ━━━
// Reads pre-aggregated trending_movies cache (rebuilt by the scheduler;
// see App\Console\Kernel::schedule). Public so anonymous browsers can
// discover what's hot before signing up.
Route::get('/trending', [\App\Http\Controllers\TrendingController::class, 'index'])
    ->name('trending.index');

// Anonymous public endpoint — easy spam target. Hard cap via the named
// 'newsletter' limiter (see RouteServiceProvider): 2/min/IP by default.
// Honeypot trap field (`website_url`) + form-fill timer rejects naive bots
// with a silent 200 — see App\Http\Middleware\Honeypot. Pair with the
// `<x-honeypot />` Blade component in the form view.
Route::post('newsletter', NewsletterController::class)
    ->middleware(['throttle:newsletter', 'honeypot'])
    ->name('newsletter.subscribe');

// ━━━ SEO infrastructure (public, no auth — crawlers must reach these) ━━━
Route::get('/sitemap.xml', [\App\Http\Controllers\SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/sitemap-cast.xml', [\App\Http\Controllers\SeoController::class, 'sitemapCast'])->name('seo.sitemap.cast');
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('seo.robots');

// ━━━ Cast / Director public profiles (public, no auth) ━━━
// /cast/{id}/{slug?} — slug is a SEO suffix; the controller 301-redirects
// missing/stale slugs to the canonical URL so search engines settle on one.
// {cast} is constrained to an integer so /cast/some-string can never collide
// with the listing index route below.
Route::get('/cast', [\App\Http\Controllers\PublicCastController::class, 'index'])
    ->name('public.cast.index');
Route::get('/cast/{cast}/{slug?}', [\App\Http\Controllers\PublicCastController::class, 'show'])
    ->where('cast', '[0-9]+')
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('public.cast.show');
Route::get('/api/movies/{movie}/cast', [\App\Http\Controllers\PublicCastController::class, 'byMovie'])
    ->name('public.cast.by-movie');

// ━━━ Legal pages (public, no auth — required for app-store review,
// cookie banner links, and prospective users browsing pre-signup) ━━━
// NOTE: `/privacy` is reserved for the auth-protected user-data rights
// hub (export/erasure UI) defined further below by the privacy controller.
// The static Privacy Policy doc lives at `/privacy-policy` so the two
// don't collide. The Privacy Policy doc links into `/privacy` for
// rights-exercise actions.
Route::get('/privacy-policy', [\App\Http\Controllers\LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [\App\Http\Controllers\LegalController::class, 'terms'])->name('legal.terms');
Route::get('/refund-policy', [\App\Http\Controllers\LegalController::class, 'refund'])->name('legal.refund');

// ━━━ Help Center (public, no auth — discoverable without signup) ━━━
// Article suggest is JSON for the autocomplete typeahead — capped at
// 60/min/IP via the named 'search' limiter so a typing storm doesn't
// hammer the DB. Feedback POST is throttled tight (10/hr/IP) because
// each submission writes a row and bumps a counter.
Route::get('/help', [\App\Http\Controllers\HelpController::class, 'index'])->name('help.index');
Route::get('/help/search', [\App\Http\Controllers\HelpController::class, 'search'])->name('help.search');
Route::get('/help/api/suggest', [\App\Http\Controllers\HelpController::class, 'searchSuggest'])
    ->middleware('throttle:search')
    ->name('help.suggest');
Route::get('/help/category/{category:slug}', [\App\Http\Controllers\HelpController::class, 'category'])
    ->name('help.category');
Route::get('/help/{article:slug}', [\App\Http\Controllers\HelpController::class, 'show'])
    ->name('help.show');
Route::post('/help/{article:slug}/feedback', [\App\Http\Controllers\HelpController::class, 'feedback'])
    ->middleware('throttle:10,60')
    ->name('help.feedback');

// ━━━ Locale switcher (public — anonymous visitors get session locale) ━━━
// POST so the choice cannot be CSRF'd silently via a stray <a href="?lang=..">
// link smuggled into UGC. The middleware (App\Http\Middleware\SetLocale)
// still honours `?lang=` for share-friendly URLs, but the persistent
// per-user write happens ONLY through this endpoint.
Route::post('/locale/{code}', [\App\Http\Controllers\LocaleController::class, 'switch'])
    ->where('code', '[A-Za-z-]{2,5}')
    ->name('locale.switch');

// ━━━ Security disclosure (RFC 9116 — public, no auth) ━━━
// security.txt lives at /public/.well-known/security.txt and is served as a
// static file. These routes back the human-facing pages it points at.
// reportSubmit is throttled tight: it ships an email + writes to the security
// log channel, so abuse here turns into spam in ops' inbox.
Route::get('/security/policy', [\App\Http\Controllers\SecurityPolicyController::class, 'policy'])->name('security.policy');
Route::get('/security/report', [\App\Http\Controllers\SecurityPolicyController::class, 'reportForm'])->name('security.report.form');
Route::post('/security/report', [\App\Http\Controllers\SecurityPolicyController::class, 'reportSubmit'])
    ->middleware('throttle:5,60')
    ->name('security.report.submit');

// ━━━ CSP violation reports (browsers POST here when SecurityHeaders blocks something) ━━━
// Public on purpose — browsers send these from any context, no session.
// Throttled tight because a misconfigured CSP can fire thousands per minute.
Route::post('/csp-report', function (\Illuminate\Http\Request $request) {
    $payload = $request->isJson() ? $request->json()->all() : $request->all();
    \Illuminate\Support\Facades\Log::channel('security')->warning('CSP violation', [
        'report' => $payload['csp-report'] ?? $payload,
        'ip' => $request->ip(),
        'ua' => $request->userAgent(),
    ]);

    return response()->noContent();
})
    ->middleware('throttle:60,1')
    ->name('security.csp-report');

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionsController::class, 'create'])->name('login');
    // 'login' is a named RateLimiter (RouteServiceProvider) — coarse outer
    // guard against floods. The fine-grained per-account/per-IP lockout +
    // progressive delay live inside SessionsController via LoginThrottle.
    // Anti-bot honeypot (`<x-honeypot />` in resources/views/auth/login.blade.php)
    // sits alongside the throttle. Authenticated users are skipped, so the
    // post-login `auth` redirect is unaffected.
    Route::post('login', [SessionsController::class, 'store'])->middleware(['throttle:login', 'honeypot']);
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    // Per-IP 'register' limiter — registrations create real DB rows so the
    // outer guard is intentionally tight (3/min/IP). Tunable via
    // config('security.rate_limits.register'). Honeypot adds a behavioural
    // check on top so naive registration spammers never hit the controller.
    Route::post('register', [RegisterController::class, 'store'])->middleware(['throttle:register', 'honeypot']);

    // ━━━ Password reset (Laravel-broker-backed, hardened) ━━━
    // Both endpoints share the named 'password-reset' limiter (3/hr/IP by
    // default — see config/security.php). Tight on purpose so attackers
    // can't use the broker as an enumeration oracle. The response is a
    // single generic flash regardless of whether the email exists.
    Route::get('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'showRequest'])
        ->name('password.request');
    Route::post('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'request'])
        ->middleware(['throttle:password-reset', 'honeypot'])
        ->name('password.email');

    Route::get('reset-password/{token}', [\App\Http\Controllers\PasswordResetController::class, 'showReset'])
        ->name('password.reset');
    Route::post('reset-password', [\App\Http\Controllers\PasswordResetController::class, 'update'])
        ->middleware(['throttle:password-reset', 'honeypot'])
        ->name('password.update');
});

// ━━━ Email verification (Laravel-built-in flow) ━━━
// `notice` and `resend` need an authenticated session (we just registered).
// `verify` itself uses a signed URL so it doesn't need auth — the request
// class validates signature + hash and returns 401 on tamper.
Route::middleware('auth')->group(function () {
    Route::get('email/verify', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    // Named 'verification-resend' limiter (per user when authenticated) —
    // tunable via config('security.rate_limits.verification-resend').
    Route::post('email/verification-notification', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification-resend')
        ->name('verification.send');
});

Route::get('email/verify/{id}/{hash}', [\App\Http\Controllers\Auth\EmailVerificationController::class, 'verify'])
    ->middleware(['auth', 'signed', 'throttle:verification-resend'])
    ->name('verification.verify');

// ━━━ 2FA challenge (auth NOT required — gated by 2fa.pending_user_id session key) ━━━
Route::get('/2fa/challenge', [\App\Http\Controllers\TwoFactorController::class, 'challenge'])->name('2fa.challenge');
Route::post('/2fa/verify', [\App\Http\Controllers\TwoFactorController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('2fa.verify');

Route::middleware('auth')->group(function () {
    Route::post('logout', [SessionsController::class, 'destroy'])->name('logout');

    // ━━━ 2FA management (must be logged in) ━━━
    Route::get('/2fa/setup', [\App\Http\Controllers\TwoFactorController::class, 'setup'])->name('2fa.setup');
    Route::post('/2fa/confirm', [\App\Http\Controllers\TwoFactorController::class, 'confirm'])->name('2fa.confirm');
    Route::post('/2fa/disable', [\App\Http\Controllers\TwoFactorController::class, 'disable'])->name('2fa.disable');

    Route::get('/movies', [VelflixController::class, 'index'])->name('velflix.index');
    Route::get('/movie/{watch}', [VelflixController::class, 'show'])->name('movies.show');

    // Watchlist
    Route::get('/my-list', [\App\Http\Controllers\WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('/watchlist/toggle', [\App\Http\Controllers\WatchlistController::class, 'toggle'])->name('watchlist.toggle');

    // Ratings
    Route::post('/rating', [\App\Http\Controllers\RatingController::class, 'store'])->name('rating.store');
    Route::delete('/rating', [\App\Http\Controllers\RatingController::class, 'destroy'])->name('rating.destroy');

    // Comments — store is rate-limited per user (named 'comments' limiter,
    // 10/min/user by default) so a single account can't spam threads.
    // Destroy is unguarded because it's gated by ownership in the
    // controller and has no abuse vector beyond the user's own data.
    Route::post('/comment', [\App\Http\Controllers\CommentController::class, 'store'])
        ->middleware('throttle:comments')
        ->name('comment.store');
    Route::delete('/comment/{comment}', [\App\Http\Controllers\CommentController::class, 'destroy'])->name('comment.destroy');

    // Emoji reactions — share the 'comments' limiter so a single user
    // can't burst reactions any faster than they can post. Returns
    // JSON; consumed optimistically by the Alpine commentReactions()
    // factory.
    Route::post('/comments/{comment}/react', [\App\Http\Controllers\CommentReactionController::class, 'toggle'])
        ->middleware('throttle:comments')
        ->name('comment.react');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');

    // Public-profile editor (bio / avatar / cover / is_public / allow_dm).
    // Multipart upload — hits FileUploadValidator (peer SEC #11) for EXIF
    // strip + magic-byte sniff when available, falls back to Laravel rules
    // otherwise. POST verb because the form is multipart/form-data.
    Route::post('/profile/public', [\App\Http\Controllers\ProfileController::class, 'updatePublic'])
        ->name('profile.public.update');

    // Activity feed (peer SOCIAL #1 bonus) — 7-day window across the
    // viewer's follow graph, capped at 50 items. Cheap enough to compute
    // per-request; if it grows hot we'll materialise into a feed table.
    Route::get('/feed', [\App\Http\Controllers\ActivityFeedController::class, 'index'])->name('feed.index');

    // Self-service "View My Permissions" — lists the authenticated user's
    // assigned roles + the effective permission set those roles grant.
    // Read-only; no security/audit value beyond letting users see what
    // they can/can't do, but it dramatically reduces support tickets.
    Route::get('/profile/permissions', [\App\Http\Controllers\ProfileController::class, 'permissions'])
        ->name('profile.permissions');

    // Profile — active session management
    Route::get('/profile/sessions', [\App\Http\Controllers\Profile\SessionController::class, 'index'])->name('profile.sessions.index');
    Route::delete('/profile/sessions/{id}', [\App\Http\Controllers\Profile\SessionController::class, 'destroy'])->name('profile.sessions.destroy');
    Route::post('/profile/sessions/destroy-all', [\App\Http\Controllers\Profile\SessionController::class, 'destroyAll'])->name('profile.sessions.destroyAll');

    // Profile — known/trusted device management (backs LoginAlertService)
    Route::post('/profile/devices/{device}/trust', [\App\Http\Controllers\Profile\SessionController::class, 'trustDevice'])->name('profile.devices.trust');
    Route::delete('/profile/devices/{device}', [\App\Http\Controllers\Profile\SessionController::class, 'forgetDevice'])->name('profile.devices.forget');

    // Subscription Plans — viewing is fine for unverified users (lets them
    // see what they're missing) but the actual checkout below is gated by
    // the `verified` middleware.
    Route::get('/plans', function () {
        $plans = \App\Models\SubscriptionPlan::active()->get();

        return view('plans.index', compact('plans'));
    })->name('plans.index');

    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
    Route::get('/notifications/count', [\App\Http\Controllers\NotificationController::class, 'count'])->name('notifications.count');

    // Rewards & Gamification
    Route::get('/rewards', [\App\Http\Controllers\RewardsController::class, 'index'])->name('rewards.index');
    Route::post('/rewards/claim-daily', [\App\Http\Controllers\RewardsController::class, 'claimDaily'])->name('rewards.claimDaily');

    // Watch History / Progress Tracking
    Route::post('/watch/progress', [\App\Http\Controllers\WatchHistoryController::class, 'updateProgress'])->name('watch.progress');
    Route::get('/watch/resume', [\App\Http\Controllers\WatchHistoryController::class, 'getProgress'])->name('watch.resume');

    // ━━━ Gamification: Streaks + Achievements + Leaderboards ━━━
    // Achievement showcase (Hall of Fame) — auth so we can render unlocked state.
    Route::get('/profile/achievements', [\App\Http\Controllers\ProfileController::class, 'achievements'])
        ->name('profile.achievements');

    // Streak freeze purchase (50 coins → +1 freeze credit). POST-only so a
    // stray <a> can't drain a user's balance; CSRF protected by the web group.
    Route::post('/streak/freeze', [\App\Http\Controllers\StreakController::class, 'freeze'])
        ->name('streak.freeze');

    // Leaderboards. Use the 'search' limiter (60/min/user) — these are read-
    // only views and not particularly cheap (group-by counts), so the cap
    // matches the same expectation as the autocomplete pages.
    Route::get('/leaderboards/streaks', [\App\Http\Controllers\LeaderboardController::class, 'streaks'])
        ->middleware('throttle:search')
        ->name('leaderboards.streaks');
    Route::get('/leaderboards/xp', [\App\Http\Controllers\LeaderboardController::class, 'xp'])
        ->middleware('throttle:search')
        ->name('leaderboards.xp');
    Route::get('/leaderboards/watches', [\App\Http\Controllers\LeaderboardController::class, 'watches'])
        ->middleware('throttle:search')
        ->name('leaderboards.watches');

    // ── Per-episode player (series support) ─────────────────────
    // Standalone player view for a single episode of a series. Lives
    // alongside /watch/progress so the player's heartbeat ajax post
    // stays unchanged — the new request just adds `episode_id`.
    // `geoblock` reads the route's `{movie}` param. The episode route binds
    // {episode} not {movie}, so geo enforcement is handled INSIDE the
    // controller (which looks up $episode->movie and applies the same gate).
    // See audit FIX #2 §3.1 / §5.
    Route::get('/watch/episode/{episode}', [\App\Http\Controllers\EpisodeWatchController::class, 'show'])
        ->name('episodes.watch');

    // Payment — checkout requires a verified email so we can deliver receipts
    // and recovery codes. Browsing is intentionally NOT gated (see /movies).
    Route::middleware('verified')->group(function () {
        Route::get('/checkout/{plan}', [\App\Http\Controllers\PaymentController::class, 'checkout'])->name('payment.checkout');
        Route::get('/payment/success', [\App\Http\Controllers\PaymentController::class, 'success'])->name('payment.success');

        // Live promo validation for the checkout UI. Alpine debounces the
        // input change and POSTs here on each keystroke pause. Throttled
        // hard ('search' limiter: 60/min/user) so a stuck keyboard can't
        // hammer the promo gate. Returns JSON only — no view rendered.
        Route::post('/checkout/validate-promo', [\App\Http\Controllers\PaymentController::class, 'validatePromo'])
            ->middleware('throttle:search')
            ->name('payment.validate-promo');
    });

    // Watch Party (synchronized playback rooms)
    // {roomCode} is the literal column value — WatchParty::getRouteKeyName()
    // returns 'room_code' so type-hinting the model in the controller would
    // also work. We pass the string explicitly so the URL is human-friendly
    // ("/watch-party/ABCD1234").
    Route::get('/watch-party/create/{movie}', [\App\Http\Controllers\WatchPartyController::class, 'createForm'])->name('watch-party.create.form');
    Route::post('/watch-party', [\App\Http\Controllers\WatchPartyController::class, 'create'])->name('watch-party.create');
    Route::get('/watch-party/join', [\App\Http\Controllers\WatchPartyController::class, 'joinForm'])->name('watch-party.join.form');
    Route::post('/watch-party/join', [\App\Http\Controllers\WatchPartyController::class, 'joinByCode'])->name('watch-party.join.action');
    Route::get('/watch-party/{roomCode}', [\App\Http\Controllers\WatchPartyController::class, 'show'])->name('watch-party.show');
    Route::post('/watch-party/{roomCode}/join', [\App\Http\Controllers\WatchPartyController::class, 'join'])->name('watch-party.join');
    Route::post('/watch-party/{roomCode}/leave', [\App\Http\Controllers\WatchPartyController::class, 'leave'])->name('watch-party.leave');
    Route::post('/watch-party/{roomCode}/sync', [\App\Http\Controllers\WatchPartyController::class, 'sync'])->name('watch-party.sync');
    Route::post('/watch-party/{roomCode}/chat', [\App\Http\Controllers\WatchPartyController::class, 'chat'])->name('watch-party.chat');
    Route::post('/watch-party/{roomCode}/end', [\App\Http\Controllers\WatchPartyController::class, 'end'])->name('watch-party.end');

    // Save for Friday Night — schedule manager
    Route::get('/my-schedule', [\App\Http\Controllers\ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('/my-schedule/create/{movie}', [\App\Http\Controllers\ScheduleController::class, 'create'])->name('schedule.create');
    Route::post('/my-schedule/{movie}', [\App\Http\Controllers\ScheduleController::class, 'store'])->name('schedule.store');
    Route::delete('/my-schedule/{schedule}', [\App\Http\Controllers\ScheduleController::class, 'destroy'])->name('schedule.destroy');
    Route::get('/my-schedule/{schedule}/ics', [\App\Http\Controllers\ScheduleController::class, 'ics'])->name('schedule.ics');

    // Movie Trivia Quiz Game (O5)
    Route::get('/movie/{movie}/quiz', [\App\Http\Controllers\QuizController::class, 'start'])->name('quiz.start');
    Route::post('/movie/{movie}/quiz', [\App\Http\Controllers\QuizController::class, 'submit'])
        ->middleware('throttle:6,1') // 6/min/IP — deters brute-forcing the correct answers (audit 09 / E-7)
        ->name('quiz.submit');
    Route::get('/movie/{movie}/quiz/leaderboard', [\App\Http\Controllers\QuizController::class, 'leaderboard'])->name('quiz.leaderboard');

    // ━━━ Refer-a-friend dashboard + Gift redeem (auth-only surface) ━━━
    // Dashboard shows the user's referral code + share link + ledger.
    // Gift redeem is auth-gated because we need a User row to stamp
    // redeemed_by_user_id; throttled tight to prevent brute force
    // enumeration of 12-char gift codes.
    Route::get('/referrals', [\App\Http\Controllers\ReferralController::class, 'dashboard'])
        ->name('referral.dashboard');

    Route::get('/gift/redeem', [\App\Http\Controllers\GiftSubscriptionController::class, 'redeemForm'])
        ->name('gift.redeem-form');
    Route::post('/gift/redeem', [\App\Http\Controllers\GiftSubscriptionController::class, 'redeem'])
        ->middleware('throttle:10,1')
        ->name('gift.redeem');

    // ━━━ Privacy & GDPR (export + erase) ━━━
    // Self-service "right to access" + "right to be forgotten". The download
    // route is wrapped in `signed` middleware so the temporary URL produced
    // by UserDataExporter::signedUrl() is the only way out of the private
    // disk. Delete is a DELETE verb so a stray <a> click can't trigger it.
    Route::get('/privacy', [\App\Http\Controllers\Privacy\UserDataController::class, 'index'])->name('privacy.index');
    Route::get('/privacy/export', [\App\Http\Controllers\Privacy\UserDataController::class, 'exportForm'])->name('privacy.export.request');
    Route::post('/privacy/export', [\App\Http\Controllers\Privacy\UserDataController::class, 'exportRequest'])->name('privacy.export.submit');
    Route::get('/privacy/export/download/{filename}', [\App\Http\Controllers\Privacy\UserDataController::class, 'exportDownload'])
        ->where('filename', '[A-Za-z0-9_.\-]+')
        ->middleware('signed')
        ->name('privacy.export.download');
    Route::get('/privacy/delete-account', [\App\Http\Controllers\Privacy\UserDataController::class, 'confirmDelete'])->name('privacy.delete.confirm');
    Route::delete('/privacy/delete-account', [\App\Http\Controllers\Privacy\UserDataController::class, 'delete'])->name('privacy.delete.execute');
});

// ━━━ Web Push subscribe / unsubscribe (auth optional — anonymous opt-in allowed) ━━━
// The subscribe endpoint upserts by `endpoint`, so re-subscribing the same browser
// just refreshes the row. When VAPID is not configured both endpoints return
// HTTP 503 with `{success:false, reason:'not_configured'}` so the client-side
// JS can fail gracefully without console spam.
Route::post('/api/push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'subscribe'])
    ->middleware('throttle:60,1')
    ->name('push.subscribe');
Route::post('/api/push/unsubscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'unsubscribe'])
    ->middleware('throttle:60,1')
    ->name('push.unsubscribe');

// ━━━ PWA install telemetry (auth optional) ━━━
// Fired by resources/js/pwa-install.js on `appinstalled` or userChoice
// resolution. Append-only ledger consumed by the admin install-count widget.
// 30/min/IP cap is more than enough for legitimate installs without leaving
// a wide-open POST endpoint.
Route::post('/api/pwa/track-install', [\App\Http\Controllers\PwaInstallTrackController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('pwa.track-install');

// ━━━ Offline fallback page (server-rendered mirror of public/offline.html) ━━━
// The static asset is what the service worker actually serves when fetch()
// fails, but this route is here so deep-link callers can still reach the
// view via a real URL (e.g. browser bookmark, share link).
Route::view('/offline', 'offline')->name('offline');

// Midtrans Webhook (no auth required) — gateway can fan out per state
// change so the cap is generous (named 'webhook' limiter = 100/min/IP).
// Anchored on the IP so a single misbehaving sender can't drown legit ones.
Route::post('/payment/webhook', [\App\Http\Controllers\PaymentController::class, 'webhook'])
    ->middleware('throttle:webhook')
    ->name('payment.webhook');

// ━━━ Refer-a-friend public capture (no auth — works for prospects) ━━━
// Bare /r/{code} endpoint records the attribution into a 30-day cookie
// + session value, then bounces the visitor to /register (or home if
// already authed). The actual ReferralConversion row is created by
// RegisterController::store once the User row exists. Coarse throttle
// keeps a botnet from burning random codes into the cookie jar.
Route::get('/r/{code}', [\App\Http\Controllers\ReferralController::class, 'redirect'])
    ->where('code', '[A-Za-z0-9]{6,16}')
    ->middleware('throttle:30,1')
    ->name('referral.redirect');

// ━━━ Gift subscription public surface ━━━
// Buy form + checkout submit are auth-OPTIONAL — anonymous shoppers
// can purchase a gift. Redeem requires auth (you need a User row to
// stamp redeemed_by_user_id). Webhook is its own public endpoint that
// also forwards non-gift orders to PaymentController::webhook so we
// can point Midtrans at a single URL when ops prefer that topology.
// {plan} is constrained to numeric so /gift/redeem (a string segment)
// never tries to model-bind against subscription_plans and 404 — the
// redeem GET is declared inside the auth group below.
Route::get('/gift/{plan}', [\App\Http\Controllers\GiftSubscriptionController::class, 'buy'])
    ->whereNumber('plan')
    ->name('gift.buy');
Route::post('/gift/purchase/{plan}', [\App\Http\Controllers\GiftSubscriptionController::class, 'purchase'])
    ->whereNumber('plan')
    ->middleware('throttle:10,1')
    ->name('gift.purchase');
Route::post('/gift/webhook', [\App\Http\Controllers\GiftSubscriptionController::class, 'webhook'])
    ->middleware('throttle:webhook')
    ->name('gift.webhook');

// Health check endpoints (no auth — for load balancers / orchestrators)
Route::get('/healthz', [\App\Http\Controllers\HealthController::class, 'live'])->name('health.live');
Route::get('/healthz/ready', [\App\Http\Controllers\HealthController::class, 'ready'])->name('health.ready');
Route::get('/healthz/detailed', [\App\Http\Controllers\HealthController::class, 'detailed'])->name('health.detailed');

// AI Chatbot (auth required) — named 'ai-chat' limiter = 20/min/user,
// catches runaway client loops without blocking real conversation pace.
Route::post('/chat', [\App\Http\Controllers\ChatController::class, 'respond'])
    ->middleware(['auth', 'throttle:ai-chat'])
    ->name('chat.respond');

// Per-user persistent chat history — list/load/delete sessions
Route::middleware('auth')->group(function () {
    Route::get('/chat/history', [\App\Http\Controllers\ChatController::class, 'history'])->name('chat.history');
    Route::get('/chat/session/{session}', [\App\Http\Controllers\ChatController::class, 'session'])->whereNumber('session')->name('chat.session');
    Route::delete('/chat/session/{session}', [\App\Http\Controllers\ChatController::class, 'destroySession'])->whereNumber('session')->name('chat.session.destroy');
});

// AI Plot Explainer (auth required) — named 'ai-batch' limiter (50/hr/user)
// is the outer guard. The controller still keeps its own per-feature 10/hr
// budget for cost control, so this is intentional defence-in-depth, not a
// double-application of the same limit.
Route::post('/api/movies/{movie}/plot-explain', [\App\Http\Controllers\PlotExplainController::class, 'explain'])
    ->middleware(['auth', 'throttle:ai-batch'])
    ->name('movies.plot-explain');

// ━━━ DRM Key Endpoint (no auth — JWT-protected, fetched by Shaka Player) ━━━
//
// JWT scope guard + per-key replay binding live inside PlaybackController::key.
// Geo enforcement is inline (legacy) and matches `geoblock` semantics; see
// docs/audit/04-drm-playback.md FIX #2 §3.1.
Route::get('/drm/key/{sessionToken}/{keyId}', [\App\Http\Controllers\PlaybackController::class, 'key'])
    ->name('drm.key');

// ━━━ DRM media playlist + segment streaming (signed-URL gated) ━━━
//
// PlaybackManifestGenerator emits these URLs via URL::temporarySignedRoute,
// so the `signed` middleware revalidates the signature on every fetch. The
// player never gets to forge a playlist URL for a different movie/rendition.
// `geoblock` denies countries outside the movie's geo_allow list (HTTP 451).
// See docs/audit/04-drm-playback.md FIX #2 §2.4 / §3.1.
Route::get('/drm/playlist/{movie:slug}/{rendition}.m3u8', [\App\Http\Controllers\PlaybackController::class, 'playlist'])
    ->middleware(['signed', 'geoblock'])
    ->where('rendition', '[A-Za-z0-9_\-]+')
    ->name('drm.playlist');

Route::get('/drm/segment/{movie:slug}/{rendition}/{filename}', [\App\Http\Controllers\PlaybackController::class, 'segment'])
    ->middleware(['signed', 'geoblock'])
    ->where('rendition', '[A-Za-z0-9_\-]+')
    ->where('filename', 'segment_[0-9]+\.ts')
    ->name('drm.segment');

// ━━━ Signed-URL media accessors (no auth — gated by `signed` middleware) ━━━
// Backs Movie::getPosterUrlAttribute() for any poster/backdrop/slider that
// lives on the `private` disk. Public CDN URLs (Bunny / S3 with public ACL)
// keep returning their direct URL; only files on the private disk go
// through these routes. The signed URL TTL is set by the accessor (2 h
// default — long enough for browser cache, short enough that a leaked link
// expires before search-engine indexing).
Route::get('/media/poster/{movie}', [\App\Http\Controllers\MediaController::class, 'poster'])
    ->middleware('signed')
    ->name('media.poster');
Route::get('/media/backdrop/{movie}', [\App\Http\Controllers\MediaController::class, 'backdrop'])
    ->middleware('signed')
    ->name('media.backdrop');
Route::get('/media/slider/{movie}', [\App\Http\Controllers\MediaController::class, 'slider'])
    ->middleware('signed')
    ->name('media.slider');

// X-Ray Actor Overlay route lives in routes/api.php (auto-prefixed /api)

Route::controller(LoginController::class)->group(function () {
    Route::get('login/google', 'redirectToProvider');
    Route::get('login/google/callback', 'handleProviderCallback');
});

// ━━━ Email tracking endpoints (public, no auth — hit by mail clients) ━━━
// Open pixel + click redirect. tracking_id is the capability token; the
// controller never returns a status that reveals whether the token is
// valid (broken IDs get a transparent gif / redirect-to-home anyway), so
// these can't be used for token enumeration. Throttled coarsely so a
// botnet can't DoS the open counters.
//
// The {trackingId}.gif suffix is a cosmetic file extension to convince
// strict mail clients that the URL is genuinely an image — the route
// constraint pins the param to exactly the 32-char random alnum token.
Route::get('/email/track/open/{trackingId}.gif', [\App\Http\Controllers\EmailTrackingController::class, 'open'])
    ->where('trackingId', '[A-Za-z0-9]{32}')
    ->middleware('throttle:1000,1')
    ->name('email.track.open');
Route::get('/email/track/click/{trackingId}', [\App\Http\Controllers\EmailTrackingController::class, 'click'])
    ->where('trackingId', '[A-Za-z0-9]{32}')
    ->middleware('throttle:1000,1')
    ->name('email.track.click');

// ━━━ Editorial Blog (public — anonymous reading) ━━━
//
// Route order matters: the catch-all /blog/{post:slug} MUST come LAST so
// it doesn't shadow /blog/feed.xml or /blog/category/{slug}. The show
// action allows preview for users holding `blog.manage` (so editors can
// review a draft) — everyone else gets 404 for non-published posts.
Route::get('/blog', [\App\Http\Controllers\BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/feed.xml', [\App\Http\Controllers\BlogController::class, 'rss'])->name('blog.rss');
Route::get('/blog/category/{category:slug}', [\App\Http\Controllers\BlogController::class, 'byCategory'])
    ->name('blog.category');
Route::get('/blog/{post:slug}', [\App\Http\Controllers\BlogController::class, 'show'])->name('blog.show');

// ━━━ Admin panel (per-permission gating) ━━━
//
// Outer middleware stack: `auth` + `can:admin` is the COARSE gate.
// Anyone holding any admin-flavoured role (super_admin, content_manager,
// finance, customer_support, etc.) passes this check and reaches the
// prefix. Per-route fine-grained authorization is layered on each
// Route::<verb>() via `->middleware('can:<permission_name>')`, where
// the permission names follow the dotted-namespace taxonomy seeded by
// `RolePermissionSeeder` (see docs/security/roles-permissions.md).
//
// Defensive design: if the Permission table is missing or the seed
// never ran, `AuthServiceProvider` installs a `Gate::before` fallback
// that lets users with the legacy `is_admin` boolean through ANY
// dotted permission check — so this group never 500s on a fresh
// install or stale seed. Once peer ROLE #2's dynamic `Gate::define`
// loop registers the real gates, those take over and the legacy
// fallback becomes a redundant safety net (`Gate::has($ability)`
// short-circuits the before-hook).
//
// Routes without a more specific permission (the dashboard landing
// page + pitch deck reader) stay on the bare `can:admin` gate.
//
// 2FA enforcement (FIX #6, AUDIT #1 priority): every admin route is
// gated by the `2fa` middleware (alias → TwoFactorVerified). The
// middleware is a no-op for users who have NOT enabled 2FA, so
// existing admin accounts without TOTP keep working today. Once an
// admin enables 2FA via /profile, every subsequent admin request
// must clear the challenge — closing the OAuth/programmatic-login
// bypass identified in docs/audit/01-auth-login.md.
//
// Policy: super_admins MUST enable 2FA. Enforced procedurally for
// now (admins-MUST-enable-2FA migration policy) until a hard-block
// is shipped.
Route::middleware(['auth', '2fa', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AdminController::class, 'dashboard'])->name('dashboard');

    // ─── Movies CRUD ─────────────────────────────────────────────
    Route::get('/movies', [\App\Http\Controllers\AdminController::class, 'movies'])
        ->middleware('can:movies.view')->name('movies.index');
    Route::get('/movies/create', [\App\Http\Controllers\AdminController::class, 'createMovie'])
        ->middleware('can:movies.create')->name('movies.create');
    Route::post('/movies', [\App\Http\Controllers\AdminController::class, 'storeMovie'])
        ->middleware('can:movies.create')->name('movies.store');
    Route::get('/movies/{movie}/edit', [\App\Http\Controllers\AdminController::class, 'editMovie'])
        ->middleware('can:movies.update')->name('movies.edit');
    Route::put('/movies/{movie}', [\App\Http\Controllers\AdminController::class, 'updateMovie'])
        ->middleware('can:movies.update')->name('movies.update');
    Route::delete('/movies/{movie}', [\App\Http\Controllers\AdminController::class, 'destroyMovie'])
        ->middleware('can:movies.delete')->name('movies.destroy');

    // ─── Movies bulk actions ─────────────────────────────────────
    // Single endpoint that dispatches on the `action` field. Baseline
    // gate is `movies.update`; sharper per-action abilities (e.g.
    // `movies.delete` for `action=delete`) are re-checked inside the
    // controller. See App\Http\Controllers\Admin\MovieBulkController.
    Route::post('/movies/bulk', [\App\Http\Controllers\Admin\MovieBulkController::class, 'apply'])
        ->middleware('can:movies.update')->name('movies.bulk');

    // ─── TMDB Import Wizard ─────────────────────────────────────
    // Bulk + single-id imports of TMDB movies/TV. All endpoints share
    // the existing `movies.create` permission so no new permission slug
    // needs seeding. The /search and /preview endpoints are JSON-only
    // (used by the Alpine.js wizard for typeahead + preview pane); the
    // /import endpoint accepts both JSON (XHR submit) and form-encoded
    // (server-rendered POST fallback).
    Route::get('/tmdb-import', [\App\Http\Controllers\Admin\TmdbImportController::class, 'index'])
        ->middleware('can:movies.create')->name('tmdb.index');
    Route::get('/tmdb-import/bulk', [\App\Http\Controllers\Admin\TmdbImportController::class, 'bulkIndex'])
        ->middleware('can:movies.create')->name('tmdb.bulk');
    Route::get('/tmdb-import/search', [\App\Http\Controllers\Admin\TmdbImportController::class, 'search'])
        ->middleware('can:movies.create')->name('tmdb.search');
    Route::get('/tmdb-import/preview', [\App\Http\Controllers\Admin\TmdbImportController::class, 'preview'])
        ->middleware('can:movies.create')->name('tmdb.preview');
    Route::post('/tmdb-import', [\App\Http\Controllers\Admin\TmdbImportController::class, 'import'])
        ->middleware('can:movies.create')->name('tmdb.import');
    Route::post('/tmdb-import/bulk', [\App\Http\Controllers\Admin\TmdbImportController::class, 'bulkImport'])
        ->middleware('can:movies.create')->name('tmdb.bulk-import');

    // ─── Genres / Casts (bundled under movies.update — taxonomy ops) ──
    Route::get('/genres', [\App\Http\Controllers\AdminController::class, 'genres'])
        ->middleware('can:movies.update')->name('genres.index');
    Route::post('/genres', [\App\Http\Controllers\AdminController::class, 'storeGenre'])
        ->middleware('can:movies.update')->name('genres.store');
    Route::delete('/genres/{genre}', [\App\Http\Controllers\AdminController::class, 'destroyGenre'])
        ->middleware('can:movies.update')->name('genres.destroy');

    Route::get('/casts', [\App\Http\Controllers\AdminController::class, 'casts'])
        ->middleware('can:movies.update')->name('casts.index');
    Route::post('/casts', [\App\Http\Controllers\AdminController::class, 'storeCast'])
        ->middleware('can:movies.update')->name('casts.store');
    Route::delete('/casts/{cast}', [\App\Http\Controllers\AdminController::class, 'destroyCast'])
        ->middleware('can:movies.update')->name('casts.destroy');

    // AI bio enrichment — admin button on the public /cast/{id} page.
    // Synchronous one-shot (web search + AI call). Throttle inherits the
    // admin group's coarse limiter; abuse is implausible behind can:admin.
    Route::post('/cast/{cast}/enrich-bio', [\App\Http\Controllers\PublicCastController::class, 'enrichBio'])
        ->middleware('can:movies.update')->name('cast.enrich-bio');

    // ─── Infrastructure Settings (dynamic tech stack config) ────
    Route::get('/infrastructure', [\App\Http\Controllers\Admin\InfrastructureController::class, 'index'])
        ->name('infrastructure.index');
    Route::post('/infrastructure', [\App\Http\Controllers\Admin\InfrastructureController::class, 'update'])
        ->name('infrastructure.update');

    // ─── Architecture Docs (client-facing HLA) ──────────────────
    // Password-gated (every reload re-prompts). Default password: ott2026
    // Configurable via /admin/settings → key `pages.protected_password`.
    // Allow GET+POST so the gate form can POST back to the same URL.
    Route::match(['get', 'post'], '/docs', [\App\Http\Controllers\Admin\DocsController::class, 'index'])
        ->middleware('page-password')
        ->name('docs.index');

    // ─── Users ───────────────────────────────────────────────────
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users'])
        ->middleware('can:users.view')->name('users.index');
    Route::get('/users/create', [\App\Http\Controllers\AdminController::class, 'createUser'])
        ->middleware('can:users.update')->name('users.create');
    Route::post('/users', [\App\Http\Controllers\AdminController::class, 'storeUser'])
        ->middleware('can:users.update')->name('users.store');
    Route::get('/users/{user}/edit', [\App\Http\Controllers\AdminController::class, 'editUser'])
        ->middleware('can:users.update')->name('users.edit');
    Route::put('/users/{user}', [\App\Http\Controllers\AdminController::class, 'updateUser'])
        ->middleware('can:users.update')->name('users.update');
    Route::put('/users/{user}/toggle-admin', [\App\Http\Controllers\AdminController::class, 'toggleAdmin'])
        ->middleware('can:users.update')->name('users.toggleAdmin');
    Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'destroyUser'])
        ->middleware('can:users.delete')->name('users.destroy');
    // Brute-force protection — unlock a locked-out account (clears
    // failed login_attempts rows + writes audit_logs entry).
    Route::post('/users/{user}/unlock-login', [\App\Http\Controllers\AdminController::class, 'unlockLogin'])
        ->middleware('can:users.update')->name('users.unlock-login');

    // ━━━ RBAC: Role CRUD + per-user role assignment ━━━
    // Role/Permission models + pivots are owned by peer ROLE #1.
    // User::roles() pivot + hasRole/hasPermission helpers are owned by
    // peer ROLE #2. The `roles.manage` and `users.assign_roles` gates
    // are owned by peer ROLE #3. This block wires the admin UI.
    // Per-route can: guards are layered defensively here — even if the
    // RoleController is mounted, every action still demands `roles.manage`
    // so a Content Manager can't drop a custom role via a crafted POST.
    Route::resource('/roles', \App\Http\Controllers\Admin\RoleController::class)
        ->except(['show'])
        ->middleware('can:roles.manage');
    Route::get('/users/{user}/roles', [\App\Http\Controllers\AdminController::class, 'editRoles'])
        ->middleware('can:users.assign_roles')->name('users.roles.edit');
    Route::post('/users/{user}/roles', [\App\Http\Controllers\AdminController::class, 'updateRoles'])
        ->middleware('can:users.assign_roles')->name('users.roles.update');

    // ─── Banners (catalog taxonomy → movies.update) ──────────────
    Route::get('/banners', [\App\Http\Controllers\AdminController::class, 'banners'])
        ->middleware('can:movies.update')->name('banners.index');
    Route::post('/banners', [\App\Http\Controllers\AdminController::class, 'storeBanner'])
        ->middleware('can:movies.update')->name('banners.store');
    Route::put('/banners/{banner}/toggle', [\App\Http\Controllers\AdminController::class, 'toggleBanner'])
        ->middleware('can:movies.update')->name('banners.toggle');
    Route::delete('/banners/{banner}', [\App\Http\Controllers\AdminController::class, 'destroyBanner'])
        ->middleware('can:movies.update')->name('banners.destroy');

    // ─── Series: Seasons + Episodes (nested under a movie) ──────
    // Resource minus `show` — admin uses `index` listing instead. Episode
    // routes are double-nested so the URL itself documents the hierarchy
    // (admin/movies/{movie}/seasons/{season}/episodes/{episode}).
    // scoped() ensures Laravel only resolves a Season that belongs to
    // the URL's Movie (and an Episode that belongs to that Season), so
    // a crafted URL with mismatched IDs returns 404, not a leak.
    Route::resource('/movies/{movie}/seasons', \App\Http\Controllers\Admin\SeasonController::class)
        ->except(['show'])
        ->scoped()
        ->middleware('can:movies.update')
        ->names('movies.seasons');
    Route::resource('/movies/{movie}/seasons/{season}/episodes', \App\Http\Controllers\Admin\EpisodeController::class)
        ->except(['show'])
        ->scoped()
        ->middleware('can:movies.update')
        ->names('movies.seasons.episodes');

    // ─── Movie Subtitles (per-movie manager) ─────────────────────
    Route::get('/movies/{movie}/subtitles', [\App\Http\Controllers\Admin\SubtitleController::class, 'index'])
        ->middleware('can:subtitles.generate')->name('movies.subtitles.index');
    Route::post('/movies/{movie}/subtitles/generate', [\App\Http\Controllers\Admin\SubtitleController::class, 'generate'])
        ->middleware('can:subtitles.generate')->name('movies.subtitles.generate');
    Route::post('/movies/{movie}/subtitles/translate', [\App\Http\Controllers\Admin\SubtitleController::class, 'translate'])
        ->middleware('can:subtitles.translate')->name('movies.subtitles.translate');
    Route::delete('/movies/{movie}/subtitles/{subtitle}', [\App\Http\Controllers\Admin\SubtitleController::class, 'destroy'])
        ->middleware('can:subtitles.generate')->name('movies.subtitles.destroy');
    Route::post('/movies/{movie}/subtitles/{subtitle}/default', [\App\Http\Controllers\Admin\SubtitleController::class, 'setDefault'])
        ->middleware('can:subtitles.generate')->name('movies.subtitles.default');

    // ─── AI Providers ────────────────────────────────────────────
    Route::get('/ai-settings', [\App\Http\Controllers\AdminController::class, 'aiSettings'])
        ->middleware('can:ai.providers.configure')->name('ai.index');
    Route::post('/ai-settings', [\App\Http\Controllers\AdminController::class, 'storeAiProvider'])
        ->middleware('can:ai.providers.configure')->name('ai.store');
    Route::put('/ai-settings/{aiProvider}', [\App\Http\Controllers\AdminController::class, 'updateAiProvider'])
        ->middleware('can:ai.providers.configure')->name('ai.update');
    Route::put('/ai-settings/{aiProvider}/toggle', [\App\Http\Controllers\AdminController::class, 'toggleAiProvider'])
        ->middleware('can:ai.providers.configure')->name('ai.toggle');
    Route::delete('/ai-settings/{aiProvider}', [\App\Http\Controllers\AdminController::class, 'destroyAiProvider'])
        ->middleware('can:ai.providers.configure')->name('ai.destroy');

    // ─── Pitch Deck (no extra permission — bare `can:admin` only) ──
    // Pitch deck — password-gated like /admin/docs (default password ott2026,
    // configurable via /admin/settings → pages.protected_password).
    Route::match(['get', 'post'], '/pitch-deck', [\App\Http\Controllers\AdminController::class, 'pitchDeck'])
        ->middleware('page-password')->name('pitch-deck');
    Route::match(['get', 'post'], '/pitch-deck.md', [\App\Http\Controllers\AdminController::class, 'pitchDeckMarkdown'])
        ->middleware('page-password')->name('pitch-deck.md');

    // ━━━ SWARM AI FEATURES INTEGRATION ━━━

    // AI Usage Dashboard
    Route::get('/ai-usage', [\App\Http\Controllers\Admin\AiUsageController::class, 'index'])
        ->middleware('can:ai.usage.view')->name('ai.usage');

    // AI Provider connection tester
    Route::post('/ai-settings/{aiProvider}/test', [\App\Http\Controllers\Admin\AiProviderTestController::class, 'test'])
        ->middleware('can:ai.providers.configure')->name('ai.test');

    // Audit Logs
    Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])
        ->middleware('can:security.audit_logs')->name('audit-logs.index');

    // ━━━ i18n — Translation coverage + AI cache stats ━━━
    // Surfaces per-locale UI string coverage (diff of lang/<code>.json files)
    // alongside translation_cache hit rate. Read-only; rerunning a translation
    // happens via the user-facing path (clear cache rows manually if needed).
    Route::get('/translations', [\App\Http\Controllers\Admin\TranslationDashboardController::class, 'index'])
        ->name('translations.index');

    // ━━━ Menu Matrix — visual audit of sidebar visibility per role ━━━
    // Source-of-truth for the table is config/admin_menu.php (same file the
    // sidebar component renders from). Gated on `roles.manage` so only
    // peers with role-admin authority can view the visibility audit.
    Route::get('/menu-matrix', [\App\Http\Controllers\Admin\MenuMatrixController::class, 'index'])
        ->middleware('can:roles.manage')->name('menu-matrix.index');

    // ━━━ Security: WAF-lite banned IP manager ━━━
    Route::get('/security/waf-banned-ips', [\App\Http\Controllers\Admin\WafController::class, 'index'])
        ->middleware('can:security.waf')->name('security.waf.banned-ips');
    Route::post('/security/waf-banned-ips/unban', [\App\Http\Controllers\Admin\WafController::class, 'unban'])
        ->middleware('can:security.waf')->name('security.waf.unban');

    // Sentiment Dashboard
    Route::get('/sentiment/{movie?}', [\App\Http\Controllers\Admin\SentimentDashboardController::class, 'index'])
        ->middleware('can:comments.moderate')->name('sentiment.index');

    // AI Movie Reviews (multi-perspective)
    Route::get('/movies/{movie}/ai-reviews', [\App\Http\Controllers\Admin\AiReviewController::class, 'index'])
        ->middleware('can:ai.tasks.run')->name('movies.ai-reviews.index');
    Route::post('/movies/{movie}/ai-reviews/generate', [\App\Http\Controllers\Admin\AiReviewController::class, 'generate'])
        ->middleware('can:ai.tasks.run')->name('movies.ai-reviews.generate');

    // Marketing AI (banner + social media)
    Route::get('/movies/{movie}/marketing-ai/banner', [\App\Http\Controllers\Admin\MarketingAiController::class, 'bannerForm'])
        ->middleware('can:marketing.banner')->name('movies.marketing-ai.banner');
    Route::post('/movies/{movie}/marketing-ai/banner', [\App\Http\Controllers\Admin\MarketingAiController::class, 'generateBanner'])
        ->middleware('can:marketing.banner')->name('movies.marketing-ai.banner.generate');
    Route::get('/movies/{movie}/marketing-ai/social', [\App\Http\Controllers\Admin\MarketingAiController::class, 'socialForm'])
        ->middleware('can:marketing.social')->name('movies.marketing-ai.social');
    Route::post('/movies/{movie}/marketing-ai/social', [\App\Http\Controllers\Admin\MarketingAiController::class, 'generateSocial'])
        ->middleware('can:marketing.social')->name('movies.marketing-ai.social.generate');

    // Per-movie AI: soundtrack analyzer (FIX #7). Queues AnalyzeSoundtrack
    // on ai-batch; result is rendered on the public detail page when
    // movies.soundtrack_analysis is populated.
    Route::post('/movies/{movie}/soundtrack', [\App\Http\Controllers\Admin\MovieAiController::class, 'soundtrack'])
        ->middleware('can:ai.tasks.run')->name('movies.soundtrack.generate');

    // Comment Moderation Queue
    Route::get('/comments/queue', [\App\Http\Controllers\Admin\CommentModerationController::class, 'queue'])
        ->middleware('can:comments.moderate')->name('comments.queue');
    Route::patch('/comments/{comment}/approve', [\App\Http\Controllers\Admin\CommentModerationController::class, 'approve'])
        ->middleware('can:comments.moderate')->name('comments.approve');
    Route::patch('/comments/{comment}/reject', [\App\Http\Controllers\Admin\CommentModerationController::class, 'reject'])
        ->middleware('can:comments.moderate')->name('comments.reject');
    Route::post('/comments/{comment}/rerun', [\App\Http\Controllers\Admin\CommentModerationController::class, 'rerun'])
        ->middleware('can:comments.moderate')->name('comments.rerun');

    // ━━━ SWARM 25 ROUTES ━━━

    // Movie video upload + transcoding control. The GET upload page is the
    // canonical destination after creating a movie via the metadata form —
    // legacy AdminController::store/updateMovie now redirects here for any
    // video work. See docs/audit/04-drm-playback.md FIX #2 §6.
    Route::get('/movies/{movie}/upload', [\App\Http\Controllers\Admin\MovieUploadController::class, 'showUploadPage'])
        ->middleware('can:movies.upload_master')->name('movies.upload-page');
    Route::post('/movies/{movie}/upload-master', [\App\Http\Controllers\Admin\MovieUploadController::class, 'uploadMaster'])
        ->middleware('can:movies.upload_master')->name('movies.upload-master');
    // Direct browser → GCS (S3-compatible) upload: sign a presigned PUT, then
    // finalize once the object lands. Keeps the 2GB payload off the PHP server.
    Route::post('/movies/{movie}/sign-upload', [\App\Http\Controllers\Admin\MovieUploadController::class, 'signUpload'])
        ->middleware('can:movies.upload_master')->name('movies.sign-upload');
    Route::post('/movies/{movie}/finalize-upload', [\App\Http\Controllers\Admin\MovieUploadController::class, 'finalizeUpload'])
        ->middleware('can:movies.upload_master')->name('movies.finalize-upload');
    Route::post('/movies/{movie}/start-transcode', [\App\Http\Controllers\Admin\MovieUploadController::class, 'startTranscode'])
        ->middleware('can:movies.upload_master')->name('movies.start-transcode');
    Route::get('/movies/{movie}/encoding-status', [\App\Http\Controllers\Admin\MovieUploadController::class, 'encodingStatus'])
        ->middleware('can:movies.encoding_status')->name('movies.encoding-status');

    // Subtitle variants (dialect / kid-safe / speaker tags)
    Route::post('/movies/{movie}/subtitles/dialect', [\App\Http\Controllers\Admin\SubtitleController::class, 'translateDialect'])
        ->middleware('can:subtitles.translate')->name('movies.subtitles.dialect');
    Route::post('/movies/{movie}/subtitles/kid-safe', [\App\Http\Controllers\Admin\SubtitleController::class, 'kidSafeFilter'])
        ->middleware('can:subtitles.translate')->name('movies.subtitles.kid-safe');
    Route::post('/movies/{movie}/subtitles/speaker-tags', [\App\Http\Controllers\Admin\SubtitleController::class, 'addSpeakerTags'])
        ->middleware('can:subtitles.translate')->name('movies.subtitles.speaker-tags');

    // Director Auteur Analysis
    Route::get('/director-analyses', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'index'])
        ->middleware('can:ai.tasks.run')->name('director-analyses.index');
    Route::post('/director-analyses', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'analyze'])
        ->middleware('can:ai.tasks.run')->name('director-analyses.analyze');
    Route::get('/director-analyses/{directorSlug}', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'show'])
        ->middleware('can:ai.tasks.run')->name('director-analyses.show');
    Route::post('/director-analyses/{directorSlug}/refresh', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'refresh'])
        ->middleware('can:ai.tasks.run')->name('director-analyses.refresh');
    Route::delete('/director-analyses/{directorSlug}', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'destroy'])
        ->middleware('can:ai.tasks.run')->name('director-analyses.destroy');

    // Churn Risk Dashboard
    Route::get('/churn', [\App\Http\Controllers\Admin\ChurnDashboardController::class, 'index'])
        ->middleware('can:analytics.churn')->name('churn.dashboard');

    // AI Insights (content gap + pricing)
    Route::get('/insights/content-gap', [\App\Http\Controllers\Admin\AiInsightsController::class, 'contentGap'])
        ->middleware('can:analytics.insights')->name('insights.content-gap');
    Route::get('/insights/pricing', [\App\Http\Controllers\Admin\AiInsightsController::class, 'pricing'])
        ->middleware('can:analytics.insights')->name('insights.pricing');

    // Revenue + Geo + Cohort + Funnel + A/B (D1/D14/D2/D3/D6)
    Route::get('/revenue', [\App\Http\Controllers\Admin\RevenueDashboardController::class, 'index'])
        ->middleware('can:analytics.revenue')->name('revenue.dashboard');
    Route::get('/geo', [\App\Http\Controllers\Admin\GeoDistributionController::class, 'index'])
        ->middleware('can:analytics.geo')->name('geo.distribution');
    Route::get('/cohorts', [\App\Http\Controllers\Admin\CohortDashboardController::class, 'index'])
        ->middleware('can:analytics.cohort')->name('cohorts.index');
    Route::get('/cohorts/export.csv', [\App\Http\Controllers\Admin\CohortDashboardController::class, 'export'])
        ->middleware('can:analytics.cohort')->name('cohorts.export');
    Route::get('/funnel', [\App\Http\Controllers\Admin\FunnelDashboardController::class, 'index'])
        ->middleware('can:analytics.funnel')->name('funnel.index');
    Route::get('/ab-tests', [\App\Http\Controllers\Admin\AbTestController::class, 'index'])
        ->middleware('can:analytics.funnel')->name('ab-tests.index');
    Route::get('/ab-tests/create', [\App\Http\Controllers\Admin\AbTestController::class, 'create'])
        ->middleware('can:analytics.funnel')->name('ab-tests.create');
    Route::post('/ab-tests', [\App\Http\Controllers\Admin\AbTestController::class, 'store'])
        ->middleware('can:analytics.funnel')->name('ab-tests.store');
    Route::get('/ab-tests/{experiment}', [\App\Http\Controllers\Admin\AbTestController::class, 'show'])
        ->middleware('can:analytics.funnel')->name('ab-tests.show');
    Route::post('/ab-tests/{experiment}/{action}', [\App\Http\Controllers\Admin\AbTestController::class, 'act'])
        ->middleware('can:analytics.funnel')->name('ab-tests.act');

    // Performance Dashboard (P1) — AI latency, queue lag, cache + DB stats, slow queries
    Route::get('/performance', [\App\Http\Controllers\Admin\PerformanceDashboardController::class, 'index'])
        ->middleware('can:analytics.performance')->name('performance.index');

    // Marketing Ops (TikTok clips, title alternatives, email A/B, CS reply)
    Route::get('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'tikTokClipsForm'])
        ->middleware('can:marketing.tiktok')->name('movies.marketing-ops.tiktok-clips');
    Route::post('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTikTokClips'])
        ->middleware('can:marketing.tiktok')->name('movies.marketing-ops.tiktok-clips.generate');
    Route::get('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'titleAlternativesForm'])
        ->middleware('can:marketing.social')->name('movies.marketing-ops.title-alternatives');
    Route::post('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTitleAlternatives'])
        ->middleware('can:marketing.social')->name('movies.marketing-ops.title-alternatives.generate');
    Route::get('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'emailSubjectsForm'])
        ->middleware('can:marketing.email_ab')->name('marketing-ops.email-subjects');
    Route::post('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateEmailSubjects'])
        ->middleware('can:marketing.email_ab')->name('marketing-ops.email-subjects.generate');
    Route::get('/marketing-ops/cs-reply', fn () => view('admin.marketing-ops.cs-reply-drafter'))
        ->middleware('can:marketing.cs_reply')->name('marketing-ops.cs-reply');
    Route::post('/marketing-ops/cs-reply', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'csReplyDraft'])
        ->middleware('can:marketing.cs_reply')->name('marketing-ops.cs-reply.generate');

    // ━━━ Email Campaign Builder ━━━
    // Admin composes a campaign, picks a segment, optionally calls AI to
    // draft copy, previews audience, then `send` flips draft → sending and
    // CampaignDispatcher fans out per-recipient SendCampaignEmail jobs on
    // the `ai-batch` queue. Tracking endpoints (open pixel + click) live
    // OUTSIDE the admin group — they're public, hit by mail clients.
    //
    // All actions share the seeded `marketing.email_ab` permission slug
    // (the sidebar label is "Email Campaigns" / "marketing.email" — they
    // map to the same authorisation gate).
    //
    // Helper POSTs (ai-draft, preview-audience) come BEFORE the resource
    // declaration so Laravel doesn't try to route them through {id}.
    Route::post('/email-campaigns/ai-draft', [\App\Http\Controllers\Admin\EmailCampaignController::class, 'aiDraft'])
        ->middleware('can:marketing.email_ab')->name('email-campaigns.ai-draft');
    Route::post('/email-campaigns/preview-audience', [\App\Http\Controllers\Admin\EmailCampaignController::class, 'previewAudience'])
        ->middleware('can:marketing.email_ab')->name('email-campaigns.preview-audience');
    Route::post('/email-campaigns/{emailCampaign}/send', [\App\Http\Controllers\Admin\EmailCampaignController::class, 'send'])
        ->middleware('can:marketing.email_ab')->name('email-campaigns.send');
    Route::post('/email-campaigns/{emailCampaign}/cancel', [\App\Http\Controllers\Admin\EmailCampaignController::class, 'cancel'])
        ->middleware('can:marketing.email_ab')->name('email-campaigns.cancel');
    Route::get('/email-campaigns/{emailCampaign}/report', [\App\Http\Controllers\Admin\EmailCampaignController::class, 'report'])
        ->middleware('can:marketing.email_ab')->name('email-campaigns.report');
    Route::resource('/email-campaigns', \App\Http\Controllers\Admin\EmailCampaignController::class)
        ->parameters(['email-campaigns' => 'emailCampaign'])
        ->middleware('can:marketing.email_ab');

    // ━━━ API Keys (service-to-service auth) ━━━
    // Plaintext keys are shown ONCE in a flash modal after creation.
    // Revoke is soft (sets revoked_at) so audit_logs entries remain joinable.
    Route::get('/api-keys', [\App\Http\Controllers\Admin\ApiKeyController::class, 'index'])
        ->middleware('can:security.api_keys')->name('api-keys.index');
    Route::post('/api-keys', [\App\Http\Controllers\Admin\ApiKeyController::class, 'store'])
        ->middleware('can:security.api_keys')->name('api-keys.store');
    Route::delete('/api-keys/{apiKey}', [\App\Http\Controllers\Admin\ApiKeyController::class, 'destroy'])
        ->middleware('can:security.api_keys')
        ->whereNumber('apiKey')->name('api-keys.destroy');

    // ━━━ Queue Dashboard (Horizon-lite) ━━━
    // Gated by the operational `system.queues` permission. All seven
    // endpoints share the same gate — retry/forget/flush are equally
    // sensitive (they mutate worker state) and live counts leak nothing
    // beyond what an operator with queue access already sees.
    //
    // `{id}` for retry/forget is the failed_jobs UUID, NOT the numeric
    // PK — that's the canonical identifier `queue:retry` / `queue:forget`
    // accept, and Laravel's default failed-job driver is `database-uuids`.
    Route::prefix('queues')->name('queue-dashboard.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'index'])
            ->middleware('can:system.queues')->name('index');
        Route::get('/live', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'liveCounts'])
            ->middleware('can:system.queues')->name('live');
        Route::get('/failed', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'failed'])
            ->middleware('can:system.queues')->name('failed');
        Route::post('/retry/{id}', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'retry'])
            ->middleware('can:system.queues')->name('retry');
        Route::post('/retry-all', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'retryAll'])
            ->middleware('can:system.queues')->name('retry-all');
        Route::delete('/forget/{id}', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'forget'])
            ->middleware('can:system.queues')->name('forget');
        Route::post('/flush', [\App\Http\Controllers\Admin\QueueDashboardController::class, 'flushFailed'])
            ->middleware('can:system.queues')->name('flush');
    });

    // ━━━ Promo Codes (subscription discount tokens) ━━━
    // Gated on `promo.manage` permission (added via RolePermissionSeeder
    // marketing category). AuthServiceProvider's `Gate::before` fallback
    // makes legacy `is_admin` users see these routes even before the seeder
    // re-runs, so the UX never breaks on a stale seed.
    // bulk-generate + report sit OUTSIDE Route::resource() so their URLs
    // are discoverable (/admin/promo-codes/bulk-generate, /report). They
    // are declared BEFORE the resource so the {promo_code} catch-all
    // doesn't try to bind a model named "report" or "bulk-generate".
    Route::post('/promo-codes/bulk-generate', [\App\Http\Controllers\Admin\PromoCodeController::class, 'bulkGenerate'])
        ->middleware('can:promo.manage')->name('promo-codes.bulk-generate');
    Route::get('/promo-codes/report', [\App\Http\Controllers\Admin\PromoCodeReportController::class, 'index'])
        ->middleware('can:promo.manage')->name('promo-codes.report');
    Route::resource('/promo-codes', \App\Http\Controllers\Admin\PromoCodeController::class)
        ->except(['show'])
        ->middleware('can:promo.manage');

    // ━━━ Web Push Broadcasts (admin sender) ━━━
    // `push.send` permission is seeded by RolePermissionSeeder. Until the
    // seed runs the legacy `is_admin` Gate fallback still grants access.
    Route::get('/push', [\App\Http\Controllers\Admin\PushBroadcastController::class, 'index'])
        ->middleware('can:push.send')->name('push.index');
    Route::get('/push/create', [\App\Http\Controllers\Admin\PushBroadcastController::class, 'create'])
        ->middleware('can:push.send')->name('push.create');
    Route::post('/push', [\App\Http\Controllers\Admin\PushBroadcastController::class, 'store'])
        ->middleware('can:push.send')->name('push.store');

    // ━━━ Admin Notifications (peer NOTIF #1 — realtime staff alerts) ━━━
    // Bare `can:admin` gate is intentional — the bell widget must reach every
    // staff role. Per-notification audience checks happen inside the controller
    // (see NotificationController::authorizeAudience). The /unread-count
    // endpoint is the polling fallback when BROADCAST_DRIVER=null|log.
    Route::get('/notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Admin\NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::get('/notifications/{adminNotification}', [\App\Http\Controllers\Admin\NotificationController::class, 'show'])
        ->whereNumber('adminNotification')->name('notifications.show');
    Route::post('/notifications/{adminNotification}/read', [\App\Http\Controllers\Admin\NotificationController::class, 'markRead'])
        ->whereNumber('adminNotification')->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\Admin\NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');

    // ━━━ Maintenance Mode (App-level kill switch, separate from `artisan down`) ━━━
    // Gated on `system.maintenance` — seeded only to super_admin. The
    // CheckCustomMaintenance middleware short-circuits requests to
    // /admin/maintenance* so an admin can always disable the switch
    // they enabled, even from a non-allow-listed IP.
    Route::get('/maintenance', [\App\Http\Controllers\Admin\MaintenanceController::class, 'index'])
        ->middleware('can:system.maintenance')->name('maintenance.index');
    Route::post('/maintenance/enable', [\App\Http\Controllers\Admin\MaintenanceController::class, 'enable'])
        ->middleware('can:system.maintenance')->name('maintenance.enable');
    Route::post('/maintenance/disable', [\App\Http\Controllers\Admin\MaintenanceController::class, 'disable'])
        ->middleware('can:system.maintenance')->name('maintenance.disable');
    Route::post('/maintenance/update', [\App\Http\Controllers\Admin\MaintenanceController::class, 'update'])
        ->middleware('can:system.maintenance')->name('maintenance.update');

    // ━━━ Health Dashboard (operational diagnostics) ━━━
    // Same engine as `php artisan flik:doctor`. Gated on `system.health`
    // — granted to both admin and super_admin via the seeder. The JSON
    // sub-endpoint powers the per-card auto-refresh poller in the view.
    Route::get('/health', [\App\Http\Controllers\Admin\HealthDashboardController::class, 'index'])
        ->middleware('can:system.health')->name('health.index');
    Route::get('/health/check/{section}', [\App\Http\Controllers\Admin\HealthDashboardController::class, 'runCheck'])
        ->middleware('can:system.health')
        ->where('section', '[a-z]+')
        ->name('health.check');

    // ━━━ Feature Flags (runtime rollout toggles) ━━━
    // Backed by App\Models\FeatureFlag + App\Services\Features\FeatureManager.
    // Every action gated on `system.feature_flags` (granted to admin +
    // super_admin via RolePermissionSeeder). Per-action middleware mirrors
    // the controller's $this->authorize() calls so a request without the
    // permission short-circuits BEFORE the controller is even resolved.
    //
    // Explicit Route::bind so the URL stays the prettier `{flag}` while the
    // controller signature keeps the readable `FeatureFlag $featureFlag` —
    // implicit binding requires param-name match, so we wire it manually.
    Route::bind('flag', function (string $value) {
        return \App\Models\FeatureFlag::query()->findOrFail((int) $value);
    });
    Route::get('/feature-flags', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'index'])
        ->middleware('can:system.feature_flags')->name('feature-flags.index');
    Route::get('/feature-flags/create', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'create'])
        ->middleware('can:system.feature_flags')->name('feature-flags.create');
    Route::post('/feature-flags', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'store'])
        ->middleware('can:system.feature_flags')->name('feature-flags.store');
    Route::get('/feature-flags/{flag}/edit', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'edit'])
        ->middleware('can:system.feature_flags')->name('feature-flags.edit');
    Route::put('/feature-flags/{flag}', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'update'])
        ->middleware('can:system.feature_flags')->name('feature-flags.update');
    Route::delete('/feature-flags/{flag}', [\App\Http\Controllers\Admin\FeatureFlagController::class, 'destroy'])
        ->middleware('can:system.feature_flags')->name('feature-flags.destroy');

    // ━━━ Settings Registry (runtime-editable key/value store) ━━━
    // Backed by App\Models\Setting + setting()/Setting::get helpers.
    // Single bulk-update endpoint per the controller contract — the index
    // form posts every dirty setting back at once. Restore-defaults reverts
    // every seeded key to its canonical value (destructive on purpose).
    Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])
        ->middleware('can:system.settings')->name('settings.index');
    Route::post('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])
        ->middleware('can:system.settings')->name('settings.update');
    Route::post('/settings/restore-defaults', [\App\Http\Controllers\Admin\SettingsController::class, 'seed'])
        ->middleware('can:system.settings')->name('settings.restore-defaults');

    // ━━━ Editorial Blog (admin CRUD + AI assist) ━━━
    // Gated on `blog.manage` (seeded under the `content` category). The
    // legacy `Gate::before` admin fallback in AuthServiceProvider keeps
    // these routes reachable for users with the old `is_admin` flag even
    // before the seeder runs.
    //
    // AI assist + restore + publish endpoints come BEFORE the resource
    // declaration so Laravel doesn't try to bind {post} = "ai" / "restore"
    // / etc.
    Route::post('/blog/posts/ai/suggest-titles', [\App\Http\Controllers\Admin\BlogAiController::class, 'suggestTitles'])
        ->middleware('can:blog.manage')->name('blog.ai.suggest-titles');
    Route::post('/blog/posts/ai/outline', [\App\Http\Controllers\Admin\BlogAiController::class, 'outline'])
        ->middleware('can:blog.manage')->name('blog.ai.outline');
    Route::post('/blog/posts/ai/enrich', [\App\Http\Controllers\Admin\BlogAiController::class, 'enrich'])
        ->middleware('can:blog.manage')->name('blog.ai.enrich');

    Route::post('/blog/posts/{id}/restore', [\App\Http\Controllers\Admin\BlogPostController::class, 'restore'])
        ->whereNumber('id')
        ->middleware('can:blog.manage')->name('blog.posts.restore');
    Route::post('/blog/posts/{post}/publish', [\App\Http\Controllers\Admin\BlogPostController::class, 'publish'])
        ->middleware('can:blog.manage')->name('blog.posts.publish');

    Route::resource('/blog/posts', \App\Http\Controllers\Admin\BlogPostController::class)
        ->except(['show'])
        ->parameters(['posts' => 'post'])
        ->middleware('can:blog.manage')
        ->names('blog.posts');

    Route::resource('/blog/categories', \App\Http\Controllers\Admin\BlogCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['categories' => 'category'])
        ->middleware('can:blog.manage')
        ->names('blog.categories');

    // ━━━ Help Center CMS (admin CRUD + AI assist) ━━━
    // Gated on `help.manage` (content category) — granted to admin +
    // content_editor via RolePermissionSeeder. AI assist + publish
    // endpoints come BEFORE the resource declaration so Laravel doesn't
    // bind {article} = "ai" / "publish" / etc.
    Route::post('/help/articles/ai/suggest-title', [\App\Http\Controllers\Admin\HelpAiController::class, 'suggestTitle'])
        ->middleware('can:help.manage')->name('help.ai.suggest-title');
    Route::post('/help/articles/ai/draft-answer', [\App\Http\Controllers\Admin\HelpAiController::class, 'draftAnswer'])
        ->middleware('can:help.manage')->name('help.ai.draft-answer');
    Route::post('/help/articles/ai/improve', [\App\Http\Controllers\Admin\HelpAiController::class, 'improve'])
        ->middleware('can:help.manage')->name('help.ai.improve');

    Route::post('/help/articles/{article}/publish', [\App\Http\Controllers\Admin\HelpArticleController::class, 'publish'])
        ->middleware('can:help.manage')->name('help.articles.publish');

    Route::resource('/help/articles', \App\Http\Controllers\Admin\HelpArticleController::class)
        ->except(['show'])
        ->parameters(['articles' => 'article'])
        ->middleware('can:help.manage')
        ->names('help.articles');

    Route::resource('/help/categories', \App\Http\Controllers\Admin\HelpCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['categories' => 'category'])
        ->middleware('can:help.manage')
        ->names('help.categories');

    // ━━━ Gift Subscriptions (admin read-only inventory) ━━━
    // Read-only listing — refunds are issued directly on the Midtrans
    // dashboard so the gateway stays the source of truth. The admin
    // controller exposes only an index() action today; a refund() helper
    // is on the roadmap but unimplemented (see GiftSubscriptionAdminController
    // docblock + audits/11-payment.md). Gated on the bare `can:admin`
    // umbrella — every billing-flavoured admin role passes through, and
    // there's no destructive write to require a sharper permission for.
    Route::get('/gifts', [\App\Http\Controllers\Admin\GiftSubscriptionAdminController::class, 'index'])
        ->name('gifts.index');

    // ━━━ Refer-a-friend program (admin reporting) ━━━
    // index()  — filterable conversion ledger
    // report() — KPI cards + top-referrers leaderboard
    // Both read-only. Same `can:admin` umbrella applies — finance + marketing
    // peers need the funnel snapshot without a dedicated permission slug.
    Route::get('/referrals', [\App\Http\Controllers\Admin\ReferralAdminController::class, 'index'])
        ->name('referrals.index');
    Route::get('/referrals/report', [\App\Http\Controllers\Admin\ReferralAdminController::class, 'report'])
        ->name('referrals.report');
});

// ━━━ User-facing AI Features ━━━

Route::middleware('auth')->group(function () {
    // Onboarding (cold-start)
    Route::get('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'quiz'])->name('onboarding.quiz');
    Route::post('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'submit'])->name('onboarding.submit');
    // Suppresses the home-page "Tell us what you like" nudge banner for the
    // remainder of the session (per-session, not persisted to user row).
    Route::post('/onboarding/dismiss', [\App\Http\Controllers\OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');

    // Mood Discovery — POST kicks off a real LLM call so it goes through
    // the 'ai-batch' limiter (50/hr/user). The GET form view is cheap and
    // intentionally unguarded.
    Route::get('/discover/mood', [\App\Http\Controllers\MoodDiscoveryController::class, 'form'])->name('discovery.mood.form');
    Route::post('/discover/mood', [\App\Http\Controllers\MoodDiscoveryController::class, 'discover'])
        ->middleware('throttle:ai-batch')
        ->name('discovery.mood.discover');

    // Personalized Recommendations — read-only JSON endpoints. Named
    // 'search' limiter (60/min/user) absorbs autocomplete-style polling
    // without firing on real navigation.
    Route::get('/api/recommendations', [\App\Http\Controllers\RecommendationController::class, 'forUser'])
        ->middleware('throttle:search')
        ->name('recommendations.me');
    Route::get('/api/recommendations/time', [\App\Http\Controllers\RecommendationController::class, 'byTimeOfDay'])
        ->middleware('throttle:search')
        ->name('recommendations.time');

    // Movie Comparison — POSTs trigger LLM calls so they share the
    // 'ai-batch' budget. The GET form is unguarded (cheap view render).
    Route::get('/compare', [\App\Http\Controllers\MovieComparisonController::class, 'form'])->name('compare.form');
    Route::post('/compare', [\App\Http\Controllers\MovieComparisonController::class, 'compare'])
        ->middleware('throttle:ai-batch')
        ->name('compare.run');
    Route::post('/api/compare', [\App\Http\Controllers\MovieComparisonController::class, 'compareApi'])
        ->middleware('throttle:ai-batch')
        ->name('compare.api');

    // Year In Review
    Route::get('/year-in-review', [\App\Http\Controllers\YearInReviewController::class, 'show'])->name('year-in-review.show');
    Route::get('/year-in-review/{year}', [\App\Http\Controllers\YearInReviewController::class, 'show'])
        ->whereNumber('year')->name('year-in-review.year');
    Route::post('/year-in-review/{id}/share', [\App\Http\Controllers\YearInReviewController::class, 'share'])
        ->whereNumber('id')->name('year-in-review.share');

    // Smart Watchlist + Family Movie Night — recommend POST is an LLM call
    // (combines multiple users' preferences) so it lives in the ai-batch
    // budget. The list and form views are unguarded.
    Route::get('/watchlist/smart', [\App\Http\Controllers\SmartWatchlistController::class, 'prioritized'])->name('watchlist.smart');
    Route::get('/family-night', [\App\Http\Controllers\FamilyNightController::class, 'form'])->name('family-night.form');
    Route::post('/family-night', [\App\Http\Controllers\FamilyNightController::class, 'recommend'])
        ->middleware('throttle:ai-batch')
        ->name('family-night.recommend');

    // Highlight Reels
    Route::get('/movie/{movie}/highlight', [\App\Http\Controllers\HighlightReelController::class, 'show'])->name('highlight.show');
    Route::get('/movie/{movie}/highlight/download', [\App\Http\Controllers\HighlightReelController::class, 'download'])->name('highlight.download');

    // Universal Smart Search (intent classification → routed to specialised
    // services). Both endpoints share the 'search' limiter (60/min/user)
    // which is sized for autocomplete keystroke traffic.
    Route::get('/search', [\App\Http\Controllers\SmartSearchController::class, 'search'])
        ->middleware('throttle:search')
        ->name('search.smart');
    Route::get('/api/search/autocomplete', [\App\Http\Controllers\SmartSearchController::class, 'autocomplete'])
        ->middleware('throttle:search')
        ->name('search.autocomplete');

    // Advanced Search (image / vibe / person) — POSTs do real ML work
    // (CLIP embeddings / vibe classification) so they go through the
    // 'ai-batch' budget. Form views are unguarded.
    Route::get('/search/image', [\App\Http\Controllers\AdvancedSearchController::class, 'imageForm'])->name('search.image.form');
    Route::post('/search/image', [\App\Http\Controllers\AdvancedSearchController::class, 'imageSearch'])
        ->middleware('throttle:ai-batch')
        ->name('search.image');
    Route::get('/search/vibe', [\App\Http\Controllers\AdvancedSearchController::class, 'vibeForm'])->name('search.vibe.form');
    Route::post('/search/vibe', [\App\Http\Controllers\AdvancedSearchController::class, 'vibeSearch'])
        ->middleware('throttle:ai-batch')
        ->name('search.vibe');
    Route::get('/search/person', [\App\Http\Controllers\AdvancedSearchController::class, 'personForm'])->name('search.person.form');
    Route::post('/search/person', [\App\Http\Controllers\AdvancedSearchController::class, 'personSearch'])
        ->middleware('throttle:ai-batch')
        ->name('search.person');

    // ── Encrypted playback (DRM-protected) ──
    // `geoblock` ensures the request country is in $movie->geo_allow before
    // any DRM session is minted or any manifest is emitted. Per audit FIX #2
    // §3.1 — middleware was registered but never mounted before.
    Route::get('/playback/{movie}/config', [\App\Http\Controllers\PlaybackController::class, 'config'])
        ->middleware('geoblock')
        ->name('playback.config');
    Route::get('/playback/{movie}/manifest.m3u8', [\App\Http\Controllers\PlaybackController::class, 'manifest'])
        ->middleware('geoblock')
        ->name('playback.manifest');
    Route::post('/playback/{movie}/heartbeat', [\App\Http\Controllers\PlaybackController::class, 'heartbeat'])
        ->middleware('geoblock')
        ->name('playback.heartbeat');
});

// ━━━ Public Profile + Social Layer (peer SOCIAL #1) ━━━
//
// Reads (show, followers list, following list) are intentionally GUEST-accessible:
// the whole point of /u/{username} is to expose the user externally. Private
// profiles (`is_public=false`) degrade to a minimal view server-side and the
// list endpoints 404 the same way a missing user would, so we never leak the
// existence of a hidden account via status-code differences.
//
// Writes (follow / unfollow) require auth and run their own self-action guard.
// The route-model-bound `User` uses the default `id` key so /u/{username} hits
// the controller's case-insensitive lookup but the action routes use the
// numeric id (cheaper + immune to a half-rename of the handle mid-session).
Route::get('/u/{username}', [\App\Http\Controllers\PublicProfileController::class, 'show'])
    ->where('username', '[A-Za-z0-9_\.]+')
    ->name('profile.public.show');

Route::get('/u/{user}/followers', [\App\Http\Controllers\PublicProfileController::class, 'followers'])
    ->whereNumber('user')
    ->name('profile.public.followers');
Route::get('/u/{user}/following', [\App\Http\Controllers\PublicProfileController::class, 'following'])
    ->whereNumber('user')
    ->name('profile.public.following');

Route::middleware('auth')->group(function () {
    Route::post('/u/{user}/follow', [\App\Http\Controllers\PublicProfileController::class, 'follow'])
        ->whereNumber('user')
        ->name('profile.public.follow');
    Route::delete('/u/{user}/follow', [\App\Http\Controllers\PublicProfileController::class, 'unfollow'])
        ->whereNumber('user')
        ->name('profile.public.unfollow');
});

// ━━━ User-curated lists (sharable, follow-able movie playlists) ━━━
//
// Distinct concept from the /my-list watchlist endpoint (private bookmark
// flat-list). UserList rows have a title, description, visibility
// (public/unlisted/private), follower graph, and manual item ordering.
//
// URL convention: /lists/{user:username}/{list:slug} with ->scopeBindings()
// so {list} is resolved within the {user}'s owned lists. Per-user slug
// uniqueness means two users can both have a list called "Best of 2025"
// without colliding.
//
// `auth-only` collection routes (mine, following, create, store) are declared
// BEFORE the catch-all show route so /lists/mine never matches as username
// "mine". Action routes (POST follow, DELETE, etc.) live in their own
// auth-guarded group further down.
Route::get('/lists', [\App\Http\Controllers\UserListController::class, 'index'])
    ->name('user-lists.index');

Route::middleware('auth')->group(function () {
    Route::get('/lists/mine', [\App\Http\Controllers\UserListController::class, 'mine'])
        ->name('user-lists.mine');
    Route::get('/lists/following', [\App\Http\Controllers\UserListController::class, 'following'])
        ->name('user-lists.following');
    Route::get('/lists/create', [\App\Http\Controllers\UserListController::class, 'create'])
        ->name('user-lists.create');
    Route::post('/lists', [\App\Http\Controllers\UserListController::class, 'store'])
        ->name('user-lists.store');
});

// Public show (visibility is enforced in the controller — guests can read
// public + unlisted lists; private 404s for non-owners).
Route::get('/lists/{user:username}/{list:slug}', [\App\Http\Controllers\UserListController::class, 'show'])
    ->scopeBindings()
    ->where('user', '[A-Za-z0-9_\.]+')
    ->name('user-lists.show');

// Owner-only mutating actions. scopeBindings() means {list} is resolved as
// $user->lists()->where('slug', $list) so a malicious slug can't match a
// list belonging to a different account.
Route::middleware('auth')->group(function () {
    Route::get('/lists/{user:username}/{list:slug}/edit', [\App\Http\Controllers\UserListController::class, 'edit'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.edit');
    Route::put('/lists/{user:username}/{list:slug}', [\App\Http\Controllers\UserListController::class, 'update'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.update');
    Route::delete('/lists/{user:username}/{list:slug}', [\App\Http\Controllers\UserListController::class, 'destroy'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.destroy');

    Route::post('/lists/{user:username}/{list:slug}/items', [\App\Http\Controllers\UserListController::class, 'addMovie'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.items.add');
    Route::delete('/lists/{user:username}/{list:slug}/items/{movie:id}', [\App\Http\Controllers\UserListController::class, 'removeMovie'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->whereNumber('movie')
        ->name('user-lists.items.remove');
    Route::post('/lists/{user:username}/{list:slug}/reorder', [\App\Http\Controllers\UserListController::class, 'reorder'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.reorder');

    Route::post('/lists/{user:username}/{list:slug}/follow', [\App\Http\Controllers\UserListController::class, 'follow'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.follow');
    Route::delete('/lists/{user:username}/{list:slug}/follow', [\App\Http\Controllers\UserListController::class, 'unfollow'])
        ->scopeBindings()
        ->where('user', '[A-Za-z0-9_\.]+')
        ->name('user-lists.unfollow');
});
