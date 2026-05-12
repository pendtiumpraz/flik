<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Ai\AiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cohort retention analyzer (D2).
 *
 * Buckets users by their signup week (or month) and reports the share of each
 * cohort that returned to watch something in week 0, week 1, ..., week N
 * (or month 0, ..., month N) since signup.
 *
 * "Active" is defined as having at least one row in `watch_histories` whose
 * `last_watched_at` (falling back to `updated_at`) lands inside the period
 * being measured. We do not require subscription — engagement is the signal.
 *
 * Output shape (both `weeklyCohorts` and `monthlyCohorts`):
 *   [
 *     [
 *       'cohort_week_start' => 'YYYY-MM-DD',   // or 'cohort_month_start'
 *       'label'             => 'Wk 2026-05-04' // or 'May 2026'
 *       'signup_count'      => int,
 *       'retention'         => [               // length = periods (incl. w0/m0)
 *           ['period' => 0, 'active' => int, 'pct' => float],
 *           ...
 *       ],
 *     ],
 *     ...
 *   ]
 *
 * Cohorts are returned newest-first so the dashboard's first row is the most
 * recent signup bucket (oldest = bottom row). Reverse in the view if you want
 * the classic "stairstep" triangle shape.
 *
 * Portability note: all bucketing happens in PHP (Carbon::startOfWeek/startOfMonth)
 * so the analyzer works identically on SQLite, MySQL, and Postgres without
 * driver-specific date functions.
 */
class CohortAnalyzer
{
    /** Carbon::WEEK_STARTS_AT is Monday by default — make it explicit. */
    protected const WEEK_STARTS_AT = Carbon::MONDAY;

    /**
     * Inactivity window (days) used by churnByCohort() to flag a user as
     * "inactive" — no watch_history activity in the trailing N days.
     */
    protected const CHURN_INACTIVITY_DAYS = 14;

    /**
     * Hard cap on cohorts forwarded to the AI insight prompt. Keeps token
     * spend bounded even when the caller asks for a long horizon.
     */
    protected const AI_MAX_COHORTS = 12;

    /**
     * AI client is optional — only required when callers invoke aiInsight().
     * Leaving it nullable means existing call sites that just want the matrix
     * (and the cron / queue tooling) don't need to bind a provider.
     */
    public function __construct(
        protected ?AiClient $ai = null,
    ) {}

    /**
     * Weekly cohort retention matrix.
     *
     * @param  int  $weeksBack  Number of cohort weeks to include (default 12).
     *                          Also caps the retention horizon at week N where
     *                          N = weeksBack - 1.
     * @return array<int,array{cohort_week_start:string,label:string,size:int,signup_count:int,retention:array<int,array{period:int,active:int,pct:float|null}>,retention_pct:array<int,float|null>}>
     */
    public function weeklyCohorts(int $weeksBack = 12): array
    {
        $weeks = max(1, $weeksBack);
        $now = Carbon::now();
        $thisWeekStart = $now->copy()->startOfWeek(self::WEEK_STARTS_AT);

        // Build the list of cohort buckets we care about (oldest → newest).
        $cohortStarts = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $cohortStarts[] = $thisWeekStart->copy()->subWeeks($i);
        }

        $oldestCohortStart = $cohortStarts[0];

        // Pull all users that signed up within the analysis window.
        // We need (id, created_at) to bucket by signup week.
        $users = DB::table('users')
            ->select('id', 'created_at')
            ->where('created_at', '>=', $oldestCohortStart)
            ->where('created_at', '<', $thisWeekStart->copy()->addWeek())
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return $this->emptyMatrix($cohortStarts, 'week');
        }

        // user_id → cohort_week_start (Carbon)
        $userCohort = [];
        // cohort_week_start ISO → [user_id => true]
        $cohortMembers = [];

        foreach ($users as $u) {
            $createdAt = Carbon::parse($u->created_at);
            $bucketStart = $createdAt->copy()->startOfWeek(self::WEEK_STARTS_AT);
            $key = $bucketStart->toDateString();
            $userCohort[(int) $u->id] = $bucketStart;
            $cohortMembers[$key] ??= [];
            $cohortMembers[$key][(int) $u->id] = true;
        }

        // Pull watch activity for those users since the oldest cohort start.
        $userIds = array_keys($userCohort);
        $activity = DB::table('watch_histories')
            ->select('user_id', 'last_watched_at', 'updated_at')
            ->whereIn('user_id', $userIds)
            ->where(function ($q) use ($oldestCohortStart) {
                $q->where('last_watched_at', '>=', $oldestCohortStart)
                  ->orWhere('updated_at', '>=', $oldestCohortStart);
            })
            ->orderBy('id')
            ->get();

        // cohort_key → period_index → set of active user_ids
        $activeMatrix = [];
        foreach ($activity as $row) {
            $uid = (int) $row->user_id;
            $cohortStart = $userCohort[$uid] ?? null;
            if ($cohortStart === null) {
                continue;
            }
            $rawTs = $row->last_watched_at ?? $row->updated_at;
            if ($rawTs === null) {
                continue;
            }
            $watchedAt = Carbon::parse($rawTs);
            if ($watchedAt->lt($cohortStart)) {
                continue;
            }
            $period = (int) floor($cohortStart->diffInDays($watchedAt) / 7);
            if ($period < 0 || $period >= $weeks) {
                continue;
            }
            $ck = $cohortStart->toDateString();
            $activeMatrix[$ck][$period][$uid] = true;
        }

        return $this->assemble(
            cohortStarts: $cohortStarts,
            cohortMembers: $cohortMembers,
            activeMatrix: $activeMatrix,
            periods: $weeks,
            now: $now,
            granularity: 'week',
        );
    }

    /**
     * Monthly cohort retention matrix.
     *
     * @return array<int,array{cohort_month_start:string,label:string,signup_count:int,retention:array<int,array{period:int,active:int,pct:float}>}>
     */
    public function monthlyCohorts(int $months = 6): array
    {
        $months = max(1, $months);
        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth();

        $cohortStarts = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $cohortStarts[] = $thisMonthStart->copy()->subMonthsNoOverflow($i);
        }

        $oldestCohortStart = $cohortStarts[0];
        $endExclusive = $thisMonthStart->copy()->addMonthNoOverflow();

        $users = DB::table('users')
            ->select('id', 'created_at')
            ->where('created_at', '>=', $oldestCohortStart)
            ->where('created_at', '<', $endExclusive)
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return $this->emptyMatrix($cohortStarts, 'month');
        }

        $userCohort = [];
        $cohortMembers = [];

        foreach ($users as $u) {
            $createdAt = Carbon::parse($u->created_at);
            $bucketStart = $createdAt->copy()->startOfMonth();
            $key = $bucketStart->toDateString();
            $userCohort[(int) $u->id] = $bucketStart;
            $cohortMembers[$key] ??= [];
            $cohortMembers[$key][(int) $u->id] = true;
        }

        $userIds = array_keys($userCohort);
        $activity = DB::table('watch_histories')
            ->select('user_id', 'last_watched_at', 'updated_at')
            ->whereIn('user_id', $userIds)
            ->where(function ($q) use ($oldestCohortStart) {
                $q->where('last_watched_at', '>=', $oldestCohortStart)
                  ->orWhere('updated_at', '>=', $oldestCohortStart);
            })
            ->orderBy('id')
            ->get();

        $activeMatrix = [];
        foreach ($activity as $row) {
            $uid = (int) $row->user_id;
            $cohortStart = $userCohort[$uid] ?? null;
            if ($cohortStart === null) {
                continue;
            }
            $rawTs = $row->last_watched_at ?? $row->updated_at;
            if ($rawTs === null) {
                continue;
            }
            $watchedAt = Carbon::parse($rawTs);
            if ($watchedAt->lt($cohortStart)) {
                continue;
            }
            // Whole-month delta (e.g. cohort Mar → watch in Mar = 0, in Apr = 1).
            $period = $this->monthsBetween($cohortStart, $watchedAt);
            if ($period < 0 || $period >= $months) {
                continue;
            }
            $ck = $cohortStart->toDateString();
            $activeMatrix[$ck][$period][$uid] = true;
        }

        return $this->assemble(
            cohortStarts: $cohortStarts,
            cohortMembers: $cohortMembers,
            activeMatrix: $activeMatrix,
            periods: $months,
            now: $now,
            granularity: 'month',
        );
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Build the final newest-first matrix from the bucketing results.
     *
     * @param  array<int,Carbon>  $cohortStarts             oldest → newest
     * @param  array<string,array<int,bool>>  $cohortMembers
     * @param  array<string,array<int,array<int,bool>>>  $activeMatrix
     * @return array<int,array<string,mixed>>
     */
    protected function assemble(
        array $cohortStarts,
        array $cohortMembers,
        array $activeMatrix,
        int $periods,
        Carbon $now,
        string $granularity,
    ): array {
        $rows = [];
        $startKey = $granularity === 'week' ? 'cohort_week_start' : 'cohort_month_start';

        foreach ($cohortStarts as $cohortStart) {
            $ck = $cohortStart->toDateString();
            $members = $cohortMembers[$ck] ?? [];
            $signupCount = count($members);

            // Cap retention horizon so we don't show projection into the future.
            $maxPeriodReached = $granularity === 'week'
                ? (int) floor($cohortStart->diffInDays($now) / 7)
                : $this->monthsBetween($cohortStart, $now);

            $retention = [];
            for ($p = 0; $p < $periods; $p++) {
                if ($p > $maxPeriodReached) {
                    // Future period — leave blank rather than emit a misleading 0%.
                    $retention[] = [
                        'period' => $p,
                        'active' => 0,
                        'pct' => null,
                    ];
                    continue;
                }
                $activeSet = $activeMatrix[$ck][$p] ?? [];
                $active = count($activeSet);
                $pct = $signupCount > 0
                    ? round(($active / $signupCount) * 100, 1)
                    : 0.0;
                $retention[] = [
                    'period' => $p,
                    'active' => $active,
                    'pct' => $pct,
                ];
            }

            $rows[] = [
                $startKey => $ck,
                'label' => $this->cohortLabel($cohortStart, $granularity),
                // `size` is the spec-friendly alias for `signup_count` — both
                // are emitted so existing callers and the new D2 spec are happy.
                'size' => $signupCount,
                'signup_count' => $signupCount,
                'retention' => $retention,
                'retention_pct' => array_map(
                    static fn (array $point) => $point['pct'],
                    $retention,
                ),
            ];
        }

        // Newest cohort first — easier to scan in the dashboard.
        return array_reverse($rows);
    }

    /**
     * @param  array<int,Carbon>  $cohortStarts
     * @return array<int,array<string,mixed>>
     */
    protected function emptyMatrix(array $cohortStarts, string $granularity): array
    {
        $startKey = $granularity === 'week' ? 'cohort_week_start' : 'cohort_month_start';
        $periods = count($cohortStarts);

        $rows = [];
        foreach ($cohortStarts as $cohortStart) {
            $retention = [];
            for ($p = 0; $p < $periods; $p++) {
                $retention[] = ['period' => $p, 'active' => 0, 'pct' => 0.0];
            }
            $rows[] = [
                $startKey => $cohortStart->toDateString(),
                'label' => $this->cohortLabel($cohortStart, $granularity),
                'size' => 0,
                'signup_count' => 0,
                'retention' => $retention,
                'retention_pct' => array_fill(0, $periods, 0.0),
            ];
        }

        return array_reverse($rows);
    }

    protected function cohortLabel(Carbon $start, string $granularity): string
    {
        return $granularity === 'week'
            ? 'Wk '.$start->format('Y-m-d')
            : $start->format('M Y');
    }

    /**
     * Whole-month difference between two Carbon instances (always >= 0).
     */
    protected function monthsBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lt($from)) {
            return -1;
        }
        $years = (int) $to->format('Y') - (int) $from->format('Y');
        $months = (int) $to->format('n') - (int) $from->format('n');

        return $years * 12 + $months;
    }

    // ── Churn ───────────────────────────────────────────────────────────

    /**
     * Per-cohort churn ratio.
     *
     * A cohort member is "churned" when EITHER:
     *   1. They have no `watch_histories` activity in the trailing
     *      {@see self::CHURN_INACTIVITY_DAYS} days (default 14), OR
     *   2. They had a subscription whose `ends_at` is in the past (i.e. it
     *      lapsed and was not renewed). We only count this leg when the
     *      `subscriptions` table is present, so the analyzer keeps working
     *      on minimal/dev installs.
     *
     * @param  int  $weeksBack  Number of cohort weeks to summarise (default 12).
     * @return array<int,array{
     *   cohort_week_start:string,
     *   label:string,
     *   size:int,
     *   churned:int,
     *   active:int,
     *   churn_pct:float
     * }>
     */
    public function churnByCohort(int $weeksBack = 12): array
    {
        $weeks = max(1, $weeksBack);
        $now = Carbon::now();
        $thisWeekStart = $now->copy()->startOfWeek(self::WEEK_STARTS_AT);
        $oldestStart = $thisWeekStart->copy()->subWeeks($weeks - 1);
        $inactivityCutoff = $now->copy()->subDays(self::CHURN_INACTIVITY_DAYS);

        $cohortStarts = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $cohortStarts[] = $thisWeekStart->copy()->subWeeks($i);
        }

        $users = DB::table('users')
            ->select('id', 'created_at')
            ->where('created_at', '>=', $oldestStart)
            ->where('created_at', '<', $thisWeekStart->copy()->addWeek())
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return $this->emptyChurnMatrix($cohortStarts);
        }

        // Bucket users by signup week.
        $userCohort = [];
        $cohortMembers = [];
        foreach ($users as $u) {
            $bucketStart = Carbon::parse($u->created_at)->startOfWeek(self::WEEK_STARTS_AT);
            $key = $bucketStart->toDateString();
            $userCohort[(int) $u->id] = $bucketStart;
            $cohortMembers[$key] ??= [];
            $cohortMembers[$key][(int) $u->id] = true;
        }

        $userIds = array_keys($userCohort);

        // Leg 1: latest watch activity timestamp per user (max of last_watched_at / updated_at).
        $latestActivity = $this->latestWatchActivityFor($userIds);

        // Leg 2: subscriptions that have already ended (and were not replaced
        // by a still-running active row). Only consulted when the table exists.
        $expiredOnlyUserIds = $this->usersWithOnlyExpiredSubscriptions($userIds, $now);

        // Tally.
        $rows = [];
        foreach ($cohortStarts as $cohortStart) {
            $key = $cohortStart->toDateString();
            $members = array_keys($cohortMembers[$key] ?? []);
            $size = count($members);
            $churned = 0;

            foreach ($members as $uid) {
                $lastSeen = $latestActivity[$uid] ?? null;
                $inactive = $lastSeen === null || $lastSeen->lt($inactivityCutoff);
                $subEnded = isset($expiredOnlyUserIds[$uid]);
                if ($inactive || $subEnded) {
                    $churned++;
                }
            }

            $churnPct = $size > 0 ? round(($churned / $size) * 100, 1) : 0.0;

            $rows[] = [
                'cohort_week_start' => $key,
                'label' => $this->cohortLabel($cohortStart, 'week'),
                'size' => $size,
                'churned' => $churned,
                'active' => max(0, $size - $churned),
                'churn_pct' => $churnPct,
            ];
        }

        // Newest cohort first — matches the orientation of weeklyCohorts().
        return array_reverse($rows);
    }

    /**
     * @param  array<int,int>  $userIds
     * @return array<int,Carbon>  user_id → most-recent activity Carbon
     */
    protected function latestWatchActivityFor(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('watch_histories')
            ->select('user_id', 'last_watched_at', 'updated_at')
            ->whereIn('user_id', $userIds)
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $ts = $row->last_watched_at ?? $row->updated_at;
            if ($ts === null) {
                continue;
            }
            $when = Carbon::parse($ts);
            if (!isset($latest[$uid]) || $when->gt($latest[$uid])) {
                $latest[$uid] = $when;
            }
        }

        return $latest;
    }

    /**
     * Find user_ids whose only subscription rows have already ended (i.e.
     * `ends_at < now`). Users with at least one still-running active row are
     * excluded (they're not subscription-churned). Returns a hash set
     * (user_id => true) for O(1) lookup.
     *
     * Returns an empty array when the `subscriptions` table is absent so the
     * churn calculation degrades gracefully on minimal installs.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,true>
     */
    protected function usersWithOnlyExpiredSubscriptions(array $userIds, Carbon $now): array
    {
        if (empty($userIds) || !Schema::hasTable('subscriptions')) {
            return [];
        }

        try {
            $rows = DB::table('subscriptions')
                ->select('user_id', 'status', 'ends_at')
                ->whereIn('user_id', $userIds)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        // user_id => ['has_active' => bool, 'has_expired' => bool]
        $state = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $state[$uid] ??= ['has_active' => false, 'has_expired' => false];

            $endsAt = $row->ends_at ? Carbon::parse($row->ends_at) : null;
            $isActive = $row->status === 'active' && ($endsAt === null || $endsAt->gt($now));

            if ($isActive) {
                $state[$uid]['has_active'] = true;
            } elseif ($endsAt !== null && $endsAt->lt($now)) {
                $state[$uid]['has_expired'] = true;
            }
        }

        $expired = [];
        foreach ($state as $uid => $s) {
            if ($s['has_expired'] && !$s['has_active']) {
                $expired[$uid] = true;
            }
        }

        return $expired;
    }

    /**
     * @param  array<int,Carbon>  $cohortStarts
     * @return array<int,array<string,mixed>>
     */
    protected function emptyChurnMatrix(array $cohortStarts): array
    {
        $rows = [];
        foreach ($cohortStarts as $cohortStart) {
            $rows[] = [
                'cohort_week_start' => $cohortStart->toDateString(),
                'label' => $this->cohortLabel($cohortStart, 'week'),
                'size' => 0,
                'churned' => 0,
                'active' => 0,
                'churn_pct' => 0.0,
            ];
        }

        return array_reverse($rows);
    }

    // ── AI Narrative ────────────────────────────────────────────────────

    /**
     * Send the cohort matrix to the active AI provider for a short narrative.
     *
     * Indonesian, ~200 words, identifies trends + recommends 3 actions.
     * Returns a deterministic Indonesian fallback when no provider is wired,
     * the matrix is empty, or the AI call fails — never throws.
     *
     * @param  array<int,array<string,mixed>>  $cohortData  output of weeklyCohorts() / monthlyCohorts() / churnByCohort()
     */
    public function aiInsight(array $cohortData): string
    {
        if (empty($cohortData)) {
            return 'Belum ada data cohort yang cukup untuk dianalisis. Tunggu hingga ada pengguna baru terdaftar dan menonton.';
        }

        if ($this->ai === null) {
            return 'AI insight tidak tersedia: AiClient belum di-bind. Konfigurasi provider di /admin/ai-settings.';
        }

        // Compact each cohort to just the numbers the model needs — drop
        // anything tokens would be wasted on (raw user lists, etc.).
        $compact = [];
        foreach (array_slice($cohortData, 0, self::AI_MAX_COHORTS) as $row) {
            $start = $row['cohort_week_start']
                ?? $row['cohort_month_start']
                ?? ($row['label'] ?? 'unknown');

            $entry = [
                'cohort_start' => $start,
                'label' => $row['label'] ?? $start,
                'size' => (int) ($row['size'] ?? $row['signup_count'] ?? 0),
            ];

            // Retention payload (preferred) — array of pct numbers.
            if (isset($row['retention_pct']) && is_array($row['retention_pct'])) {
                $entry['retention_pct'] = $row['retention_pct'];
            } elseif (isset($row['retention']) && is_array($row['retention'])) {
                $entry['retention_pct'] = array_map(
                    static fn ($p) => $p['pct'] ?? null,
                    $row['retention'],
                );
            }

            // Churn payload — surfaced when caller passed churnByCohort() output.
            if (isset($row['churn_pct'])) {
                $entry['churn_pct'] = (float) $row['churn_pct'];
            }

            $compact[] = $entry;
        }

        $system = 'Anda adalah analis pertumbuhan & retensi untuk FLiK, layanan streaming film Indonesia. '
            .'Anda menerima matriks retensi cohort. Jawab dalam Bahasa Indonesia, '
            .'paragraf prosa (TANPA markdown fence, TANPA JSON), maksimum 200 kata.';

        $user = "Diberikan matriks retensi cohort berikut, identifikasi tren. "
            ."Kenapa cohort tertentu retensinya lebih baik? Rekomendasikan 3 aksi konkret.\n\n"
            ."Aturan keluaran:\n"
            ."- Bahasa Indonesia, ~200 kata, natural & ringkas.\n"
            ."- Sebut angka konkret dari data (cth: \"Cohort 2026-05-04 turun 100% → 32% di W1\").\n"
            ."- Nilai null/kosong artinya periode belum terjadi — abaikan.\n"
            ."- Akhiri dengan 3 rekomendasi bernomor (1. ... 2. ... 3. ...).\n\n"
            ."Data cohort:\n```json\n"
            .json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n```";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                options: ['max_tokens' => 600, 'temperature' => 0.6],
                taskType: 'analytics.cohort_insight',
            );

            $text = trim((string) ($response['content'] ?? ''));
            if ($text === '') {
                return 'Analisis AI tidak tersedia saat ini. Silakan coba lagi nanti.';
            }

            // Strip any accidental markdown fence the model may have added.
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/im', '', $text) ?? $text;

            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('CohortAnalyzer::aiInsight AI call failed', [
                'error' => $e->getMessage(),
            ]);

            return 'Analisis AI tidak tersedia saat ini ('.Str::limit($e->getMessage(), 120).').';
        }
    }
}
