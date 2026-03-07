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
});

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
});
