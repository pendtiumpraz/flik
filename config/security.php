<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security configuration
|--------------------------------------------------------------------------
|
| Knobs for the FLiK password policy + Have-I-Been-Pwned breach check.
| The defaults match what we ship in production; everything is overridable
| via environment variables so staging/local can dial them down.
|
| Used by:
|   - App\Rules\StrongPassword
|   - App\Rules\NotBreached
|   - App\Services\Security\PasswordService
|   - App\Services\Security\LoginThrottle
|   - App\Http\Controllers\SessionsController
|   - App\Providers\RouteServiceProvider (named 'login' rate limiter)
|   - App\Http\Middleware\SecurityHeaders (response header injection + CSP)
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | PII Pepper
    |--------------------------------------------------------------------------
    |
    | Application-wide pepper appended to PII (national_id) before hashing
    | with SHA-256. Consumed by App\Models\User::hashNationalId(). Sourced
    | here via config() rather than env() directly so the value survives
    | `php artisan config:cache` — env() returns null in cached-config
    | mode, which would silently degrade the pepper to APP_KEY and make
    | rotation impossible without a redeploy (audit 16 PRIVACY-6).
    |
    | Rotation procedure: change PII_PEPPER, clear config cache, then run
    | `php artisan flik:security:reencrypt-pii --only=national_id` to
    | re-hash all rows with the new pepper.
    |
    */

    'pii_pepper' => env('PII_PEPPER', null),

    'password' => [
        // Minimum total characters. Increase, never decrease, in production.
        'min_length' => env('PASSWORD_MIN_LENGTH', 10),

        // Whether at least one symbol (non-alnum) is required.
        // Letters/digits are always required regardless of this flag.
        'require_symbol' => env('PASSWORD_REQUIRE_SYMBOL', true),

        // Toggle the Have-I-Been-Pwned k-anonymity API call. Disable for
        // air-gapped environments or in test runs where outbound HTTP is
        // forbidden — the rule fails-open on network error anyway, but
        // disabling avoids the round-trip entirely.
        'check_breach' => env('PASSWORD_CHECK_BREACH', true),
    ],

    'throttle' => [
        // Rolling window (minutes) used for both account + IP failure counts.
        // Failures older than this don't contribute to lockout decisions.
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),

        // Per-account: failures within the window required to trigger lockout.
        // Tune down on staging if you want to test the message faster.
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),

        // Per-IP: failures within the window required to trigger an IP lock.
        // Higher than per-account because legitimate shared NATs (offices,
        // Indonesian ISPs with CGNAT) can produce several distinct accounts.
        'ip_max_attempts' => (int) env('LOGIN_IP_MAX_ATTEMPTS', 20),

        // Progressive delay ladder (seconds) keyed by recent-fail count.
        // Index = number of failures already on file for this email in the
        // window. Cap is the last value. Setting all values to 0 disables
        // the sleep without disabling lockouts.
        'progressive_delay' => [0, 1, 2, 4, 8, 16],

        // Named RateLimiter ('login') — POST /login per IP, sits in front of
        // the controller. This is the *coarse* outer guard; the per-account
        // logic in LoginThrottle is the precise inner one.
        'rate_limiter' => [
            'max_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Named rate limiters
    |--------------------------------------------------------------------------
    |
    | Tunable per-env knobs for every named RateLimiter registered in
    | App\Providers\RouteServiceProvider::configureRateLimiting(). Each entry
    | declares the maximum number of allowed hits within a fixed window
    | (decay) expressed in either 'minute' or 'hour'.
    |
    | Bumping a value here is enough to relax/tighten the limit — no code
    | change needed. The limiter name is also the throttle middleware
    | argument: ->middleware('throttle:comments') reads from the 'comments'
    | entry below at request time.
    |
    | The 'login' entry is intentionally aliased to throttle.rate_limiter
    | above so the existing security ladder stays the single source of truth.
    |
    */

    'rate_limits' => [
        // POST /login — coarse outer guard (per IP). Inner per-account ladder
        // lives in App\Services\Security\LoginThrottle.
        'login' => [
            'max' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', env('LOGIN_RATE_LIMIT_PER_MINUTE', 5)),
            'decay' => 'minute',
        ],

        // POST /register — slow because each success creates a real user row.
        'register' => [
            'max' => (int) env('RATE_LIMIT_REGISTER_PER_MINUTE', 3),
            'decay' => 'minute',
        ],

        // POST password reset request / confirm — hourly to prevent enumeration.
        'password-reset' => [
            'max' => (int) env('RATE_LIMIT_PASSWORD_RESET_PER_HOUR', 3),
            'decay' => 'hour',
        ],

        // POST /comment — per-user when authenticated, per-IP otherwise.
        'comments' => [
            'max' => (int) env('RATE_LIMIT_COMMENTS_PER_MINUTE', 10),
            'decay' => 'minute',
        ],

        // POST /chat — interactive AI chatbot; auth required.
        'ai-chat' => [
            'max' => (int) env('RATE_LIMIT_AI_CHAT_PER_MINUTE', 20),
            'decay' => 'minute',
        ],

        // Heavy AI tasks (plot explain, comparison API, mood discover, etc.).
        'ai-batch' => [
            'max' => (int) env('RATE_LIMIT_AI_BATCH_PER_HOUR', 50),
            'decay' => 'hour',
        ],

        // Search + autocomplete + recommendations APIs.
        'search' => [
            'max' => (int) env('RATE_LIMIT_SEARCH_PER_MINUTE', 60),
            'decay' => 'minute',
        ],

        // Newsletter signup — public endpoint, abuse target.
        'newsletter' => [
            'max' => (int) env('RATE_LIMIT_NEWSLETTER_PER_MINUTE', 2),
            'decay' => 'minute',
        ],

        // Resend email-verification link.
        'verification-resend' => [
            'max' => (int) env('RATE_LIMIT_VERIFICATION_RESEND_PER_MINUTE', 6),
            'decay' => 'minute',
        ],

        // Inbound webhooks (Midtrans payment notifications). Generous because
        // the gateway can legitimately retry; per-IP anchored on the gateway.
        'webhook' => [
            'max' => (int) env('RATE_LIMIT_WEBHOOK_PER_MINUTE', 100),
            'decay' => 'minute',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate-limit hit auditing
    |--------------------------------------------------------------------------
    |
    | When enabled, App\Http\Middleware\RecordRateLimitHits writes an
    | 'rate_limit.hit' row to audit_logs every time a request is rejected
    | with HTTP 429. Disable in tests or environments with very chatty
    | crawlers to avoid bloating audit_logs.
    |
    */

    'rate_limit_audit' => [
        'enabled' => (bool) env('RATE_LIMIT_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Honeypot anti-bot
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Http\Middleware\Honeypot + the `<x-honeypot />` Blade
    | component. Two cheap signals catch naive form-flooders without
    | challenging real users:
    |
    |   - field         Name of the hidden trap input. A real user can't see
    |                   or focus it (visually-hidden CSS, tabindex=-1,
    |                   aria-hidden, autocomplete=off). Any non-empty
    |                   submission is treated as a bot.
    |   - min_seconds   Floor on the time between rendering the form and
    |                   POSTing it. A POST that arrives faster than this
    |                   is treated as a bot. 2 s is well below realistic
    |                   human latency on the shortest form (login).
    |
    | Set HONEYPOT_ENABLED=false to disable globally — both the middleware
    | and the Blade component short-circuit on the same flag so no
    | mid-deploy mismatch can lock real users out.
    |
    | Authenticated users are always skipped inside the middleware (the
    | bulk of bot traffic targets pre-auth endpoints, and we never want
    | to add false-positive friction for a logged-in customer).
    |
    */

    'honeypot' => [
        'enabled' => (bool) env('HONEYPOT_ENABLED', true),
        'field' => (string) env('HONEYPOT_FIELD', 'website_url'),
        'min_seconds' => (int) env('HONEYPOT_MIN_SECONDS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP response security headers
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Http\Middleware\SecurityHeaders. The CSP directives
    | array is rendered into a single header value. Add new external hosts
    | to the matching directive when you introduce a new third-party
    | resource (CDN script, embed, font provider, etc.).
    |
    */

    'headers' => [
        'csp' => [
            'enabled' => env('CSP_ENABLED', true),
            'report_only' => env('CSP_REPORT_ONLY', false),
            'directives' => [
                'default-src' => ["'self'"],
                'script-src' => [
                    "'self'",
                    "'unsafe-inline'",
                    // REQUIRED by Alpine.js — it uses new Function() to evaluate
                    // x-data / x-show / x-on expressions. Without 'unsafe-eval'
                    // EVERY x-data crashes with "Alpine Expression Error: …
                    // violates CSP" and every dropdown/x-show defaults to
                    // visible. The alternative is @alpinejs/csp build which
                    // requires ahead-of-time precompiled expressions across
                    // ALL Blade views — out of scope for a hotfix.
                    "'unsafe-eval'",
                    'cdn.jsdelivr.net',
                    'cdn.tailwindcss.com',
                    'cdnjs.cloudflare.com',
                    // App-specific: Alpine, Flickity, Video.js loaded inline in views.
                    'unpkg.com',
                    'vjs.zencdn.net',
                ],
                'style-src' => [
                    "'self'",
                    "'unsafe-inline'",
                    'fonts.googleapis.com',
                    'cdn.jsdelivr.net',
                    // App-specific: Flickity CSS, Video.js skin.
                    'unpkg.com',
                    'vjs.zencdn.net',
                ],
                'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
                'media-src' => ["'self'", 'blob:', 'https:'],
                'font-src' => ["'self'", 'fonts.gstatic.com', 'data:'],
                'connect-src' => ["'self'", 'https:', 'wss:'],
                'frame-src' => [
                    "'self'",
                    'youtube.com',
                    'www.youtube.com',
                    'app.midtrans.com',
                    'app.sandbox.midtrans.com',
                ],
                'object-src' => ["'none'"],
                'base-uri' => ["'self'"],
                'form-action' => [
                    "'self'",
                    'app.midtrans.com',
                    'app.sandbox.midtrans.com',
                ],
                'frame-ancestors' => ["'self'"],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WAF-lite request firewall
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Http\Middleware\RequestFirewall. Signature-based
    | inspector that runs early in the global stack and blocks obvious
    | malicious payloads (path traversal, SQLi, XSS, RCE, LFI/RFI,
    | webshells). See docs/security/waf-lite.md for the full rule
    | catalogue and tuning guide.
    |
    | Modes:
    |   - 'block'     — log + return 403 (production default)
    |   - 'log_only'  — log only, request proceeds (use in dev/staging
    |                   while tuning rules so you don't lock yourself out)
    |
    | route_allowlist values are matched (in order) against the route name
    | first, then the request path. Glob wildcards (`*`) are supported via
    | Illuminate\Support\Str::is(). Listed routes skip BODY + cookie
    | inspection only — path/query/header inspection always runs.
    |
    */

    'waf' => [
        'enabled' => (bool) env('WAF_ENABLED', true),
        'mode' => env('WAF_MODE', 'block'),
        'bypass_token' => env('WAF_BYPASS_TOKEN'),
        'route_allowlist' => [
            // Comments may legitimately contain `<script>` or SQL-shaped
            // text — HtmlSanitizer + Comment moderation handle them.
            'comment.*',
            // AI chatbot input may legitimately ask about SQL injection,
            // include code examples, or paste error logs.
            'chat.*',
            // Path-style fallbacks for the same routes when used
            // unauthenticated (e.g. before route name resolution).
            'comment',
            'comment/*',
            'chat',

            // ── Admin surfaces that legitimately accept code-like /
            // markdown / JSON content. Audit 15 W-1 fix: the previous
            // wildcard 'admin.*' / 'admin/*' exempted EVERY admin POST
            // (including /admin/users, /admin/movies CRUD, /admin/banners
            // etc.) which silently disabled the second perimeter for an
            // authenticated admin with malicious intent. The list below
            // is the smaller set of admin routes where blocking SQL/PHP
            // markers would false-positive on legitimate content.

            // Pitch-deck Markdown body — explicitly intended to host code
            // samples and quoted PHP/SQL in narrative form.
            'admin.pitch-deck',
            'admin.pitch-deck.*',

            // Blog post body (Markdown/HTML, may include code blocks).
            'admin.blog-posts.*',
            'admin.blog.posts.*',
            'admin.blog.ai.*',

            // Help center article body (HTML/Markdown, may include code).
            'admin.help-articles.*',
            'admin.help.articles.*',
            'admin.help.ai.*',

            // Feature flag strategy_config is a JSON blob (rule trees can
            // look SQL-shaped under string match).
            'admin.feature-flags.*',

            // Site settings store JSON values that may contain operator-
            // pasted snippets (e.g. analytics tags, CSP overrides).
            'admin.settings.*',

            // AI provider config — operators paste JSON `extra` blobs
            // (model params) that may contain quoted strings the WAF
            // would otherwise treat as SQL/script signatures.
            'admin.ai.*',
            'admin.ai-providers.*',
        ],
        'ip_ban_threshold' => (int) env('WAF_IP_BAN_THRESHOLD', 5),
        'ip_ban_minutes' => (int) env('WAF_IP_BAN_MINUTES', 60),
    ],

];
