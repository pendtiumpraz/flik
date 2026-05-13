<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects a baseline of HTTP security headers on every response.
 *
 * Headers applied (see config/security.php → headers.csp for CSP knobs):
 *   - Strict-Transport-Security  (only in production over HTTPS)
 *   - X-Content-Type-Options
 *   - X-Frame-Options
 *   - Referrer-Policy
 *   - Permissions-Policy
 *   - Cross-Origin-Opener-Policy
 *   - Cross-Origin-Resource-Policy
 *   - Content-Security-Policy[-Report-Only]
 *
 * Binary responses (images, video segments, octet-stream downloads) skip
 * header injection — they're served by the CDN edge or video player and
 * the meta-headers are irrelevant for them while costing bandwidth.
 *
 * Registered globally in App\Http\Kernel after TrustProxies so the
 * `$request->isSecure()` check sees the real scheme behind a proxy.
 */
final class SecurityHeaders
{
    /**
     * Static, request-independent header values. STS + CSP are computed
     * per-request and merged on top of these.
     *
     * @var array<string, string>
     */
    private const STATIC_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->shouldSkip($response)) {
            return $response;
        }

        foreach (self::STATIC_HEADERS as $name => $value) {
            // Don't clobber a header an upstream layer already set intentionally
            // (e.g. an iframe-friendly embed route overriding X-Frame-Options).
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($this->shouldSendHsts($request)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        $this->applyCsp($response);

        return $response;
    }

    /**
     * Skip injection for binary payloads where the headers add no value
     * but bloat every CDN cache entry. Image/video/octet-stream are the
     * common cases (HLS segments, poster downloads, master files).
     */
    private function shouldSkip(Response $response): bool
    {
        // Streamed responses (e.g. CSV exports, X-Sendfile) and direct file
        // sends are typically binary or out of our content-protection scope.
        if ($response instanceof BinaryFileResponse) {
            return true;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($contentType === '') {
            return false;
        }

        return str_starts_with($contentType, 'image/')
            || str_starts_with($contentType, 'video/')
            || str_starts_with($contentType, 'application/octet-stream');
    }

    /**
     * HSTS is only valid over HTTPS in production. Sending it over HTTP
     * is a no-op per the RFC, but sending it on staging/local would be
     * a footgun if someone copy-pastes a staging URL.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return app()->environment('production') && $request->isSecure();
    }

    private function applyCsp(Response $response): void
    {
        $config = config('security.headers.csp', []);

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $directives = $config['directives'] ?? [];
        if ($directives === []) {
            return;
        }

        $policy = $this->buildCspString($directives);
        if ($policy === '') {
            return;
        }

        // If we expose a CSP report endpoint, append it. Older browsers honor
        // `report-uri`; newer ones use `report-to` + a Reporting-Endpoints
        // header — we ship the legacy form because it requires no extra
        // header negotiation and admins read raw laravel.log anyway.
        if ($this->hasReportEndpoint()) {
            $policy .= '; report-uri '.url('/csp-report');
        }

        $headerName = ($config['report_only'] ?? false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($headerName, $policy);
    }

    /**
     * @param  array<string, array<int, string>>  $directives
     */
    private function buildCspString(array $directives): string
    {
        $parts = [];

        foreach ($directives as $directive => $sources) {
            if (! is_array($sources) || $sources === []) {
                continue;
            }

            $parts[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    private function hasReportEndpoint(): bool
    {
        try {
            return Route::has('security.csp-report');
        } catch (\Throwable) {
            return false;
        }
    }
}
