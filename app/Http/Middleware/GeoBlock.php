<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Movie;
use App\Services\Audit\AuditLogger;
use App\Services\Geo\GeoIpResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Geo-block middleware: enforces per-movie country allow-lists.
 *
 * Usage in routes (after registering the alias `geoblock` in Kernel.php):
 *     Route::get('/movie/{movie}', [VelflixController::class, 'show'])
 *         ->middleware('geoblock');
 *
 * Behaviour:
 *   - Reads the `{movie}` route parameter (route-model-bound or raw id/slug).
 *   - If the movie has no `geo_allow` list → request passes through.
 *   - If the resolver cannot determine a country (private IP, MaxMind
 *     unavailable, fallback API failure) → request passes through
 *     (fail-open — see GeoIpResolver::isAllowed()).
 *   - Otherwise the request is denied with HTTP 451 ("Unavailable for
 *     Legal Reasons") and the attempt is recorded via AuditLogger.
 */
class GeoBlock
{
    public function __construct(
        private readonly GeoIpResolver $resolver,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $movie = $this->resolveMovie($request);

        if (!$movie instanceof Movie) {
            return $next($request);
        }

        $allowList = $this->normaliseAllowList($movie->geo_allow ?? null);

        if ($allowList === null) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if ($this->resolver->isAllowed($ip, $allowList)) {
            return $next($request);
        }

        $country = $this->resolver->country($ip);

        try {
            $this->audit->log(
                action: 'geo.blocked',
                subject: $movie,
                meta: [
                    'ip' => $ip,
                    'country' => $country,
                    'allow_list' => $allowList,
                    'path' => $request->path(),
                ],
            );
        } catch (\Throwable) {
            // Audit failures must never mask the geo-block response.
        }

        throw new HttpException(451, 'Konten tidak tersedia di negara Anda');
    }

    /**
     * Pull the movie out of the route. Supports both route-model-bound
     * Movie instances and raw scalar parameters (slug or id).
     */
    private function resolveMovie(Request $request): ?Movie
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $param = $route->parameter('movie');

        if ($param instanceof Movie) {
            return $param;
        }

        if (is_string($param) || is_int($param)) {
            $key = (string) $param;

            return Movie::query()
                ->where('slug', $key)
                ->orWhere('id', $key)
                ->first();
        }

        return null;
    }

    /**
     * Coerce arbitrary `geo_allow` storage shapes into a normalised
     * list of uppercase ISO codes — or null when no restriction applies.
     *
     * @param  mixed  $raw
     * @return array<int,string>|null
     */
    private function normaliseAllowList(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return null;
        }

        $codes = [];
        foreach ($raw as $code) {
            if (!is_string($code)) {
                continue;
            }
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes !== [] ? array_values(array_unique($codes)) : null;
    }
}
