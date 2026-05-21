<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\WatchHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement funnel analyzer (D3).
 *
 * Reports the 5-stage acquisition→activation funnel:
 *
 *   1. visited        — distinct visitors in the window. Prefers distinct
 *                       audit-log IPs (more accurate than session count on
 *                       a stateless API), falls back to the `sessions` table
 *                       (database session driver), then to `users` count.
 *   2. registered     — `users` rows created in the window.
 *   3. onboarded      — registered users with a `user_preferences` row.
 *   4. watched_first  — registered users with ≥ 1 `watch_histories` row.
 *   5. subscribed     — registered users with ≥ 1 `subscriptions` row.
 *
 * Stages 3-5 are filtered to the *cohort* from stage 2 (signups in the
 * window), so the funnel reads as "of the users we acquired in the last N
 * days, how many made it to each step?". This is the convention every
 * marketing tool uses (Mixpanel, Amplitude, GA) and avoids the "old users
 * inflating later stages" bug a naive count would have.
 *
 * Output shape (returned from `signupToSubscribed`):
 *   [
 *     [
 *       'stage'            => string, // 'visited' | 'registered' | ...
 *       'label'            => string, // 'Visited', 'Registered', ...
 *       'count'            => int,
 *       'conversion_pct'   => float,  // 0..100, 1 decimal — vs PREVIOUS stage
 *       'from_top_pct'     => float,  // 0..100, 1 decimal — vs Stage 1
 *     ],
 *     ...
 *   ]
 *
 * Portability: every aggregation is built with Eloquent / DB query builder
 * primitives (no DB-specific date functions) so the analyzer runs on
 * SQLite, MySQL, and Postgres without changes.
 */
class FunnelAnalyzer
{
    /** Stable stage keys (used by both the JSON shape and view). */
    public const STAGES = [
        'visited'       => 'Visited',
        'registered'    => 'Registered',
        'onboarded'     => 'Onboarded',
        'watched_first' => 'Watched First',
        'subscribed'    => 'Subscribed',
    ];

    /**
     * Alias for {@see self::signupToSubscribed()} — kept for compatibility
     * with `FunnelDashboardController` which historically called this name.
     * Both call sites resolve to the same payload.
     *
     * @return array<int, array{
     *   stage: string,
     *   label: string,
     *   count: int,
     *   conversion_pct: float,
     *   from_top_pct: float,
     *   percent_from_previous: float,
     *   percent_from_top: float
     * }>
     */
    public function engagementFunnel(int $days = 30): array
    {
        return $this->signupToSubscribed($days);
    }

    /**
     * Build the full Visited → Subscribed funnel for the last `$days` days.
     *
     * Returns both the canonical keys (`conversion_pct`, `from_top_pct`) and
     * legacy aliases (`percent_from_previous`, `percent_from_top`) so older
     * views / callers consuming either shape resolve without breakage.
     *
     * @return array<int, array{
     *   stage: string,
     *   label: string,
     *   count: int,
     *   conversion_pct: float,
     *   from_top_pct: float,
     *   percent_from_previous: float,
     *   percent_from_top: float
     * }>
     */
    public function signupToSubscribed(int $days = 30): array
    {
        $days  = max(1, $days);
        $since = Carbon::now()->subDays($days);

        // ── Stage 2 first — every downstream stage is filtered to this cohort.
        $signupUserIds = User::query()
            ->where('created_at', '>=', $since)
            ->pluck('id')
            ->all();

        $registered = count($signupUserIds);

        // ── Stage 1 (Visited)
        $visited = $this->countVisits($since, fallback: $registered);

        // ── Stage 3 (Onboarded) — UserPreference row exists
        $onboarded = $signupUserIds === []
            ? 0
            : UserPreference::query()
                ->whereIn('user_id', $signupUserIds)
                ->count();

        // ── Stage 4 (Watched First) — distinct user with ≥ 1 watch_history
        $watchedFirst = $signupUserIds === []
            ? 0
            : (int) WatchHistory::query()
                ->whereIn('user_id', $signupUserIds)
                ->distinct()
                ->count('user_id');

        // ── Stage 5 (Subscribed) — distinct user with ≥ 1 subscription
        $subscribed = $signupUserIds === []
            ? 0
            : (int) Subscription::query()
                ->whereIn('user_id', $signupUserIds)
                ->distinct()
                ->count('user_id');

        $raw = [
            ['stage' => 'visited',       'label' => self::STAGES['visited'],       'count' => $visited],
            ['stage' => 'registered',    'label' => self::STAGES['registered'],    'count' => $registered],
            ['stage' => 'onboarded',     'label' => self::STAGES['onboarded'],     'count' => $onboarded],
            ['stage' => 'watched_first', 'label' => self::STAGES['watched_first'], 'count' => $watchedFirst],
            ['stage' => 'subscribed',    'label' => self::STAGES['subscribed'],    'count' => $subscribed],
        ];

        return $this->annotateRatios($raw);
    }

    /**
     * Pinpoint the largest drop-off between consecutive stages.
     *
     * @return array{
     *   from: array{stage:string, label:string, count:int},
     *   to:   array{stage:string, label:string, count:int},
     *   drop_pct: float,
     *   absolute_loss: int
     * }|null
     */
    public function dropoffByStage(int $days = 30): ?array
    {
        $funnel = $this->signupToSubscribed($days);

        $worst = null;
        foreach ($funnel as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $prev = $funnel[$i - 1];

            // Drop = portion of the previous stage we lost.
            $drop = 100.0 - $row['conversion_pct'];
            $loss = max(0, $prev['count'] - $row['count']);

            if ($worst === null || $drop > $worst['drop_pct']) {
                $worst = [
                    'from'          => ['stage' => $prev['stage'], 'label' => $prev['label'], 'count' => $prev['count']],
                    'to'            => ['stage' => $row['stage'],  'label' => $row['label'],  'count' => $row['count']],
                    'drop_pct'      => round($drop, 1),
                    'absolute_loss' => $loss,
                ];
            }
        }

        return $worst;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Count distinct visitors in the window.
     *
     * Order of preference:
     *   1. Distinct `client_ip` from `audit_logs` (most accurate signal we have).
     *   2. Distinct rows in the `sessions` table (database session driver only).
     *   3. Fallback: signup count — keeps the funnel renderable on fresh dev.
     */
    protected function countVisits(Carbon $since, int $fallback): int
    {
        // (1) audit_logs distinct IP — the swarm-20 audit trail covers most
        // user-facing actions, so distinct IPs is a reasonable visitor proxy.
        if (Schema::hasTable('audit_logs')) {
            try {
                $count = (int) AuditLog::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('client_ip')
                    ->distinct()
                    ->count('client_ip');
                if ($count > 0) {
                    return $count;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // (2) Laravel database session driver creates a `sessions` table.
        if (Schema::hasTable('sessions')) {
            try {
                $sinceTs = $since->getTimestamp();
                $count = (int) DB::table('sessions')
                    ->where('last_activity', '>=', $sinceTs)
                    ->distinct()
                    ->count('id');
                if ($count > 0) {
                    return $count;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // (3) Pessimistic fallback so the dashboard always renders.
        return $fallback;
    }

    /**
     * Decorate each stage with `conversion_pct` (vs prev) and `from_top_pct`.
     *
     * @param array<int, array{stage:string, label:string, count:int}> $stages
     * @return array<int, array{
     *   stage:string,
     *   label:string,
     *   count:int,
     *   conversion_pct:float,
     *   from_top_pct:float
     * }>
     */
    protected function annotateRatios(array $stages): array
    {
        $top  = $stages[0]['count'] ?? 0;
        $prev = $top;
        $out  = [];

        foreach ($stages as $i => $row) {
            $count = (int) $row['count'];

            $fromTop = $top > 0 ? round(($count / $top) * 100, 1) : 0.0;
            $convPct = $i === 0
                ? 100.0
                : ($prev > 0 ? round(($count / $prev) * 100, 1) : 0.0);

            $out[] = [
                'stage'                 => $row['stage'],
                'label'                 => $row['label'],
                'count'                 => $count,
                'conversion_pct'        => $convPct,
                'from_top_pct'          => $fromTop,
                // Legacy alias keys consumed by the dashboard view + the
                // drop-off alert loop in FunnelDashboardController. Kept in
                // lockstep with the canonical keys above so existing callers
                // don't need to be updated when we ship new ones.
                'percent_from_previous' => $convPct,
                'percent_from_top'      => $fromTop,
            ];

            $prev = $count;
        }

        return $out;
    }
}
