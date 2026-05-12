<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Geo\GeoIpResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Admin Geo Distribution Dashboard (D14).
 *
 * For every user, picks the most recent IP we have on file — order of
 * precedence:
 *   1. drm_sessions.client_ip (most recent, already has country_code cached)
 *   2. audit_logs.client_ip   (most recent)
 *
 * The DB schema has no first-class `users.last_login_ip` column, so we
 * lean on the two tables that DO capture client IP. GeoIpResolver fills
 * in country codes where DRM didn't already cache one.
 *
 * Three top-20 leaderboards are computed:
 *   - by_user_count   — distinct users per country
 *   - by_watch_count  — sum of watch_histories rows for users in that country
 *   - by_revenue      — sum of paid subscription amounts for users in that country
 *
 * Aggregates are cached under `geo:distribution:v1` for 6 hours. Pass
 * `?refresh=1` to bust.
 */
class GeoDistributionController extends Controller
{
    private const CACHE_KEY = 'geo:distribution:v1';

    private const CACHE_TTL = 21600; // 6 hours

    /** Top-N cap for the leaderboards. */
    private const TOP_N = 20;

    /**
     * Hard cap on how many users we resolve IPs for in a single render,
     * just to keep the dashboard responsive on first cache miss.
     */
    private const MAX_USERS_TO_RESOLVE = 5000;

    public function __construct(private readonly GeoIpResolver $geo)
    {
    }

    public function index(\Illuminate\Http\Request $request): View
    {
        if ($request->query('refresh') === '1') {
            Cache::forget(self::CACHE_KEY);
        }

        $data = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn (): array => $this->computeDistribution(),
        );

        return view('admin.geo.distribution', $data);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Aggregation
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   stats: array<string,mixed>,
     *   countries: array<int, array<string,mixed>>,
     *   topByUsers: array<int, array<string,mixed>>,
     *   topByWatches: array<int, array<string,mixed>>,
     *   topByRevenue: array<int, array<string,mixed>>,
     *   computedAt: string,
     * }
     */
    private function computeDistribution(): array
    {
        // ── 1. Build user → (country, ip) map ────────────────────────
        // Prefer drm_sessions (already has country_code cached); fall
        // back to audit_logs for users who never hit playback.
        $userCountry = []; // user_id => 2-letter ISO

        if (Schema::hasTable('drm_sessions')) {
            $drmRows = DB::table('drm_sessions')
                ->select('user_id', DB::raw('MAX(id) as latest_id'))
                ->groupBy('user_id')
                ->limit(self::MAX_USERS_TO_RESOLVE)
                ->get();

            if ($drmRows->isNotEmpty()) {
                $latestIds = $drmRows->pluck('latest_id')->all();
                $drmDetails = DB::table('drm_sessions')
                    ->select('user_id', 'client_ip', 'country_code')
                    ->whereIn('id', $latestIds)
                    ->get();
                foreach ($drmDetails as $row) {
                    if ($row->user_id === null) {
                        continue;
                    }
                    $code = $this->normaliseCountry($row->country_code);
                    if ($code === null && $row->client_ip) {
                        $code = $this->geo->country((string) $row->client_ip);
                    }
                    if ($code !== null) {
                        $userCountry[(int) $row->user_id] = $code;
                    }
                }
            }
        }

        if (Schema::hasTable('audit_logs')) {
            // For users not yet attributed, look up their most recent audit log IP.
            $auditRows = DB::table('audit_logs')
                ->whereNotNull('user_id')
                ->whereNotNull('client_ip')
                ->select('user_id', DB::raw('MAX(id) as latest_id'))
                ->groupBy('user_id')
                ->limit(self::MAX_USERS_TO_RESOLVE)
                ->get();

            if ($auditRows->isNotEmpty()) {
                $latestIds = $auditRows->pluck('latest_id')->all();
                $auditDetails = DB::table('audit_logs')
                    ->select('user_id', 'client_ip')
                    ->whereIn('id', $latestIds)
                    ->get();
                foreach ($auditDetails as $row) {
                    $uid = (int) $row->user_id;
                    if ($uid === 0 || isset($userCountry[$uid])) {
                        continue; // already attributed via DRM
                    }
                    $code = $this->geo->country((string) $row->client_ip);
                    if ($code !== null) {
                        $userCountry[$uid] = $code;
                    }
                }
            }
        }

        // ── 2. Roll up per-country: users, watches, revenue ──────────
        $perCountry = []; // CC => ['users'=>int,'watches'=>int,'revenue'=>float]

        foreach ($userCountry as $uid => $cc) {
            $perCountry[$cc] ??= ['users' => 0, 'watches' => 0, 'revenue' => 0.0];
            $perCountry[$cc]['users']++;
        }

        if (!empty($userCountry) && Schema::hasTable('watch_histories')) {
            // Bulk watch counts per user_id then re-aggregate per CC.
            $userIds = array_keys($userCountry);
            $watchCounts = DB::table('watch_histories')
                ->whereIn('user_id', $userIds)
                ->select('user_id', DB::raw('COUNT(*) as n'))
                ->groupBy('user_id')
                ->pluck('n', 'user_id');

            foreach ($watchCounts as $uid => $n) {
                $cc = $userCountry[(int) $uid] ?? null;
                if ($cc !== null) {
                    $perCountry[$cc]['watches'] += (int) $n;
                }
            }
        }

        if (!empty($userCountry)) {
            $userIds = array_keys($userCountry);
            $revenueRows = Subscription::query()
                ->whereIn('user_id', $userIds)
                ->where('amount', '>', 0)
                ->select('user_id', DB::raw('SUM(amount) as total'))
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            foreach ($revenueRows as $uid => $total) {
                $cc = $userCountry[(int) $uid] ?? null;
                if ($cc !== null) {
                    $perCountry[$cc]['revenue'] += (float) $total;
                }
            }
        }

        // ── 3. Shape output ──────────────────────────────────────────
        $countries = [];
        foreach ($perCountry as $cc => $row) {
            $countries[] = [
                'code'    => $cc,
                'name'    => $this->countryName($cc),
                'flag'    => $this->flagEmoji($cc),
                'users'   => (int) $row['users'],
                'watches' => (int) $row['watches'],
                'revenue' => round((float) $row['revenue'], 2),
            ];
        }

        $totalUsers   = array_sum(array_column($countries, 'users'));
        $totalWatches = array_sum(array_column($countries, 'watches'));
        $totalRevenue = array_sum(array_column($countries, 'revenue'));

        // Pre-compute the three top-20 leaderboards.
        $topByUsers = $this->topN($countries, 'users', self::TOP_N);
        $topByWatches = $this->topN($countries, 'watches', self::TOP_N);
        $topByRevenue = $this->topN($countries, 'revenue', self::TOP_N);

        // The "main" country table is by user count (most useful default).
        usort($countries, static fn ($a, $b) => $b['users'] <=> $a['users']);

        return [
            'stats' => [
                'total_countries' => count($countries),
                'total_users'     => $totalUsers,
                'total_watches'   => $totalWatches,
                'total_revenue'   => round($totalRevenue, 2),
                'resolved_users'  => count($userCountry),
            ],
            'countries'     => $countries,
            'topByUsers'    => $topByUsers,
            'topByWatches'  => $topByWatches,
            'topByRevenue'  => $topByRevenue,
            'computedAt'    => Carbon::now()->toIso8601String(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Sort the country rows by the given key and slice the top N.
     * Returns rows where the metric is > 0 only (no zero-padded noise).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function topN(array $rows, string $key, int $n): array
    {
        $rows = array_values(array_filter($rows, static fn ($r): bool => (float) $r[$key] > 0));
        usort($rows, static fn ($a, $b) => $b[$key] <=> $a[$key]);
        return array_slice($rows, 0, $n);
    }

    /**
     * Validate + upper-case a 2-letter ISO 3166 alpha-2 country code.
     */
    private function normaliseCountry(?string $code): ?string
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
    private function flagEmoji(string $code): string
    {
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '🌐';
        }
        // 'A' = 0x41, regional indicator 'A' = U+1F1E6 = 0x1F1E6
        // offset = 0x1F1E6 - 0x41 = 0x1F1A5
        $first  = mb_chr(ord($code[0]) + 0x1F1A5, 'UTF-8');
        $second = mb_chr(ord($code[1]) + 0x1F1A5, 'UTF-8');
        return $first . $second;
    }

    /**
     * Look up a human name for an ISO country code, with a focus on the
     * Indonesian market. Falls back to the raw code for anything unknown
     * so we never render a blank cell.
     */
    private function countryName(string $code): string
    {
        static $map = [
            'ID' => 'Indonesia',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'TH' => 'Thailand',
            'PH' => 'Philippines',
            'VN' => 'Vietnam',
            'BN' => 'Brunei',
            'KH' => 'Cambodia',
            'LA' => 'Laos',
            'MM' => 'Myanmar',
            'TL' => 'Timor-Leste',
            'US' => 'United States',
            'CA' => 'Canada',
            'MX' => 'Mexico',
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'GB' => 'United Kingdom',
            'IE' => 'Ireland',
            'FR' => 'France',
            'DE' => 'Germany',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'ES' => 'Spain',
            'PT' => 'Portugal',
            'IT' => 'Italy',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czechia',
            'GR' => 'Greece',
            'TR' => 'Turkey',
            'RU' => 'Russia',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'QA' => 'Qatar',
            'KW' => 'Kuwait',
            'BH' => 'Bahrain',
            'OM' => 'Oman',
            'EG' => 'Egypt',
            'MA' => 'Morocco',
            'NG' => 'Nigeria',
            'KE' => 'Kenya',
            'ZA' => 'South Africa',
            'IN' => 'India',
            'PK' => 'Pakistan',
            'BD' => 'Bangladesh',
            'LK' => 'Sri Lanka',
            'NP' => 'Nepal',
            'CN' => 'China',
            'HK' => 'Hong Kong',
            'TW' => 'Taiwan',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'FJ' => 'Fiji',
        ];

        return $map[$code] ?? $code;
    }
}
