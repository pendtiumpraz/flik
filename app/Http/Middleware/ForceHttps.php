<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard-redirect every plain-HTTP request to its HTTPS equivalent.
 *
 * Sits in the global stack AFTER TrustProxies so $request->isSecure()
 * sees the X-Forwarded-Proto header from the load balancer / CDN edge.
 * Without TrustProxies running first, this middleware would 301-loop
 * any request fronted by an HTTPS-terminating proxy.
 *
 * Skipped automatically in `local` and `testing` so artisan serve and
 * PHPUnit feature tests don't have to fight the redirect. Production
 * (and any other env: staging, demo, etc.) get the upgrade.
 *
 * Pairs with:
 *   - URL::forceScheme('https') in AppServiceProvider — fixes outgoing links
 *   - SecurityHeaders middleware — sends HSTS only when already on HTTPS
 *   - SESSION_SECURE_COOKIE=true in prod — cookie won't even be sent on HTTP
 */
final class ForceHttps
{
    /**
     * Environments that are allowed to serve over plain HTTP.
     *
     * @var array<int, string>
     */
    private const HTTP_ALLOWED_ENVS = ['local', 'testing'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirect($request)) {
            $secureUrl = $this->buildSecureUrl($request);

            // 301 Permanent — browsers cache the upgrade and won't ask
            // for HTTP again. Combined with HSTS this hardens the path.
            return new RedirectResponse($secureUrl, 301, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        if (in_array(app()->environment(), self::HTTP_ALLOWED_ENVS, true)) {
            return false;
        }

        // After TrustProxies has rewritten the request with X-Forwarded-Proto,
        // isSecure() reflects the *external* connection scheme — exactly what
        // we want. Direct internal hits (e.g. health checks from inside the
        // VPC over HTTP) will still upgrade, which is acceptable.
        return ! $request->isSecure();
    }

    private function buildSecureUrl(Request $request): string
    {
        $url = $request->fullUrl();

        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        // Fallback: rebuild from host + path so an unusual scheme (anything
        // other than http://) still produces a valid https:// target.
        return 'https://' . $request->getHttpHost() . $request->getRequestUri();
    }
}
