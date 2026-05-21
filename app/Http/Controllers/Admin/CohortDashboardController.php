<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tasks\CohortInsightGenerator;
use App\Services\Analytics\CohortAnalyzer;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin dashboard: User Cohort Retention Analysis (D2).
 *
 * Visualises retention by signup cohort (weekly or monthly) and asks the
 * configured AI provider to summarise patterns in Indonesian. Heavy paths
 * (cohort matrix build + AI call) are cached for 6 hours so admins clicking
 * around don't keep paying for the same insight.
 *
 * Routes:
 *   GET  /admin/cohorts             → index (?type=weekly|monthly, ?refresh=1)
 *   GET  /admin/cohorts?export=csv  → CSV download of the current matrix
 */
class CohortDashboardController extends Controller
{
    /** Default cohort horizon. */
    protected const DEFAULT_WEEKS = 12;

    protected const DEFAULT_MONTHS = 6;

    /** Cache TTL (seconds) shared by both the matrix and the AI insight. */
    protected const CACHE_TTL = 21600; // 6 hours

    /**
     * GET /admin/cohorts
     */
    public function index(
        Request $request,
        CohortAnalyzer $analyzer,
        CohortInsightGenerator $insighter,
    ): mixed {
        $type = $this->normalizeType($request->input('type'));

        // CSV export uses the same matrix but skips view/AI rendering.
        if ($request->query('export') === 'csv') {
            $matrix = $this->fetchMatrix($analyzer, $type, force: false);

            return $this->exportCsv($matrix, $type);
        }

        $force = $request->boolean('refresh');

        $matrix = $this->fetchMatrix($analyzer, $type, $force);
        $insight = $this->fetchInsight($insighter, $type, $matrix, $force);

        $periodCount = $type === 'monthly' ? self::DEFAULT_MONTHS : self::DEFAULT_WEEKS;

        return view('admin.cohorts.dashboard', [
            'type' => $type,
            'matrix' => $matrix,
            'periodCount' => $periodCount,
            'insight' => $insight,
            'cachedAt' => Cache::get($this->insightStampKey($type)),
            'exportUrl' => url('/admin/cohorts').'?type='.$type.'&export=csv',
            'refreshUrl' => url('/admin/cohorts').'?type='.$type.'&refresh=1',
            'toggleUrl' => fn (string $t): string => url('/admin/cohorts').'?type='.$t,
        ]);
    }

    /**
     * GET /admin/cohorts/export.csv
     *
     * Public-route shim invoked by `admin.cohorts.export`. Builds (or reuses
     * the cached) cohort matrix, audit-logs the export, and streams the
     * CSV via the same helper the inline `?export=csv` query path uses.
     */
    public function export(
        Request $request,
        CohortAnalyzer $analyzer,
        ?AuditLogger $auditLogger = null,
    ): StreamedResponse {
        $type = $this->normalizeType($request->input('type'));
        $matrix = $this->fetchMatrix($analyzer, $type, force: false);

        // Best-effort audit — never block the export on a logging failure.
        try {
            ($auditLogger ?? app(AuditLogger::class))->log(
                action: 'analytics.cohorts.exported',
                subject: null,
                meta: [
                    'type'         => $type,
                    'row_count'    => count($matrix),
                    'period_count' => $type === 'monthly' ? self::DEFAULT_MONTHS : self::DEFAULT_WEEKS,
                ],
            );
        } catch (\Throwable $e) {
            // swallow — exports must not 500 on a logging hiccup.
        }

        return $this->exportCsv($matrix, $type);
    }

    // ── Internals ────────────────────────────────────────────────────────

    protected function fetchMatrix(CohortAnalyzer $analyzer, string $type, bool $force): array
    {
        $key = $this->matrixCacheKey($type);

        if ($force) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL, function () use ($analyzer, $type) {
            return $type === 'monthly'
                ? $analyzer->monthlyCohorts(self::DEFAULT_MONTHS)
                : $analyzer->weeklyCohorts(self::DEFAULT_WEEKS);
        });
    }

    protected function fetchInsight(
        CohortInsightGenerator $insighter,
        string $type,
        array $matrix,
        bool $force,
    ): string {
        $key = $this->insightCacheKey($type);
        $stampKey = $this->insightStampKey($type);

        if ($force) {
            Cache::forget($key);
            Cache::forget($stampKey);
        }

        return Cache::remember($key, self::CACHE_TTL, function () use ($insighter, $matrix, $stampKey) {
            $text = $insighter->generate($matrix);
            Cache::put($stampKey, now()->toDateTimeString(), self::CACHE_TTL);

            return $text;
        });
    }

    protected function normalizeType(mixed $raw): string
    {
        $raw = is_string($raw) ? strtolower(trim($raw)) : '';

        return $raw === 'monthly' ? 'monthly' : 'weekly';
    }

    protected function matrixCacheKey(string $type): string
    {
        return 'admin.cohorts.matrix.'.$type.'.v1';
    }

    protected function insightCacheKey(string $type): string
    {
        return 'admin.cohorts.insight.'.$type.'.v1';
    }

    protected function insightStampKey(string $type): string
    {
        return 'admin.cohorts.insight.'.$type.'.generated_at.v1';
    }

    /**
     * Stream the cohort matrix as a CSV download (one row per cohort).
     */
    protected function exportCsv(array $matrix, string $type): StreamedResponse
    {
        $filename = 'cohort-retention-'.$type.'-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache',
        ];

        $periodCount = $type === 'monthly' ? self::DEFAULT_MONTHS : self::DEFAULT_WEEKS;
        $periodPrefix = $type === 'monthly' ? 'm' : 'w';
        $cohortKey = $type === 'monthly' ? 'cohort_month_start' : 'cohort_week_start';

        return response()->streamDownload(function () use ($matrix, $periodCount, $periodPrefix, $cohortKey) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel reads the file without re-prompting.
            fwrite($out, "\xEF\xBB\xBF");

            $header = ['cohort_start', 'label', 'signup_count'];
            for ($p = 0; $p < $periodCount; $p++) {
                $header[] = $periodPrefix.$p.'_active';
                $header[] = $periodPrefix.$p.'_pct';
            }
            fputcsv($out, $header);

            foreach ($matrix as $row) {
                $line = [
                    $row[$cohortKey] ?? '',
                    $row['label'] ?? '',
                    $row['signup_count'] ?? 0,
                ];
                $retention = $row['retention'] ?? [];
                $byPeriod = [];
                foreach ($retention as $point) {
                    $byPeriod[(int) ($point['period'] ?? -1)] = $point;
                }
                for ($p = 0; $p < $periodCount; $p++) {
                    $point = $byPeriod[$p] ?? null;
                    $line[] = $point['active'] ?? '';
                    $line[] = $point !== null && $point['pct'] !== null
                        ? number_format((float) $point['pct'], 1, '.', '')
                        : '';
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, $headers);
    }
}
