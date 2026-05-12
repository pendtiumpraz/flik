<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\VelflixController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');
Route::post('newsletter', NewsletterController::class);

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

// ━━━ X-Ray Actor Overlay (api-style, web auth via session) ━━━
Route::middleware('auth')->get('/api/xray/{movie}', [\App\Http\Controllers\XrayController::class, 'forMovie'])
    ->name('xray.forMovie');

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

    // Marketing Ops (TikTok clips, title alternatives, email A/B, CS reply)
    Route::get('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'tikTokClipsForm'])->name('movies.marketing-ops.tiktok-clips');
    Route::post('/movies/{movie}/marketing-ops/tiktok-clips', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTikTokClips'])->name('movies.marketing-ops.tiktok-clips.generate');
    Route::get('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'titleAlternativesForm'])->name('movies.marketing-ops.title-alternatives');
    Route::post('/movies/{movie}/marketing-ops/title-alternatives', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateTitleAlternatives'])->name('movies.marketing-ops.title-alternatives.generate');
    Route::get('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'emailSubjectsForm'])->name('marketing-ops.email-subjects');
    Route::post('/marketing-ops/email-subjects', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'generateEmailSubjects'])->name('marketing-ops.email-subjects.generate');
    Route::get('/marketing-ops/cs-reply', fn () => view('admin.marketing-ops.cs-reply-drafter'))->name('marketing-ops.cs-reply');
    Route::post('/marketing-ops/cs-reply', [\App\Http\Controllers\Admin\MarketingOpsController::class, 'csReplyDraft'])->name('marketing-ops.cs-reply.generate');
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
