<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    /**
     * @psalm-suppress UndefinedClass
     * @psalm-suppress MissingDependency
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        // ForceHttps must run AFTER TrustProxies so $request->isSecure()
        // sees the real scheme behind a load balancer; otherwise it would
        // 301-loop. Skipped in local + testing envs (see middleware class).
        \App\Http\Middleware\ForceHttps::class,
        // WAF-lite: signature-based request inspector (path traversal,
        // SQLi, XSS, RCE, LFI/RFI, webshells). Runs early so malicious
        // payloads never reach a controller, but AFTER TrustProxies so
        // $request->ip() is the real client (used for ban-list lookups
        // + audit). Tunable / disablable via config('security.waf') —
        // see docs/security/waf-lite.md.
        \App\Http\Middleware\RequestFirewall::class,
        \App\Http\Middleware\SecurityHeaders::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,

        // Records HTTP 429 responses to audit_logs. Sits at the end of the
        // global stack so it sees responses from every throttle middleware
        // further down the pipeline. Disable with RATE_LIMIT_AUDIT_ENABLED=0
        // (see config/security.php → rate_limit_audit) for noisy environments.
        \App\Http\Middleware\RecordRateLimitHits::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // App-level maintenance switch (separate from Laravel's native
            // `php artisan down` file marker — that one still runs in the
            // global stack via PreventRequestsDuringMaintenance). Placed
            // AFTER SubstituteBindings + StartSession so $request->user()
            // is hydrated for the role-based bypass logic. Short-circuits
            // /admin/maintenance + /login internally so a stranded admin
            // can always disable the switch.
            \App\Http\Middleware\CheckCustomMaintenance::class,
            // i18n — resolves ?lang= / session / user.preferred_locale /
            // Accept-Language → app()->setLocale(). MUST run AFTER
            // StartSession (session lookup) AND SubstituteBindings (so
            // $request->user() is hydrated for the user-preference branch).
            \App\Http\Middleware\SetLocale::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'geoblock' => \App\Http\Middleware\GeoBlock::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        // Anti-bot honeypot: rejects POSTs that fill the hidden trap field
        // or submit faster than a human can. Apply to public POST routes
        // (login, register, password reset, newsletter). Authenticated
        // users are skipped inside the middleware. Pair with `<x-honeypot />`
        // on the form so the trap field + form-start timestamp are emitted.
        'honeypot' => \App\Http\Middleware\Honeypot::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        '2fa' => \App\Http\Middleware\TwoFactorVerified::class,
        // Defence-in-depth IDOR guard. Usage: ->middleware('owns:schedule')
        // — the param name MUST match the route binding ({schedule}).
        'owns' => \App\Http\Middleware\EnsureOwnership::class,
        // Service-to-service API key auth. Reads Authorization: Bearer flk_...
        // or X-Api-Key. On success, the verified ApiKey is exposed via
        // $request->attributes->get('api_key').
        'auth.apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
    ];
}
