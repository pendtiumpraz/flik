<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\VelflixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');
Route::post('newsletter', NewsletterController::class);

// ━━━ SEO infrastructure (public, no auth — crawlers must reach these) ━━━
Route::get('/sitemap.xml', [\App\Http\Controllers\SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('seo.robots');

Route::middleware('guest')->group(function () {
    Route::get('login', [SessionsController::class, 'create'])->name('login');
    Route::post('login', [SessionsController::class, 'store']);
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [SessionsController::class, 'destroy'])->name('logout');
    Route::get('/movies', [VelflixController::class, 'index'])->name('velflix.index');
    Route::get('/movie/{watch}', [VelflixController::class, 'show'])->name('movies.show');

    // Watchlist
    Route::get('/my-list', [\App\Http\Controllers\WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('/watchlist/toggle', [\App\Http\Controllers\WatchlistController::class, 'toggle'])->name('watchlist.toggle');

    // Ratings
    Route::post('/rating', [\App\Http\Controllers\RatingController::class, 'store'])->name('rating.store');
    Route::delete('/rating', [\App\Http\Controllers\RatingController::class, 'destroy'])->name('rating.destroy');

    // Comments
    Route::post('/comment', [\App\Http\Controllers\CommentController::class, 'store'])->name('comment.store');
    Route::delete('/comment/{comment}', [\App\Http\Controllers\CommentController::class, 'destroy'])->name('comment.destroy');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');

    // Subscription Plans
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

    // Payment
    Route::get('/checkout/{plan}', [\App\Http\Controllers\PaymentController::class, 'checkout'])->name('payment.checkout');
    Route::get('/payment/success', [\App\Http\Controllers\PaymentController::class, 'success'])->name('payment.success');

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
});

// Midtrans Webhook (no auth required)
Route::post('/payment/webhook', [\App\Http\Controllers\PaymentController::class, 'webhook'])->name('payment.webhook');

// Health check endpoints (no auth — for load balancers / orchestrators)
Route::get('/healthz', [\App\Http\Controllers\HealthController::class, 'live'])->name('health.live');
Route::get('/healthz/ready', [\App\Http\Controllers\HealthController::class, 'ready'])->name('health.ready');
Route::get('/healthz/detailed', [\App\Http\Controllers\HealthController::class, 'detailed'])->name('health.detailed');

// AI Chatbot (auth required)
Route::middleware('auth')->post('/chat', [\App\Http\Controllers\ChatController::class, 'respond'])->name('chat.respond');

// AI Plot Explainer (auth required, rate-limited inside controller)
Route::post('/api/movies/{movie}/plot-explain', [\App\Http\Controllers\PlotExplainController::class, 'explain'])
    ->middleware('auth')
    ->name('movies.plot-explain');

// ━━━ DRM Key Endpoint (no auth — JWT-protected, fetched by Shaka Player) ━━━
Route::get('/drm/key/{sessionToken}/{keyId}', [\App\Http\Controllers\PlaybackController::class, 'key'])
    ->name('drm.key');

// X-Ray Actor Overlay route lives in routes/api.php (auto-prefixed /api)

Route::controller(LoginController::class)->group(function () {
    Route::get('login/google', 'redirectToProvider');
    Route::get('login/google/callback', 'handleProviderCallback');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AdminController::class, 'dashboard'])->name('dashboard');

    // Movies
    Route::get('/movies', [\App\Http\Controllers\AdminController::class, 'movies'])->name('movies.index');
    Route::get('/movies/create', [\App\Http\Controllers\AdminController::class, 'createMovie'])->name('movies.create');
    Route::post('/movies', [\App\Http\Controllers\AdminController::class, 'storeMovie'])->name('movies.store');
    Route::get('/movies/{movie}/edit', [\App\Http\Controllers\AdminController::class, 'editMovie'])->name('movies.edit');
    Route::put('/movies/{movie}', [\App\Http\Controllers\AdminController::class, 'updateMovie'])->name('movies.update');
    Route::delete('/movies/{movie}', [\App\Http\Controllers\AdminController::class, 'destroyMovie'])->name('movies.destroy');

    // Genres
    Route::get('/genres', [\App\Http\Controllers\AdminController::class, 'genres'])->name('genres.index');
    Route::post('/genres', [\App\Http\Controllers\AdminController::class, 'storeGenre'])->name('genres.store');
    Route::delete('/genres/{genre}', [\App\Http\Controllers\AdminController::class, 'destroyGenre'])->name('genres.destroy');

    // Casts
    Route::get('/casts', [\App\Http\Controllers\AdminController::class, 'casts'])->name('casts.index');
    Route::post('/casts', [\App\Http\Controllers\AdminController::class, 'storeCast'])->name('casts.store');
    Route::delete('/casts/{cast}', [\App\Http\Controllers\AdminController::class, 'destroyCast'])->name('casts.destroy');

    // Users
    Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users'])->name('users.index');
    Route::put('/users/{user}/toggle-admin', [\App\Http\Controllers\AdminController::class, 'toggleAdmin'])->name('users.toggleAdmin');
    Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'destroyUser'])->name('users.destroy');

    // Banners
    Route::get('/banners', [\App\Http\Controllers\AdminController::class, 'banners'])->name('banners.index');
    Route::post('/banners', [\App\Http\Controllers\AdminController::class, 'storeBanner'])->name('banners.store');
    Route::put('/banners/{banner}/toggle', [\App\Http\Controllers\AdminController::class, 'toggleBanner'])->name('banners.toggle');
    Route::delete('/banners/{banner}', [\App\Http\Controllers\AdminController::class, 'destroyBanner'])->name('banners.destroy');

    // Movie Subtitles (per-movie manager)
    Route::get('/movies/{movie}/subtitles', [\App\Http\Controllers\Admin\SubtitleController::class, 'index'])->name('movies.subtitles.index');
    Route::post('/movies/{movie}/subtitles/generate', [\App\Http\Controllers\Admin\SubtitleController::class, 'generate'])->name('movies.subtitles.generate');
    Route::post('/movies/{movie}/subtitles/translate', [\App\Http\Controllers\Admin\SubtitleController::class, 'translate'])->name('movies.subtitles.translate');
    Route::delete('/movies/{movie}/subtitles/{subtitle}', [\App\Http\Controllers\Admin\SubtitleController::class, 'destroy'])->name('movies.subtitles.destroy');
    Route::post('/movies/{movie}/subtitles/{subtitle}/default', [\App\Http\Controllers\Admin\SubtitleController::class, 'setDefault'])->name('movies.subtitles.default');

    // AI Providers
    Route::get('/ai-settings', [\App\Http\Controllers\AdminController::class, 'aiSettings'])->name('ai.index');
    Route::post('/ai-settings', [\App\Http\Controllers\AdminController::class, 'storeAiProvider'])->name('ai.store');
    Route::put('/ai-settings/{aiProvider}', [\App\Http\Controllers\AdminController::class, 'updateAiProvider'])->name('ai.update');
    Route::put('/ai-settings/{aiProvider}/toggle', [\App\Http\Controllers\AdminController::class, 'toggleAiProvider'])->name('ai.toggle');
    Route::delete('/ai-settings/{aiProvider}', [\App\Http\Controllers\AdminController::class, 'destroyAiProvider'])->name('ai.destroy');

    // Pitch Deck
    Route::get('/pitch-deck', [\App\Http\Controllers\AdminController::class, 'pitchDeck'])->name('pitch-deck');
    Route::get('/pitch-deck.md', [\App\Http\Controllers\AdminController::class, 'pitchDeckMarkdown'])->name('pitch-deck.md');

    // ━━━ SWARM AI FEATURES INTEGRATION ━━━

    // AI Usage Dashboard
    Route::get('/ai-usage', [\App\Http\Controllers\Admin\AiUsageController::class, 'index'])->name('ai.usage');

    // AI Provider connection tester
    Route::post('/ai-settings/{aiProvider}/test', [\App\Http\Controllers\Admin\AiProviderTestController::class, 'test'])->name('ai.test');

    // Audit Logs
    Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('audit-logs.index');

    // Sentiment Dashboard
    Route::get('/sentiment/{movie?}', [\App\Http\Controllers\Admin\SentimentDashboardController::class, 'index'])->name('sentiment.index');

    // AI Movie Reviews (multi-perspective)
    Route::get('/movies/{movie}/ai-reviews', [\App\Http\Controllers\Admin\AiReviewController::class, 'index'])->name('movies.ai-reviews.index');
    Route::post('/movies/{movie}/ai-reviews/generate', [\App\Http\Controllers\Admin\AiReviewController::class, 'generate'])->name('movies.ai-reviews.generate');

    // Marketing AI (banner + social media)
    Route::get('/movies/{movie}/marketing-ai/banner', [\App\Http\Controllers\Admin\MarketingAiController::class, 'bannerForm'])->name('movies.marketing-ai.banner');
    Route::post('/movies/{movie}/marketing-ai/banner', [\App\Http\Controllers\Admin\MarketingAiController::class, 'generateBanner'])->name('movies.marketing-ai.banner.generate');
    Route::get('/movies/{movie}/marketing-ai/social', [\App\Http\Controllers\Admin\MarketingAiController::class, 'socialForm'])->name('movies.marketing-ai.social');
    Route::post('/movies/{movie}/marketing-ai/social', [\App\Http\Controllers\Admin\MarketingAiController::class, 'generateSocial'])->name('movies.marketing-ai.social.generate');

    // Comment Moderation Queue
    Route::get('/comments/queue', [\App\Http\Controllers\Admin\CommentModerationController::class, 'queue'])->name('comments.queue');
    Route::patch('/comments/{comment}/approve', [\App\Http\Controllers\Admin\CommentModerationController::class, 'approve'])->name('comments.approve');
    Route::patch('/comments/{comment}/reject', [\App\Http\Controllers\Admin\CommentModerationController::class, 'reject'])->name('comments.reject');
    Route::post('/comments/{comment}/rerun', [\App\Http\Controllers\Admin\CommentModerationController::class, 'rerun'])->name('comments.rerun');

    // ━━━ SWARM 25 ROUTES ━━━

    // Movie video upload + transcoding control
    Route::post('/movies/{movie}/upload-master', [\App\Http\Controllers\Admin\MovieUploadController::class, 'uploadMaster'])->name('movies.upload-master');
    Route::post('/movies/{movie}/start-transcode', [\App\Http\Controllers\Admin\MovieUploadController::class, 'startTranscode'])->name('movies.start-transcode');
    Route::get('/movies/{movie}/encoding-status', [\App\Http\Controllers\Admin\MovieUploadController::class, 'encodingStatus'])->name('movies.encoding-status');

    // Subtitle variants (dialect / kid-safe / speaker tags)
    Route::post('/movies/{movie}/subtitles/dialect', [\App\Http\Controllers\Admin\SubtitleController::class, 'translateDialect'])->name('movies.subtitles.dialect');
    Route::post('/movies/{movie}/subtitles/kid-safe', [\App\Http\Controllers\Admin\SubtitleController::class, 'kidSafeFilter'])->name('movies.subtitles.kid-safe');
    Route::post('/movies/{movie}/subtitles/speaker-tags', [\App\Http\Controllers\Admin\SubtitleController::class, 'addSpeakerTags'])->name('movies.subtitles.speaker-tags');

    // Director Auteur Analysis
    Route::get('/director-analyses', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'index'])->name('director-analyses.index');
    Route::post('/director-analyses', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'analyze'])->name('director-analyses.analyze');
    Route::get('/director-analyses/{directorSlug}', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'show'])->name('director-analyses.show');
    Route::post('/director-analyses/{directorSlug}/refresh', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'refresh'])->name('director-analyses.refresh');
    Route::delete('/director-analyses/{directorSlug}', [\App\Http\Controllers\Admin\DirectorAnalysisController::class, 'destroy'])->name('director-analyses.destroy');

    // Churn Risk Dashboard
    Route::get('/churn', [\App\Http\Controllers\Admin\ChurnDashboardController::class, 'index'])->name('churn.dashboard');

    // AI Insights (content gap + pricing)
    Route::get('/insights/content-gap', [\App\Http\Controllers\Admin\AiInsightsController::class, 'contentGap'])->name('insights.content-gap');
    Route::get('/insights/pricing', [\App\Http\Controllers\Admin\AiInsightsController::class, 'pricing'])->name('insights.pricing');

    // Revenue + Geo + Cohort + Funnel + A/B (D1/D14/D2/D3/D6)
    Route::get('/revenue', [\App\Http\Controllers\Admin\RevenueDashboardController::class, 'index'])->name('revenue.dashboard');
    Route::get('/geo', [\App\Http\Controllers\Admin\GeoDistributionController::class, 'index'])->name('geo.distribution');
    Route::get('/cohorts', [\App\Http\Controllers\Admin\CohortDashboardController::class, 'index'])->name('cohorts.index');
    Route::get('/cohorts/export.csv', [\App\Http\Controllers\Admin\CohortDashboardController::class, 'export'])->name('cohorts.export');
    Route::get('/funnel', [\App\Http\Controllers\Admin\FunnelDashboardController::class, 'index'])->name('funnel.index');
    Route::get('/ab-tests', [\App\Http\Controllers\Admin\AbTestController::class, 'index'])->name('ab-tests.index');
    Route::get('/ab-tests/create', [\App\Http\Controllers\Admin\AbTestController::class, 'create'])->name('ab-tests.create');
    Route::post('/ab-tests', [\App\Http\Controllers\Admin\AbTestController::class, 'store'])->name('ab-tests.store');
    Route::get('/ab-tests/{experiment}', [\App\Http\Controllers\Admin\AbTestController::class, 'show'])->name('ab-tests.show');
    Route::post('/ab-tests/{experiment}/{action}', [\App\Http\Controllers\Admin\AbTestController::class, 'act'])->name('ab-tests.act');

    // Performance Dashboard (P1)
    Route::get('/performance', [\App\Http\Controllers\Admin\PerformanceDashboardController::class, 'index'])->name('performance.index');

    // Marketing Ops (TikTok clips, title alternatives, email A/B, CS reply)
    Route::get('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'tikTokClipsForm'])->name('movies.marketing-ops.tiktok-clips');
    Route::post('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTikTokClips'])->name('movies.marketing-ops.tiktok-clips.generate');
    Route::get('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'titleAlternativesForm'])->name('movies.marketing-ops.title-alternatives');
    Route::post('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTitleAlternatives'])->name('movies.marketing-ops.title-alternatives.generate');
    Route::get('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'emailSubjectsForm'])->name('marketing-ops.email-subjects');
    Route::post('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateEmailSubjects'])->name('marketing-ops.email-subjects.generate');
    Route::get('/marketing-ops/cs-reply', fn () => view('admin.marketing-ops.cs-reply-drafter'))->name('marketing-ops.cs-reply');
    Route::post('/marketing-ops/cs-reply', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'csReplyDraft'])->name('marketing-ops.cs-reply.generate');

    // Performance Dashboard (P1) — AI latency, queue lag, cache + DB stats, slow queries
    Route::get('/performance', [\App\Http\Controllers\Admin\PerformanceDashboardController::class, 'index'])->name('performance.index');

    // Revenue Dashboard (D1) — MRR/ARR, churn, per-plan donut, 30-day trend
    Route::get('/revenue', [\App\Http\Controllers\Admin\RevenueDashboardController::class, 'index'])->name('revenue.dashboard');

    // Geo Distribution Dashboard (D14) — users / watches / revenue per country
    Route::get('/geo', [\App\Http\Controllers\Admin\GeoDistributionController::class, 'index'])->name('geo.distribution');
});

// ━━━ User-facing AI Features ━━━

Route::middleware('auth')->group(function () {
    // Onboarding (cold-start)
    Route::get('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'quiz'])->name('onboarding.quiz');
    Route::post('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'submit'])->name('onboarding.submit');

    // Mood Discovery
    Route::get('/discover/mood', [\App\Http\Controllers\MoodDiscoveryController::class, 'form'])->name('discovery.mood.form');
    Route::post('/discover/mood', [\App\Http\Controllers\MoodDiscoveryController::class, 'discover'])->name('discovery.mood.discover');

    // Personalized Recommendations
    Route::get('/api/recommendations', [\App\Http\Controllers\RecommendationController::class, 'forUser'])->name('recommendations.me');
    Route::get('/api/recommendations/time', [\App\Http\Controllers\RecommendationController::class, 'byTimeOfDay'])->name('recommendations.time');

    // Movie Comparison
    Route::get('/compare', [\App\Http\Controllers\MovieComparisonController::class, 'form'])->name('compare.form');
    Route::post('/compare', [\App\Http\Controllers\MovieComparisonController::class, 'compare'])->name('compare.run');
    Route::post('/api/compare', [\App\Http\Controllers\MovieComparisonController::class, 'compareApi'])->name('compare.api');

    // Year In Review
    Route::get('/year-in-review', [\App\Http\Controllers\YearInReviewController::class, 'show'])->name('year-in-review.show');
    Route::get('/year-in-review/{year}', [\App\Http\Controllers\YearInReviewController::class, 'show'])
        ->whereNumber('year')->name('year-in-review.year');
    Route::post('/year-in-review/{id}/share', [\App\Http\Controllers\YearInReviewController::class, 'share'])
        ->whereNumber('id')->name('year-in-review.share');

    // Smart Watchlist + Family Movie Night
    Route::get('/watchlist/smart', [\App\Http\Controllers\SmartWatchlistController::class, 'prioritized'])->name('watchlist.smart');
    Route::get('/family-night', [\App\Http\Controllers\FamilyNightController::class, 'form'])->name('family-night.form');
    Route::post('/family-night', [\App\Http\Controllers\FamilyNightController::class, 'recommend'])->name('family-night.recommend');

    // Highlight Reels
    Route::get('/movie/{movie}/highlight', [\App\Http\Controllers\HighlightReelController::class, 'show'])->name('highlight.show');
    Route::get('/movie/{movie}/highlight/download', [\App\Http\Controllers\HighlightReelController::class, 'download'])->name('highlight.download');

    // Universal Smart Search (intent classification → routed to specialised services)
    Route::get('/search', [\App\Http\Controllers\SmartSearchController::class, 'search'])->name('search.smart');
    Route::get('/api/search/autocomplete', [\App\Http\Controllers\SmartSearchController::class, 'autocomplete'])->name('search.autocomplete');

    // Advanced Search (image / vibe / person)
    Route::get('/search/image', [\App\Http\Controllers\AdvancedSearchController::class, 'imageForm'])->name('search.image.form');
    Route::post('/search/image', [\App\Http\Controllers\AdvancedSearchController::class, 'imageSearch'])->name('search.image');
    Route::get('/search/vibe', [\App\Http\Controllers\AdvancedSearchController::class, 'vibeForm'])->name('search.vibe.form');
    Route::post('/search/vibe', [\App\Http\Controllers\AdvancedSearchController::class, 'vibeSearch'])->name('search.vibe');
    Route::get('/search/person', [\App\Http\Controllers\AdvancedSearchController::class, 'personForm'])->name('search.person.form');
    Route::post('/search/person', [\App\Http\Controllers\AdvancedSearchController::class, 'personSearch'])->name('search.person');

    // ── Encrypted playback (DRM-protected) ──
    Route::get('/playback/{movie}/config', [\App\Http\Controllers\PlaybackController::class, 'config'])->name('playback.config');
    Route::get('/playback/{movie}/manifest.m3u8', [\App\Http\Controllers\PlaybackController::class, 'manifest'])->name('playback.manifest');
    Route::post('/playback/{movie}/heartbeat', [\App\Http\Controllers\PlaybackController::class, 'heartbeat'])->name('playback.heartbeat');
});
