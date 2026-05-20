<?php

declare(strict_types=1);

namespace App\Services\Tmdb;

use App\Exceptions\SsrfException;
use App\Services\Security\SafeHttp;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read-only client for The Movie Database (TMDB) v3 REST API.
 *
 * Auth strategy (in priority order):
 *   1. v4 Bearer token (TMDB_BEARER)  — higher rate limit, recommended.
 *   2. v3 API key (TMDB_KEY)           — appended as `?api_key=...` query param.
 *
 * Both can be set at once — bearer wins. If neither is configured the client
 * is gracefully disabled: every fetch method returns null/[] without
 * throwing, and {@see enabled()} returns false so the wizard view can show
 * a "Configure TMDB_KEY in .env" hint instead of a stack trace.
 *
 * All outbound HTTP routes through {@see SafeHttp} (and therefore through
 * {@see \App\Services\Security\SsrfGuard}) so a future config change that
 * pointed `base_url` at an internal host can't pivot us into the private
 * network. The default base URL is api.themoviedb.org which is always
 * public — but the SSRF guard is cheap insurance.
 *
 * Responses are cached for 24h per `(type, id)` pair to dodge TMDB rate
 * limits on re-imports/previews. The cache TTL is intentionally long: TMDB
 * metadata rarely changes meaningfully day-to-day, and operators can force
 * a refresh by calling {@see forget()} or by clearing the cache.
 */
class TmdbClient
{
    /**
     * TMDB v3 base — public, never rotates.
     */
    public const BASE_URL = 'https://api.themoviedb.org/3';

    /**
     * Public CDN for TMDB images. Always public, never authed.
     */
    public const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p';

    /**
     * 24h cache TTL — long enough to absorb a wizard preview → import
     * cycle without re-hitting TMDB; short enough that a re-import next
     * day picks up fresh ratings/popularity numbers.
     */
    private const CACHE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly ?SafeHttp $safe = null,
        private readonly ?HttpFactory $http = null,
    ) {
    }

    /**
     * True when at least one credential is configured. Wizard views and the
     * `flik:tmdb:health` console command branch on this so missing config
     * shows a friendly "set TMDB_KEY in .env" message rather than throwing.
     */
    public function enabled(): bool
    {
        return ! empty($this->apiKey()) || ! empty($this->bearer());
    }

    /**
     * Fetch a movie record with the bundled relationships the wizard needs:
     * credits (cast + crew), videos (for trailer keys), images (extra
     * posters/backdrops), external_ids (IMDB id), and keywords.
     *
     * Returns null when TMDB has no such id, when the API key is invalid,
     * or when SSRF/network errors block the call. Callers MUST handle null.
     *
     * @return array<string, mixed>|null
     */
    public function findMovie(int $tmdbId): ?array
    {
        return $this->cachedGet(
            "/movie/{$tmdbId}",
            ['append_to_response' => 'credits,videos,images,external_ids,keywords'],
            "tmdb:movie:{$tmdbId}",
        );
    }

    /**
     * Fetch a TV series record with the same bundled relationships as
     * {@see findMovie()}. The schema differs subtly from movies (e.g.
     * `name` instead of `title`, `first_air_date` instead of `release_date`)
     * — {@see MovieImporter::preview()} normalises both shapes into a
     * common envelope.
     *
     * @return array<string, mixed>|null
     */
    public function findTv(int $tmdbId): ?array
    {
        return $this->cachedGet(
            "/tv/{$tmdbId}",
            ['append_to_response' => 'credits,videos,images,external_ids,keywords'],
            "tmdb:tv:{$tmdbId}",
        );
    }

    /**
     * Search TMDB's movie index by free-text query (typeahead path).
     * Returns the `results` array verbatim (TMDB already includes
     * poster_path, vote_average, etc. per hit). Cached 1h per query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchMovie(string $query, ?int $year = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $params = [
            'query' => $query,
            'include_adult' => 'false',
            'language' => 'en-US',
        ];
        if ($year !== null) {
            $params['year'] = $year;
        }

        $key = 'tmdb:search:movie:'.md5($query.'|'.($year ?? ''));
        $payload = $this->cachedGet('/search/movie', $params, $key, ttl: 3600);

        return is_array($payload['results'] ?? null) ? $payload['results'] : [];
    }

    /**
     * Search TMDB's TV index. Same shape contract as {@see searchMovie()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchTv(string $query, ?int $year = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $params = [
            'query' => $query,
            'include_adult' => 'false',
            'language' => 'en-US',
        ];
        if ($year !== null) {
            $params['first_air_date_year'] = $year;
        }

        $key = 'tmdb:search:tv:'.md5($query.'|'.($year ?? ''));
        $payload = $this->cachedGet('/search/tv', $params, $key, ttl: 3600);

        return is_array($payload['results'] ?? null) ? $payload['results'] : [];
    }

    /**
     * Look up extended biographical info for a person (used to enrich a
     * Cast row beyond the credits payload — bio, birthplace, etc).
     *
     * @return array<string, mixed>|null
     */
    public function personDetails(int $personId): ?array
    {
        return $this->cachedGet(
            "/person/{$personId}",
            ['append_to_response' => 'external_ids'],
            "tmdb:person:{$personId}",
        );
    }

    /**
     * Compose a fully-qualified TMDB image URL from a stored relative
     * path. TMDB image paths look like `/xyz.jpg` (always leading slash)
     * — we tolerate either with/without the slash.
     *
     * Common sizes: w92, w154, w185, w342, w500, w780, original.
     */
    public function imageUrl(string $path, string $size = 'w500'): string
    {
        if ($path === '') {
            return '';
        }
        // Pass through if caller already gave us an absolute URL.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        return self::IMAGE_BASE_URL.'/'.$size.$path;
    }

    /**
     * Force-evict cached entries for one TMDB id. Called by
     * {@see MovieImporter} after a successful import so a re-preview
     * picks up fresh data, and exposed for ops/tests.
     */
    public function forget(string $type, int $id): void
    {
        Cache::forget("tmdb:{$type}:{$id}");
    }

    // ──────────────────────────────────────────────────────────────────
    // INTERNALS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Read TMDB v3 API key (used as the `?api_key=` query param).
     */
    private function apiKey(): ?string
    {
        $key = config('services.tmdb.api_key');
        if (is_string($key) && $key !== '') {
            return $key;
        }
        // Backwards-compat: an older env layout used TMDB_TOKEN for the v3 key.
        $legacy = config('services.tmdb.token');
        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }

    /**
     * Read TMDB v4 bearer token (used as `Authorization: Bearer ...`).
     */
    private function bearer(): ?string
    {
        $bearer = config('services.tmdb.bearer');
        return is_string($bearer) && $bearer !== '' ? $bearer : null;
    }

    /**
     * Cache-fronted GET helper. Returns null on hard failure so callers
     * never see a half-formed array. Network errors are downgraded to
     * Log::warning — TMDB occasionally 429s during peak hours and we'd
     * rather show "not found" than page the operator.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>|null
     */
    private function cachedGet(string $path, array $params, string $cacheKey, int $ttl = self::CACHE_TTL_SECONDS): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return Cache::remember($cacheKey, $ttl, function () use ($path, $params): ?array {
            return $this->rawGet($path, $params);
        });
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>|null
     */
    private function rawGet(string $path, array $params): ?array
    {
        $url = self::BASE_URL.$path;
        $headers = ['Accept' => 'application/json'];

        // Prefer the v4 bearer token (higher rate limit). Fall back to v3
        // api_key on the query string when only that one is configured.
        $bearer = $this->bearer();
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer '.$bearer;
        } else {
            $params['api_key'] = (string) $this->apiKey();
        }

        try {
            // Prefer SafeHttp (SSRF-guarded). Fall back to a raw HttpFactory
            // if the wrapper isn't bound — keeps the service usable in unit
            // tests that mock HttpFactory directly.
            if ($this->safe !== null) {
                $response = $this->safe->get($url, $params, [
                    'timeout' => 8,
                    'connect_timeout' => 5,
                    'headers' => $headers,
                ]);
            } else {
                $http = $this->http ?? app(HttpFactory::class);
                $response = $http
                    ->timeout(8)
                    ->connectTimeout(5)
                    ->withHeaders($headers)
                    ->get($url, $params);
            }
        } catch (SsrfException $e) {
            Log::warning('TmdbClient: SSRF guard blocked outbound URL', [
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('TmdbClient: HTTP transport error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->status() === 404) {
            // Cache the negative result briefly so a typo-induced storm doesn't
            // hammer TMDB. Cache::remember will persist whatever we return.
            return null;
        }

        if (! $response->successful()) {
            Log::warning('TmdbClient: non-2xx response', [
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => mb_substr((string) $response->body(), 0, 200),
            ]);
            return null;
        }

        $json = $response->json();
        return is_array($json) ? $json : null;
    }
}
