<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\VelflixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');
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
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('seo.robots');

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

    // Profile
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');

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

    // Payment — checkout requires a verified email so we can deliver receipts
    // and recovery codes. Browsing is intentionally NOT gated (see /movies).
    Route::middleware('verified')->group(function () {
        Route::get('/checkout/{plan}', [\App\Http\Controllers\PaymentController::class, 'checkout'])->name('payment.checkout');
        Route::get('/payment/success', [\App\Http\Controllers\PaymentController::class, 'success'])->name('payment.success');
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
    Route::post('/movie/{movie}/quiz', [\App\Http\Controllers\QuizController::class, 'submit'])->name('quiz.submit');
    Route::get('/movie/{movie}/quiz/leaderboard', [\App\Http\Controllers\QuizController::class, 'leaderboard'])->name('quiz.leaderboard');

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

// Midtrans Webhook (no auth required) — gateway can fan out per state
// change so the cap is generous (named 'webhook' limiter = 100/min/IP).
// Anchored on the IP so a single misbehaving sender can't drown legit ones.
Route::post('/payment/webhook', [\App\Http\Controllers\PaymentController::class, 'webhook'])
    ->middleware('throttle:webhook')
    ->name('payment.webhook');

// Health check endpoints (no auth — for load balancers / orchestrators)
Route::get('/healthz', [\App\Http\Controllers\HealthController::class, 'live'])->name('health.live');
Route::get('/healthz/ready', [\App\Http\Controllers\HealthController::class, 'ready'])->name('health.ready');
Route::get('/healthz/detailed', [\App\Http\Controllers\HealthController::class, 'detailed'])->name('health.detailed');

// AI Chatbot (auth required) — named 'ai-chat' limiter = 20/min/user,
// catches runaway client loops without blocking real conversation pace.
Route::post('/chat', [\App\Http\Controllers\ChatController::class, 'respond'])
    ->middleware(['auth', 'throttle:ai-chat'])
    ->name('chat.respond');

// AI Plot Explainer (auth required) — named 'ai-batch' limiter (50/hr/user)
// is the outer guard. The controller still keeps its own per-feature 10/hr
// budget for cost control, so this is intentional defence-in-depth, not a
// double-application of the same limit.
Route::post('/api/movies/{movie}/plot-explain', [\App\Http\Controllers\PlotExplainController::class, 'explain'])
    ->middleware(['auth', 'throttle:ai-batch'])
    ->name('movies.plot-explain');

// ━━━ DRM Key Endpoint (no auth — JWT-protected, fetched by Shaka Player) ━━━
Route::get('/drm/key/{sessionToken}/{keyId}', [\App\Http\Controllers\PlaybackController::class, 'key'])
    ->name('drm.key');

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
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
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

    // ─── Users ───────────────────────────────────────────────────
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users'])
        ->middleware('can:users.view')->name('users.index');
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
    Route::get('/pitch-deck', [\App\Http\Controllers\AdminController::class, 'pitchDeck'])->name('pitch-deck');
    Route::get('/pitch-deck.md', [\App\Http\Controllers\AdminController::class, 'pitchDeckMarkdown'])->name('pitch-deck.md');

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

    // Movie video upload + transcoding control
    Route::post('/movies/{movie}/upload-master', [\App\Http\Controllers\Admin\MovieUploadController::class, 'uploadMaster'])
        ->middleware('can:movies.upload_master')->name('movies.upload-master');
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
});

// ━━━ User-facing AI Features ━━━

Route::middleware('auth')->group(function () {
    // Onboarding (cold-start)
    Route::get('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'quiz'])->name('onboarding.quiz');
    Route::post('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'submit'])->name('onboarding.submit');

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
    Route::get('/playback/{movie}/config', [\App\Http\Controllers\PlaybackController::class, 'config'])->name('playback.config');
    Route::get('/playback/{movie}/manifest.m3u8', [\App\Http\Controllers\PlaybackController::class, 'manifest'])->name('playback.manifest');
    Route::post('/playback/{movie}/heartbeat', [\App\Http\Controllers\PlaybackController::class, 'heartbeat'])->name('playback.heartbeat');
});
