<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // TMDB (The Movie Database) — read-only API used by the admin import wizard
    // (App\Services\Tmdb\TmdbClient + MovieImporter). Either `api_key` (v3 key,
    // passed as ?api_key=) or `bearer` (v4 read token, passed as Authorization:
    // Bearer ...) is sufficient — the client prefers the bearer when both are
    // set because v4 has higher rate limits. `token` is kept as a legacy alias
    // for any existing callers that read it directly.
    'tmdb' => [
        'api_key' => env('TMDB_KEY'),
        'bearer' => env('TMDB_BEARER'),
        'token' => env('TMDB_TOKEN'),
    ],

    'mailchimp' => [
        'key' => env('MAILCHIMP_KEY'),
        'lists' => [
            'subscribers' => env('MAILCHIMP_LIST_SUBSCRIBERS'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
        'is_3ds' => env('MIDTRANS_IS_3DS', true),
    ],

    'web_search' => [
        // Wikipedia + DuckDuckGo, no API key needed.
        'enabled' => env('WEB_SEARCH_ENABLED', true),
    ],

    'bunny' => [
        'storage_zone' => env('BUNNY_STORAGE_ZONE'),
        'storage_key' => env('BUNNY_STORAGE_KEY'),
        'storage_hostname' => env('BUNNY_STORAGE_HOSTNAME', 'storage.bunnycdn.com'),
        'pull_zone_url' => env('BUNNY_PULL_ZONE_URL'),
        'pull_zone_token_key' => env('BUNNY_TOKEN_KEY'),
        'stream_library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        'stream_api_key' => env('BUNNY_STREAM_API_KEY'),
    ],

    'security_alerts' => [
        'enabled' => env('SECURITY_ALERTS_ENABLED', false),
        'slack_webhook' => env('SECURITY_ALERTS_SLACK_WEBHOOK'),
        'discord_webhook' => env('SECURITY_ALERTS_DISCORD_WEBHOOK'),
        'min_severity' => env('SECURITY_ALERTS_MIN_SEVERITY', 'high'),
    ],

    // Cloudflare Turnstile CAPTCHA. Both keys must be set for the integration
    // to engage; absent keys make TurnstileVerifier::enabled() return false
    // and the CaptchaPassed rule plus <x-captcha-turnstile> component become
    // graceful no-ops (FLiK's standard env-gating pattern).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Web Push (VAPID — RFC 8292). Both keys must be set for push delivery to
    // engage; absent keys make WebPushSender::enabled() return false, the
    // subscribe controller returns 503 with a helpful message, and the
    // <x-push-opt-in /> banner hides itself (FLiK's standard env-gating
    // pattern). Generate a fresh keypair with:
    //   php artisan flik:push:generate-vapid-keys
    //
    // The `subject` is delivered to push services in the JWT `sub` claim so
    // they can contact an operator about abuse — keep it pointed at a
    // real, monitored inbox.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@flik.example.com'),
    ],
];
