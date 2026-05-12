<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Subscription;
use App\Services\Geo\GeoIpResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Geo analytics for the Geo Distribution dashboard (D14).
 *
 * Country attribution strategy (first hit wins per user):
 *   1. `users.last_known_country` (if the column ever lands)
 *   2. `users.registered_ip`      (if the column ever lands) → resolve via GeoIP
 *   3. `drm_sessions.client_ip` + cached `country_code`     (most recent)
 *   4. `audit_logs.client_ip`                                (most recent)
 *
 * Watch heatmap: `watch_histories` has no IP column today, so we count
 * watch events per user and roll them up under the user's resolved
 * country.
 *
 * Every public method is wrapped in try/catch so an empty install or a
 * missing optional table never crashes the dashboard.
 */
class GeoAnalytics
{
    /**
     * Hard cap on how many users we resolve in a single render —
     * keeps the dashboard responsive on first cache miss.
     */
    private const MAX_USERS_TO_RESOLVE = 5000;

    public function __construct(private readonly GeoIpResolver $geo)
    {
    }

    /**
     * Distinct user count grouped by ISO 3166 alpha-2 country.
     *
     * @return array<int, array{code: string, name: string, flag: string, users: int}>
     */
    public function userDistribution(): array
    {
        try {
            $userCountry = $this->resolveUserCountries();
            if ($userCountry === []) {
                return [];
            }

            $perCountry = [];
            foreach ($userCountry as $cc) {
                $perCountry[$cc] = ($perCountry[$cc] ?? 0) + 1;
            }

            $rows = [];
            foreach ($perCountry as $cc => $count) {
                $rows[] = [
                    'code'  => $cc,
                    'name'  => $this->countryName($cc),
                    'flag'  => $this->flagEmoji($cc),
                    'users' => (int) $count,
                ];
            }
            usort($rows, static fn ($a, $b) => $b['users'] <=> $a['users']);

            return $rows;
        } catch (Throwable $e) {
            Log::warning('GeoAnalytics::userDistribution failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Total paid revenue grouped by country (using each user's resolved
     * country at attribution time).
     *
     * @return array<int, array{code: string, name: string, flag: string, revenue: float}>
     */
    public function revenueByCountry(): array
    {
        try {
            $userCountry = $this->resolveUserCountries();
            if ($userCountry === [] || !Schema::hasTable('subscriptions')) {
                return [];
            }

            $userIds = array_keys($userCountry);

            $totals = Subscription::query()
                ->whereIn('user_id', $userIds)
                ->where('amount', '>', 0)
                ->select('user_id', DB::raw('SUM(amount) as total'))
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            $perCountry = [];
            foreach ($totals as $uid => $total) {
                $cc = $userCountry[(int) $uid] ?? null;
                if ($cc === null) {
                    continue;
                }
                $perCountry[$cc] = ($perCountry[$cc] ?? 0.0) + (float) $total;
            }

            $rows = [];
            foreach ($perCountry as $cc => $sum) {
                $rows[] = [
                    'code'    => $cc,
                    'name'    => $this->countryName($cc),
                    'flag'    => $this->flagEmoji($cc),
                    'revenue' => round((float) $sum, 2),
                ];
            }
            usort($rows, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

            return $rows;
        } catch (Throwable $e) {
            Log::warning('GeoAnalytics::revenueByCountry failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * "Heatmap" of watch events per country across the last `$days`.
     *
     * Watch_histories has no IP column today, so each watch row is
     * attributed to its user's resolved country. If a future schema
     * gains `watch_histories.client_ip` this method should be the
     * single place we swap to a row-level resolver.
     *
     * @return array<int, array{code: string, name: string, flag: string, watches: int}>
     */
    public function viewerHeatmap(int $days = 7): array
    {
        try {
            if (!Schema::hasTable('watch_histories')) {
                return [];
            }

            $userCountry = $this->resolveUserCountries();
            if ($userCountry === []) {
                return [];
            }

            $since = Carbon::now()->subDays(max(1, $days));

            // Pick the right timestamp column (last_watched_at preferred,
            // updated_at as a safe fallback).
            $timeCol = Schema::hasColumn('watch_histories', 'last_watched_at')
                ? 'last_watched_at'
                : 'updated_at';

            $userIds = array_keys($userCountry);

            $watchCounts = DB::table('watch_histories')
                ->whereIn('user_id', $userIds)
                ->where($timeCol, '>=', $since)
                ->select('user_id', DB::raw('COUNT(*) as n'))
                ->groupBy('user_id')
                ->pluck('n', 'user_id');

            $perCountry = [];
            foreach ($watchCounts as $uid => $n) {
                $cc = $userCountry[(int) $uid] ?? null;
                if ($cc === null) {
                    continue;
                }
                $perCountry[$cc] = ($perCountry[$cc] ?? 0) + (int) $n;
            }

            $rows = [];
            foreach ($perCountry as $cc => $count) {
                $rows[] = [
                    'code'    => $cc,
                    'name'   => $this->countryName($cc),
                    'flag'   => $this->flagEmoji($cc),
                    'watches' => (int) $count,
                ];
            }
            usort($rows, static fn ($a, $b) => $b['watches'] <=> $a['watches']);

            return $rows;
        } catch (Throwable $e) {
            Log::warning('GeoAnalytics::viewerHeatmap failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internal: user → country resolution (memoised per request)
    // ──────────────────────────────────────────────────────────────────

    /** @var array<int,string>|null */
    private ?array $userCountryCache = null;

    /**
     * Build (and memoise) the user_id => ISO-2 country code map by
     * walking the precedence list.
     *
     * @return array<int,string>
     */
    public function resolveUserCountries(): array
    {
        if ($this->userCountryCache !== null) {
            return $this->userCountryCache;
        }

        $map = [];

        // 1+2. users.last_known_country / users.registered_ip — only if those
        // columns ever exist. Schema-aware so no exception if they don't.
        try {
            if (Schema::hasTable('users')) {
                $select = ['id'];
                $hasCountry = Schema::hasColumn('users', 'last_known_country');
                $hasIp      = Schema::hasColumn('users', 'registered_ip');
                if ($hasCountry) {
                    $select[] = 'last_known_country';
                }
                if ($hasIp) {
                    $select[] = 'registered_ip';
                }

                if ($hasCountry || $hasIp) {
                    $rows = DB::table('users')
                        ->select($select)
                        ->limit(self::MAX_USERS_TO_RESOLVE)
                        ->get();

                    foreach ($rows as $row) {
                        $uid = (int) $row->id;
                        if (isset($map[$uid])) {
                            continue;
                        }
                        $code = $hasCountry ? $this->normaliseCountry($row->last_known_country ?? null) : null;
                        if ($code === null && $hasIp && !empty($row->registered_ip)) {
                            $code = $this->geo->country((string) $row->registered_ip);
                        }
                        if ($code !== null) {
                            $map[$uid] = $code;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug('GeoAnalytics: users-table lookup skipped', ['error' => $e->getMessage()]);
        }

        // 3. drm_sessions: most-recent row per user.
        try {
            if (Schema::hasTable('drm_sessions')) {
                $latest = DB::table('drm_sessions')
                    ->select('user_id', DB::raw('MAX(id) as latest_id'))
                    ->groupBy('user_id')
                    ->limit(self::MAX_USERS_TO_RESOLVE)
                    ->get();

                if ($latest->isNotEmpty()) {
                    $details = DB::table('drm_sessions')
                        ->select('user_id', 'client_ip', 'country_code')
                        ->whereIn('id', $latest->pluck('latest_id')->all())
                        ->get();

                    foreach ($details as $row) {
                        if ($row->user_id === null) {
                            continue;
                        }
                        $uid = (int) $row->user_id;
                        if (isset($map[$uid])) {
                            continue;
                        }
                        $code = $this->normaliseCountry($row->country_code ?? null);
                        if ($code === null && !empty($row->client_ip)) {
                            $code = $this->geo->country((string) $row->client_ip);
                        }
                        if ($code !== null) {
                            $map[$uid] = $code;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug('GeoAnalytics: drm_sessions lookup skipped', ['error' => $e->getMessage()]);
        }

        // 4. audit_logs: most-recent IP per user, for anyone still unattributed.
        try {
            if (Schema::hasTable('audit_logs')) {
                $latest = DB::table('audit_logs')
                    ->whereNotNull('user_id')
                    ->whereNotNull('client_ip')
                    ->select('user_id', DB::raw('MAX(id) as latest_id'))
                    ->groupBy('user_id')
                    ->limit(self::MAX_USERS_TO_RESOLVE)
                    ->get();

                if ($latest->isNotEmpty()) {
                    $details = DB::table('audit_logs')
                        ->select('user_id', 'client_ip')
                        ->whereIn('id', $latest->pluck('latest_id')->all())
                        ->get();

                    foreach ($details as $row) {
                        $uid = (int) $row->user_id;
                        if ($uid === 0 || isset($map[$uid])) {
                            continue;
                        }
                        $code = $this->geo->country((string) $row->client_ip);
                        if ($code !== null) {
                            $map[$uid] = $code;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug('GeoAnalytics: audit_logs lookup skipped', ['error' => $e->getMessage()]);
        }

        return $this->userCountryCache = $map;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Display helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Validate + upper-case a 2-letter ISO 3166 alpha-2 country code.
     */
    public function normaliseCountry(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Convert a 2-letter ISO country code to its flag emoji by mapping
     * each letter onto the corresponding regional indicator symbol.
     */
    public function flagEmoji(string $code): string
    {
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '🌐';
        }
        $first  = mb_chr(ord($code[0]) + 0x1F1A5, 'UTF-8');
        $second = mb_chr(ord($code[1]) + 0x1F1A5, 'UTF-8');
        return $first . $second;
    }

    /**
     * Look up a human-readable name for a country code.
     */
    public function countryName(string $code): string
    {
        static $map = [
            'ID' => 'Indonesia', 'MY' => 'Malaysia', 'SG' => 'Singapore', 'TH' => 'Thailand',
            'PH' => 'Philippines', 'VN' => 'Vietnam', 'BN' => 'Brunei', 'KH' => 'Cambodia',
            'LA' => 'Laos', 'MM' => 'Myanmar', 'TL' => 'Timor-Leste',
            'US' => 'United States', 'CA' => 'Canada', 'MX' => 'Mexico',
            'BR' => 'Brazil', 'AR' => 'Argentina',
            'GB' => 'United Kingdom', 'IE' => 'Ireland', 'FR' => 'France', 'DE' => 'Germany',
            'NL' => 'Netherlands', 'BE' => 'Belgium', 'ES' => 'Spain', 'PT' => 'Portugal',
            'IT' => 'Italy', 'CH' => 'Switzerland', 'AT' => 'Austria',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'PL' => 'Poland', 'CZ' => 'Czechia', 'GR' => 'Greece', 'TR' => 'Turkey',
            'RU' => 'Russia', 'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
            'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman',
            'EG' => 'Egypt', 'MA' => 'Morocco',
            'NG' => 'Nigeria', 'KE' => 'Kenya', 'ZA' => 'South Africa',
            'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka',
            'NP' => 'Nepal',
            'CN' => 'China', 'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'JP' => 'Japan',
            'KR' => 'South Korea',
            'AU' => 'Australia', 'NZ' => 'New Zealand', 'FJ' => 'Fiji',
        ];

        return $map[$code] ?? $code;
    }
}
