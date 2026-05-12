<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolve a request IP to an ISO 3166-1 alpha-2 country code.
 *
 * Resolution strategy (first hit wins):
 *   1. MaxMind GeoLite2-Country.mmdb local database (offline, fast, accurate)
 *      — requires `geoip2/geoip2` composer package + .mmdb file present.
 *   2. ip-api.com public JSON endpoint (free tier, ~45 req/min/IP).
 *
 * Results are cached for 24 hours per IP. Private/loopback IPs always
 * resolve to null (callers should treat null as "no geo restriction available"
 * and fall through to allow-by-default).
 *
 * The class is intentionally tolerant of missing dependencies — neither
 * MaxMind nor an internet connection is required for the app to boot.
 */
class GeoIpResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    private const HTTP_TIMEOUT_SECONDS = 2;

    private string $databasePath;

    private ?string $licenseKey;

    public function __construct(?string $databasePath = null, ?string $licenseKey = null)
    {
        $this->databasePath = $databasePath
            ?? (string) env('MAXMIND_DB_PATH', storage_path('app/geoip/GeoLite2-Country.mmdb'));

        $this->licenseKey = $licenseKey ?? (env('MAXMIND_LICENSE_KEY') ?: null);
    }

    /**
     * Resolve the ISO 3166-1 alpha-2 country code for an IP.
     *
     * Returns null for invalid input, private/loopback IPs, lookup
     * failures, or when neither MaxMind nor the HTTP fallback can answer.
     */
    public function country(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if ($this->isPrivateIp($ip)) {
            return null;
        }

        return Cache::remember(
            "geo:{$ip}",
            self::CACHE_TTL_SECONDS,
            function () use ($ip): ?string {
                $code = $this->resolveViaMaxMind($ip);

                if ($code === null) {
                    $code = $this->resolveViaHttpApi($ip);
                }

                return $code;
            },
        );
    }

    /**
     * Check whether the request IP is permitted by an allow-list.
     *
     * - Null or empty list → unrestricted (returns true).
     * - Otherwise the resolved country must be present in the list
     *   (case-insensitive, trimmed).
     * - If the IP is private/loopback or resolution fails, we fail-open
     *   (return true) — geo enforcement only restricts when we have a
     *   confident country answer. This avoids blocking traffic during
     *   MaxMind outages or internal monitoring.
     *
     * @param  array<int,string>|null  $allowList
     */
    public function isAllowed(string $ip, ?array $allowList): bool
    {
        if ($allowList === null || $allowList === []) {
            return true;
        }

        $country = $this->country($ip);

        if ($country === null) {
            return true;
        }

        $normalised = array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            $allowList,
        );

        return in_array(strtoupper($country), $normalised, true);
    }

    /**
     * Detect RFC1918 / loopback / link-local / unique-local addresses.
     *
     * Mirrors PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
     * but inverted, plus an explicit empty-string short-circuit so this
     * method is safe to call before validation.
     */
    public function isPrivateIp(string $ip): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Try MaxMind GeoLite2 .mmdb lookup. Returns null when the package is
     * not installed, the database file is absent, or the IP is unknown.
     */
    private function resolveViaMaxMind(string $ip): ?string
    {
        if (!class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        if (!is_file($this->databasePath) || !is_readable($this->databasePath)) {
            return null;
        }

        try {
            /** @psalm-suppress UndefinedClass */
            $reader = new \GeoIp2\Database\Reader($this->databasePath);
            $record = $reader->country($ip);
            $iso = $record->country->isoCode;

            return is_string($iso) && $iso !== '' ? strtoupper($iso) : null;
        } catch (Throwable $e) {
            Log::debug('GeoIpResolver: MaxMind lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fallback: ip-api.com free tier. Limited to ~45 req/min/source IP.
     * Caching ensures we typically issue at most one HTTP call per unique
     * client IP per day.
     */
    private function resolveViaHttpApi(string $ip): ?string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (!is_array($data) || ($data['status'] ?? null) !== 'success') {
                return null;
            }

            $code = $data['countryCode'] ?? null;

            return is_string($code) && $code !== '' ? strtoupper($code) : null;
        } catch (Throwable $e) {
            Log::debug('GeoIpResolver: HTTP fallback failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function databasePath(): string
    {
        return $this->databasePath;
    }

    public function hasLicenseKey(): bool
    {
        return $this->licenseKey !== null && $this->licenseKey !== '';
    }

    public function licenseKey(): ?string
    {
        return $this->licenseKey;
    }
}
