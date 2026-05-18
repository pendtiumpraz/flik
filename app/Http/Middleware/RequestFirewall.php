<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequestFirewall — WAF-lite request inspector.
 *
 * Cheap signature-based filter that runs early in the global middleware stack
 * (after TrustProxies so we see the real client IP, before route dispatch so
 * malicious payloads never reach a controller). Inspired by ModSecurity's
 * core rule set but intentionally narrow — we only block payloads that are
 * unambiguously malicious in the FLiK threat model. Anything ambiguous (a
 * comment that legitimately contains the word `<script>`, an admin pasting
 * markdown that includes PHP code, an AI chat asking about SQL injection)
 * is whitelisted by route name so the dedicated downstream sanitiser can
 * handle it (HtmlSanitizer for comments, the chat scope guard for /chat,
 * etc.).
 *
 * What we inspect:
 *   - URL path + query string (every request)
 *   - JSON body / form body (skipped on whitelisted routes — see
 *     {@see config('security.waf.route_allowlist')})
 *   - Cookies (excluding the framework session cookie which contains
 *     opaque encrypted data that may legitimately match patterns)
 *   - Custom request headers (X-* and headers users can influence) —
 *     limited to a CRLF-injection check
 *
 * What we DO NOT inspect:
 *   - File uploads (binary data — handled by App\Support\MagicBytes +
 *     ClamAV in the upload pipeline)
 *   - Authorization / cookie headers in their entirety (false-positive
 *     prone — JWTs and session blobs can match a hex-blob signature)
 *
 * Modes:
 *   - 'block'    — log and return HTTP 403 ("Request blocked")
 *   - 'log_only' — log the match and let the request through. Use this in
 *                  dev / staging while you're tuning rules.
 *
 * IP throttling:
 *   - Each block hit increments `waf:ip:hits:{ip}` (TTL 5 min). When the
 *     count crosses the threshold, `waf:ip:ban:{ip}` is set (TTL 60 min)
 *     and every subsequent request from that IP is dropped at the cheapest
 *     possible point.
 *
 * Bypass:
 *   - Header `X-Bypass-Waf: <secret>` matching env `WAF_BYPASS_TOKEN`
 *     short-circuits inspection. Used by the DAST scanner against staging
 *     so it can see the *application's* responses to malicious inputs
 *     instead of an unhelpful 403 wall.
 */
final class RequestFirewall
{
    /**
     * Pattern catalogue. Each entry is `[label, pattern, modifiers]`.
     *
     * Patterns are compiled as PCRE with the `u` (unicode) modifier and
     * delimiter `~` so we can use `/` freely inside without escaping.
     * The `i` modifier is added per-rule for case-insensitive checks.
     *
     * Order matters only for log clarity — we return on the FIRST match.
     *
     * @var list<array{label:string,pattern:string,scope:string}>
     *
     *      scope: 'all' | 'no_body' (skip body in this rule — used for
     *              webshell signatures that legitimately appear in admin
     *              text but never in URLs)
     */
    private const PATTERNS = [
        // ── Path traversal (absolute-must-block) ─────────────────────
        ['label' => 'path_traversal',          'pattern' => '~\.\.[\\\\/]~',                                       'scope' => 'all'],
        ['label' => 'path_traversal_encoded',  'pattern' => '~%2e%2e[%/\\\\]~i',                                  'scope' => 'all'],
        ['label' => 'path_traversal_windows',  'pattern' => '~\.\.\\\\.+\\\\windows~i',                            'scope' => 'all'],

        // ── SQLi probes ───────────────────────────────────────────────
        ['label' => 'sqli_or_1eq1',            'pattern' => '~[\'"]\s*OR\s+1\s*=\s*1~i',                          'scope' => 'all'],
        ['label' => 'sqli_union_select',       'pattern' => '~\bUNION\s+(?:ALL\s+)?SELECT\b~i',                   'scope' => 'all'],
        ['label' => 'sqli_information_schema', 'pattern' => '~\bSELECT\b[\s\S]{1,200}\bFROM\s+information_schema~i', 'scope' => 'all'],
        ['label' => 'sqli_drop_table',         'pattern' => '~;\s*DROP\s+TABLE\b~i',                              'scope' => 'all'],
        ['label' => 'sqli_hex_blob',           'pattern' => '~0x[0-9a-f]{20,}~i',                                 'scope' => 'all'],

        // ── XSS probes ────────────────────────────────────────────────
        ['label' => 'xss_script_tag',          'pattern' => '~<script\b~i',                                       'scope' => 'all'],
        ['label' => 'xss_javascript_uri',      'pattern' => '~javascript:~i',                                     'scope' => 'all'],
        ['label' => 'xss_event_onerror',       'pattern' => '~\bonerror\s*=~i',                                   'scope' => 'all'],
        ['label' => 'xss_event_onload',        'pattern' => '~\bonload\s*=~i',                                    'scope' => 'all'],
        ['label' => 'xss_event_onclick',       'pattern' => '~\bonclick\s*=~i',                                   'scope' => 'all'],
        ['label' => 'xss_iframe_tag',          'pattern' => '~<iframe\b~i',                                       'scope' => 'all'],
        ['label' => 'xss_svg_onload',          'pattern' => '~<svg[^>]*onload~i',                                 'scope' => 'all'],

        // ── Command injection ─────────────────────────────────────────
        ['label' => 'cmdi_etc_passwd',         'pattern' => '~;\s*cat\s+/etc/~i',                                 'scope' => 'all'],
        ['label' => 'cmdi_netcat',             'pattern' => '~\|\s*nc\s+~i',                                      'scope' => 'all'],
        ['label' => 'cmdi_curl_chained',       'pattern' => '~&&\s*curl\s+~i',                                    'scope' => 'all'],
        ['label' => 'cmdi_subshell',           'pattern' => '~\$\(~',                                             'scope' => 'all'],
        ['label' => 'cmdi_backtick',           'pattern' => '~`[^`]{1,200}`~',                                    'scope' => 'all'],

        // ── PHP code injection ────────────────────────────────────────
        ['label' => 'php_open_tag',            'pattern' => '~<\?php\b~i',                                        'scope' => 'all'],
        ['label' => 'php_short_echo',          'pattern' => '~<\?=~',                                             'scope' => 'all'],
        ['label' => 'php_eval',                'pattern' => '~\beval\s*\(~i',                                     'scope' => 'all'],
        ['label' => 'php_base64_decode',       'pattern' => '~\bbase64_decode\s*\(~i',                            'scope' => 'all'],

        // ── LFI / RFI ─────────────────────────────────────────────────
        ['label' => 'lfi_file_uri',            'pattern' => '~file:///~i',                                        'scope' => 'all'],
        ['label' => 'lfi_php_filter',          'pattern' => '~php://(?:filter|input)~i',                          'scope' => 'all'],
        ['label' => 'lfi_expect',              'pattern' => '~expect://~i',                                       'scope' => 'all'],

        // ── Webshell signatures ───────────────────────────────────────
        ['label' => 'webshell_c99',            'pattern' => '~\bc99shell\b~i',                                    'scope' => 'no_body'],
        ['label' => 'webshell_r57',            'pattern' => '~\br57(?:shell)?\b~i',                               'scope' => 'no_body'],
        ['label' => 'webshell_wso2',           'pattern' => '~\bwso2?(?:shell)?\b~i',                             'scope' => 'no_body'],
        ['label' => 'webshell_b374k',          'pattern' => '~\bb374k\b~i',                                       'scope' => 'no_body'],
    ];

    /**
     * Maximum number of bytes inspected per scalar value. Bigger payloads
     * (long markdown, base64 image data) get truncated for the regex pass —
     * the prefix is enough to catch all of our signatures and avoids
     * pathological backtracking on adversarial inputs.
     */
    private const MAX_VALUE_BYTES = 8192;

    /**
     * Cap on total scalars walked from request body / cookies. Stops a
     * crafted nested array from exhausting CPU just by being deeply nested.
     */
    private const MAX_VALUES_PER_REQUEST = 500;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEnabled()) {
            return $next($request);
        }

        if ($this->isBypassed($request)) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        // Already-banned IP: drop at the cheapest possible point. We don't
        // re-log here — the original block was already audited and the
        // continued drumming would just bloat audit_logs.
        if ($ip !== '' && Cache::has($this->banKey($ip))) {
            return $this->blockResponse();
        }

        $match = $this->inspect($request);

        if ($match === null) {
            return $next($request);
        }

        $this->record($request, $match);

        if ($this->mode() === 'log_only') {
            // Dev/staging: surface the match in logs but pass through.
            return $next($request);
        }

        if ($ip !== '') {
            $this->trackIpHit($ip);
        }

        return $this->blockResponse();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Inspection
    // ─────────────────────────────────────────────────────────────────

    /**
     * Walk the request surface and return the first matching rule, or null
     * when the request looks clean.
     *
     * @return array{label:string,location:string,sample:string}|null
     */
    private function inspect(Request $request): ?array
    {
        // 1. Path + query string — always inspected, never whitelisted.
        //    Allowlist applies to BODY only (admins can paste code into
        //    forms; nobody legitimately needs `../etc/passwd` in a URL).
        $pathHit = $this->scan('path', '/'.ltrim($request->path(), '/'), inspectBody: true);
        if ($pathHit !== null) {
            return $pathHit;
        }

        $queryString = $request->getQueryString();
        if ($queryString !== null && $queryString !== '') {
            $decodedQuery = rawurldecode($queryString);
            $queryHit = $this->scan('query', $queryString.' '.$decodedQuery, inspectBody: true);
            if ($queryHit !== null) {
                return $queryHit;
            }
        }

        // 2. Custom headers — CRLF injection only. Inspecting full header
        //    values is too false-positive prone (JWTs match the hex-blob
        //    signature; some browsers send long opaque cookies).
        $crlfHit = $this->inspectHeadersForCrlf($request);
        if ($crlfHit !== null) {
            return $crlfHit;
        }

        $inspectBody = ! $this->isRouteAllowlisted($request);

        // 3. Cookies (excluding session/xsrf — those are framework-managed
        //    encrypted blobs that legitimately match patterns).
        if ($inspectBody) {
            $cookieHit = $this->inspectCookies($request);
            if ($cookieHit !== null) {
                return $cookieHit;
            }
        }

        // 4. Body (form or JSON).
        if ($inspectBody && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $bodyHit = $this->inspectBody($request);
            if ($bodyHit !== null) {
                return $bodyHit;
            }
        }

        return null;
    }

    /**
     * Run every WAF pattern against a single string. Returns the first
     * match metadata or null.
     *
     * @return array{label:string,location:string,sample:string}|null
     */
    private function scan(string $location, string $value, bool $inspectBody): ?array
    {
        if ($value === '') {
            return null;
        }

        // Truncate for performance + ReDoS protection.
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            $value = substr($value, 0, self::MAX_VALUE_BYTES);
        }

        foreach (self::PATTERNS as $rule) {
            // The `no_body` scope flag is only relevant when we're
            // scanning a body field — for path/query/header it's still
            // active. `inspectBody=false` here means "this isn't a body
            // scalar", so `no_body` rules apply normally.
            if ($rule['scope'] === 'no_body' && $inspectBody === true && $location === 'body') {
                continue;
            }

            $result = @preg_match($rule['pattern'], $value, $matches);
            if ($result === 1) {
                return [
                    'label' => $rule['label'],
                    'location' => $location,
                    'sample' => mb_substr((string) ($matches[0] ?? ''), 0, 120),
                ];
            }
        }

        return null;
    }

    /**
     * Walk request body (JSON or form) and apply patterns to every scalar
     * value, with a hard cap on the number of scalars inspected.
     *
     * @return array{label:string,location:string,sample:string}|null
     */
    private function inspectBody(Request $request): ?array
    {
        $payload = $request->isJson()
            ? (array) $request->json()->all()
            : $request->all();

        if ($payload === []) {
            // Last resort: inspect raw input if the parsed form is empty
            // (e.g. content-type missing). Cheap if there's no body.
            $raw = (string) $request->getContent();
            if ($raw !== '') {
                return $this->scan('body_raw', $raw, inspectBody: false);
            }

            return null;
        }

        $count = 0;

        return $this->walkScalars($payload, 'body', $count);
    }

    /**
     * Recursively walk a payload and scan every scalar value. Stops early
     * when {@see MAX_VALUES_PER_REQUEST} is reached — at that point the
     * request is already abnormal enough that block-by-default would be
     * justified, but the documented contract is "first match wins" so we
     * just cut off the walk.
     *
     * @return array{label:string,location:string,sample:string}|null
     */
    private function walkScalars(mixed $value, string $location, int &$count): ?array
    {
        if ($count >= self::MAX_VALUES_PER_REQUEST) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $childLocation = $location.'['.(is_string($k) ? $k : (string) $k).']';
                $hit = $this->walkScalars($v, $childLocation, $count);
                if ($hit !== null) {
                    return $hit;
                }
            }

            return null;
        }

        if (is_object($value)) {
            // Stringify objects via __toString if available; otherwise skip.
            if (method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                return null;
            }
        }

        if (! is_scalar($value)) {
            return null;
        }

        $count++;
        $stringValue = (string) $value;

        if ($stringValue === '') {
            return null;
        }

        return $this->scan($location, $stringValue, inspectBody: false);
    }

    /**
     * @return array{label:string,location:string,sample:string}|null
     */
    private function inspectCookies(Request $request): ?array
    {
        // Skip framework-managed cookies — encrypted blobs legitimately
        // contain hex sequences and would constantly false-positive.
        $sessionCookieName = (string) config('session.cookie', '');
        $skip = array_filter([
            $sessionCookieName,
            'XSRF-TOKEN',
            'remember_web',
        ]);

        $count = 0;
        foreach ($request->cookies->all() as $name => $value) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            // Wildcard-style skip for laravel "remember_*" cookies that
            // include the auth-guard hash suffix.
            if (str_starts_with((string) $name, 'remember_')) {
                continue;
            }
            $hit = $this->walkScalars($value, 'cookie['.$name.']', $count);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Detect CRLF (\r or \n) injection inside any custom request header
     * (X-* family + the small set of headers that are user-controllable
     * and not framework-internal).
     *
     * @return array{label:string,location:string,sample:string}|null
     */
    private function inspectHeadersForCrlf(Request $request): ?array
    {
        foreach ($request->headers->all() as $name => $values) {
            // Only inspect headers a user can plausibly inject. Framework
            // / proxy headers (host, content-length, x-forwarded-*) are
            // either trusted or already validated upstream.
            $lower = strtolower((string) $name);
            if (! str_starts_with($lower, 'x-') || str_starts_with($lower, 'x-forwarded-')) {
                continue;
            }
            // Bypass + ratelimit headers we set ourselves shouldn't trip.
            if (in_array($lower, ['x-bypass-waf', 'x-csrf-token', 'x-requested-with'], true)) {
                continue;
            }

            foreach ((array) $values as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                if (preg_match('~[\r\n]~', $value) === 1) {
                    return [
                        'label' => 'header_crlf_injection',
                        'location' => 'header['.$lower.']',
                        'sample' => mb_substr($value, 0, 80),
                    ];
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Allowlist + bypass + IP ban
    // ─────────────────────────────────────────────────────────────────

    private function isRouteAllowlisted(Request $request): bool
    {
        $patterns = (array) config('security.waf.route_allowlist', []);
        if ($patterns === []) {
            return false;
        }

        // Match by route name first (preferred — it survives URL changes).
        // Route is null when WAF runs before route resolution (global stack) —
        // fall back to path-only matching in that case.
        $route = $request->route();
        $routeName = ($route !== null && method_exists($route, 'getName'))
            ? $route->getName()
            : null;

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if ($routeName !== null && Str::is($pattern, $routeName)) {
                return true;
            }
            // Path-style fallback so an entry like `admin/*` works even
            // before the route table has resolved a name.
            if (str_contains($pattern, '/') && Str::is($pattern, $request->path())) {
                return true;
            }
        }

        return false;
    }

    private function isBypassed(Request $request): bool
    {
        $expected = (string) config('security.waf.bypass_token', '');
        if ($expected === '') {
            return false;
        }
        $provided = (string) $request->headers->get('X-Bypass-Waf', '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function trackIpHit(string $ip): void
    {
        $threshold = max(1, (int) config('security.waf.ip_ban_threshold', 5));
        $banMinutes = max(1, (int) config('security.waf.ip_ban_minutes', 60));

        $hitsKey = $this->hitsKey($ip);

        try {
            // 5-minute rolling window for hits. Cache::increment requires
            // the key to exist; seed it on first hit with the window TTL.
            if (! Cache::has($hitsKey)) {
                Cache::put($hitsKey, 1, now()->addMinutes(5));
                $hits = 1;
            } else {
                $hits = (int) Cache::increment($hitsKey);
            }

            if ($hits >= $threshold) {
                Cache::put($this->banKey($ip), true, now()->addMinutes($banMinutes));
                // Audit the escalation separately so ops can graph "ban
                // promotions" distinct from individual rule hits.
                $this->audit->security('security.waf.ip_banned', [
                    'ip' => $ip,
                    'hits' => $hits,
                    'threshold' => $threshold,
                    'ban_minutes' => $banMinutes,
                ]);
            }
        } catch (\Throwable $e) {
            // Cache backend trouble must never crash the request — we've
            // already issued the 403 to the client by this point.
            Log::channel('security')->warning('RequestFirewall: ip-ban tracking failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hitsKey(string $ip): string
    {
        return 'waf:ip:hits:'.$ip;
    }

    private function banKey(string $ip): string
    {
        return 'waf:ip:ban:'.$ip;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Audit + response
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param  array{label:string,location:string,sample:string}  $match
     */
    private function record(Request $request, array $match): void
    {
        try {
            $this->audit->security('security.waf.blocked', [
                'matched_pattern' => $match['label'],
                'location' => $match['location'],
                'sample' => $match['sample'],
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'mode' => $this->mode(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('security')->warning('RequestFirewall: audit write failed', [
                'matched_pattern' => $match['label'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function blockResponse(): Response
    {
        return response('Request blocked', 403, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Blocked-By' => 'flik-waf',
        ]);
    }

    private function isEnabled(): bool
    {
        return (bool) config('security.waf.enabled', true);
    }

    private function mode(): string
    {
        $mode = (string) config('security.waf.mode', 'block');

        return $mode === 'log_only' ? 'log_only' : 'block';
    }
}
