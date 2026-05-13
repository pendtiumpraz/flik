<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Tightened defaults — DO NOT loosen without security review.
|
| - `paths`         : only API + sanctum endpoints. Web routes are same-origin
|                     and intentionally excluded so a misconfigured CSRF token
|                     can't be paired with permissive CORS.
| - `allowed_methods`: explicit verb list, no wildcards. PATCH included for
|                     partial updates (admin moderation flows).
| - `allowed_origins`: derived from CORS_ALLOWED_ORIGINS (comma-separated).
|                     Defaults to APP_URL only — **same-origin** when unset.
|                     NEVER falls back to "*" because we set
|                     supports_credentials=true (browsers reject the combo).
| - `allowed_headers`: explicit allowlist. We include both X-CSRF-TOKEN and
|                     X-XSRF-TOKEN because Axios uses the latter while the
|                     Blade meta tag exposes the former.
| - `exposed_headers`: surface our rate-limit headers so SPAs/api clients can
|                     react to throttling without parsing 429 bodies.
| - `max_age`        : 1 hour preflight cache — long enough to amortise OPTIONS
|                     storms but short enough to roll out config changes.
| - `supports_credentials`: required for cookie-based session auth from
|                     allowed origins (Sanctum SPA flow + admin tooling).
|
| The `allowed_origins` parser strips trailing slashes and ignores blanks so
| operators can write `CORS_ALLOWED_ORIGINS="https://flik.id, https://admin.flik.id,"`
| without surprises.
*/

$origins = array_values(array_filter(array_map(
    static fn (string $o): string => rtrim(trim($o), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_URL', '')))
), static fn (string $o): bool => $o !== ''));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Accept',
        'Authorization',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 3600,

    'supports_credentials' => true,

];
