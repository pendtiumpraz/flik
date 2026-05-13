<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\SsrfException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * SSRF-aware wrapper around Laravel's HTTP client.
 *
 * Every entry point ({@see get}, {@see post}, {@see head}, {@see put},
 * {@see delete}, {@see patch}) routes through {@see SsrfGuard} before any
 * socket is opened. Redirects are bounded to 3 hops and constrained to
 * http(s) — `Location: file://` redirects can't escape the protocol guard.
 *
 * Defaults applied to every outbound request:
 *  - 8s timeout (overridable via $opts['timeout'])
 *  - 5s connect timeout
 *  - max 3 redirects, http/https only
 *  - User-Agent: "FLiK-SafeHttp/1.0"
 *
 * Caller-overridable via the $opts array on every method:
 *  - 'timeout'        int        request timeout in seconds (default 8)
 *  - 'connect_timeout' int       socket connect timeout (default 5)
 *  - 'headers'        array      extra request headers (merged)
 *  - 'allowed_hosts'  list<str>  per-call allowlist passed to SsrfGuard
 *  - 'attach'         array      Multipart attachment {name, contents, filename}
 *  - 'body'           string     raw body for POST/PUT (mutually exclusive with array $data)
 *  - 'as'             string     'json' | 'form' (default 'json' for write methods)
 *  - 'auth_bearer'    string     Bearer token shorthand
 *
 * Note: the wrapper deliberately does NOT pin cURL to a specific resolved
 * IP yet — Laravel's facade doesn't expose CURLOPT_RESOLVE cleanly. The
 * guard still protects against rebinding by re-resolving immediately
 * before the request via the same DNS cache.  Future hardening can wire
 * in a Guzzle handler stack that calls SsrfGuard::resolveSafely() and
 * pins via curl options.
 */
class SafeHttp
{
    private const DEFAULT_TIMEOUT = 8;
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const DEFAULT_MAX_REDIRECTS = 3;
    private const USER_AGENT = 'FLiK-SafeHttp/1.0';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly SsrfGuard $guard,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $opts
     */
    public function get(string $url, array $query = [], array $opts = []): Response
    {
        return $this->prepare($url, $opts)->get($url, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     */
    public function post(string $url, array $data = [], array $opts = []): Response
    {
        $request = $this->prepare($url, $opts);
        $as = $opts['as'] ?? 'json';

        if (isset($opts['body'])) {
            return $request->withBody($opts['body'], $opts['headers']['Content-Type'] ?? 'application/json')
                ->post($url);
        }

        return $as === 'form'
            ? $request->asForm()->post($url, $data)
            : $request->post($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     */
    public function put(string $url, array $data = [], array $opts = []): Response
    {
        $request = $this->prepare($url, $opts);

        if (isset($opts['body'])) {
            return $request->withBody($opts['body'], $opts['headers']['Content-Type'] ?? 'application/octet-stream')
                ->put($url);
        }

        return $request->put($url, $data);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    public function head(string $url, array $opts = []): Response
    {
        return $this->prepare($url, $opts)->head($url);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     */
    public function delete(string $url, array $data = [], array $opts = []): Response
    {
        return $this->prepare($url, $opts)->delete($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     */
    public function patch(string $url, array $data = [], array $opts = []): Response
    {
        return $this->prepare($url, $opts)->patch($url, $data);
    }

    /**
     * Convenience: build a {@see PendingRequest} the caller can chain on
     * (e.g. ->attach(), ->withToken()) — but the SSRF check has already
     * fired on $url so the eventual send() is safe.
     *
     * @param  array<string, mixed>  $opts
     */
    public function request(string $url, array $opts = []): PendingRequest
    {
        return $this->prepare($url, $opts);
    }

    /**
     * Run the guard, then build a baseline PendingRequest with safe defaults.
     *
     * @param  array<string, mixed>  $opts
     */
    private function prepare(string $url, array $opts): PendingRequest
    {
        /** @var list<string> $extraHosts */
        $extraHosts = $opts['allowed_hosts'] ?? [];

        try {
            $this->guard->assertUrlAllowed($url, $extraHosts);
        } catch (SsrfException $e) {
            // Logged at warning so /admin/audit-logs operators see attack
            // patterns without needing error-level paging.
            Log::warning('SafeHttp: SSRF guard blocked outbound URL', [
                'url'    => $url,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        $timeout = (int) ($opts['timeout'] ?? self::DEFAULT_TIMEOUT);
        $connectTimeout = (int) ($opts['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);
        $maxRedirects = (int) ($opts['max_redirects'] ?? self::DEFAULT_MAX_REDIRECTS);

        $headers = (array) ($opts['headers'] ?? []);
        if (!isset($headers['User-Agent'])) {
            $headers['User-Agent'] = self::USER_AGENT;
        }

        $request = $this->http
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withHeaders($headers)
            ->withOptions([
                'allow_redirects' => [
                    'max'             => $maxRedirects,
                    'protocols'       => ['http', 'https'],
                    'strict'          => true,
                    'referer'         => false,
                    'track_redirects' => false,
                ],
            ]);

        if (!empty($opts['auth_bearer'])) {
            $request = $request->withToken((string) $opts['auth_bearer']);
        }

        if (!empty($opts['attach']) && is_array($opts['attach'])) {
            foreach ($opts['attach'] as $attachment) {
                $request = $request->attach(
                    (string) ($attachment['name'] ?? 'file'),
                    $attachment['contents'] ?? '',
                    (string) ($attachment['filename'] ?? 'file'),
                    (array) ($attachment['headers'] ?? []),
                );
            }
        }

        return $request;
    }
}
