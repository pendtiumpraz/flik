<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/movies';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * Every limiter (other than the default 'api' shipped by Laravel) is
     * data-driven via config('security.rate_limits.*'), so production can
     * dial limits up or down per-env without touching code.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // Default Laravel API limiter — preserved for routes/api.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // ── Auth surface ───────────────────────────────────────────────
        // Coarse outer guard for POST /login. Sits in front of the
        // SessionsController + LoginThrottle (which handles the precise
        // per-account & per-IP lockout logic). Tunable via env:
        //   RATE_LIMIT_LOGIN_PER_MINUTE (default 5; falls back to legacy
        //   LOGIN_RATE_LIMIT_PER_MINUTE for backwards compatibility).
        //
        // Keyed by IP because requests are unauthenticated. 429 returns
        // automatically; controller-level lockouts use a friendlier
        // Indonesian message via ValidationException + the @error('throttle')
        // view block.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute($this->maxFor('login'))->by($request->ip());
        });

        // POST /register — anchored on IP because the user is anonymous.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute($this->maxFor('register'))->by($request->ip());
        });

        // POST /password/email + /password/reset — hourly window per IP to
        // prevent enumeration via the "did this email exist?" timing oracle.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perHour($this->maxFor('password-reset'))->by($request->ip());
        });

        // POST /email/verification-notification — anchored on the user when
        // authenticated (the typical case), IP fallback for safety. Email
        // delivery throttling is layered on top inside the notification.
        RateLimiter::for('verification-resend', function (Request $request) {
            return Limit::perMinute($this->maxFor('verification-resend'))
                ->by(optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        // ── User-generated content ─────────────────────────────────────
        // POST /comment — per user when logged in (so a single user behind a
        // shared NAT can't flood), IP fallback for guests/edge cases.
        RateLimiter::for('comments', function (Request $request) {
            return Limit::perMinute($this->maxFor('comments'))
                ->by(optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        // ── AI surface ─────────────────────────────────────────────────
        // POST /chat — interactive AI chatbot. Auth-required route, but we
        // still IP-fallback defensively so this limiter never NPEs if the
        // middleware order ever changes.
        RateLimiter::for('ai-chat', function (Request $request) {
            return Limit::perMinute($this->maxFor('ai-chat'))
                ->by(optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        // Heavy AI tasks (plot explain, mood discover, comparisons, etc.).
        // Hourly cap, anchored on the user — these calls cost real money
        // through the active provider.
        RateLimiter::for('ai-batch', function (Request $request) {
            return Limit::perHour($this->maxFor('ai-batch'))
                ->by(optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        // ── Search / discovery ─────────────────────────────────────────
        // /search, /api/search/autocomplete, /api/recommendations*. Generous
        // cap because autocomplete fires on every keystroke; per-user when
        // known so a popular guest IP doesn't poison everyone behind it.
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute($this->maxFor('search'))
                ->by(optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        // ── Public abuse targets ───────────────────────────────────────
        // POST /newsletter — anonymous endpoint, per IP, very tight.
        RateLimiter::for('newsletter', function (Request $request) {
            return Limit::perMinute($this->maxFor('newsletter'))->by($request->ip());
        });

        // POST /payment/webhook — Midtrans retry behaviour can be bursty
        // (status updates fan out per state change) so the cap is generous;
        // anchored on the gateway IP.
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute($this->maxFor('webhook'))->by($request->ip());
        });
    }

    /**
     * Resolve the configured 'max' hits for a named limiter, with a sane
     * fallback so a missing/invalid config key never disables the limiter.
     */
    private function maxFor(string $name): int
    {
        /** @var array<string, mixed>|null $cfg */
        $cfg = config("security.rate_limits.$name");

        if (is_array($cfg) && isset($cfg['max'])) {
            return max(1, (int) $cfg['max']);
        }

        return 60; // last-resort default; matches Laravel's 'api' limiter.
    }
}
