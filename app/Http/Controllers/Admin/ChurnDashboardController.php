<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChurnPrediction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin dashboard for the ChurnPredictor output.
 *
 * Read-only view over the `churn_predictions` table:
 *   - Aggregated counts per risk_level (low / medium / high / critical)
 *   - Top-N at-risk users with risk_score, signals, suggested_action
 *   - Optional ?level= filter
 *   - ?export=csv streams the same filtered list as a CSV download
 *
 * The dashboard never recomputes scores — that's the predictor's job
 * (run `php artisan flik:churn:predict` daily). If a row is missing for a
 * user it just doesn't show up here.
 *
 * Route convention (add to routes/web.php under the admin group):
 *   Route::get('/churn', [\App\Http\Controllers\Admin\ChurnDashboardController::class, 'index'])
 *       ->name('churn.index');
 */
class ChurnDashboardController extends Controller
{
    /**
     * Cap on the at-risk users table size — the dashboard is for triage,
     * not for browsing the full base.
     */
    protected const TABLE_LIMIT = 50;

    /**
     * Hard cap on CSV export rows so a typo can't pull the whole table.
     */
    protected const EXPORT_LIMIT = 5000;

    public function index(Request $request): mixed
    {
        $level = $this->normalizeLevel($request->query('level'));

        // CSV export short-circuits the view render.
        if ($request->query('export') === 'csv') {
            return $this->exportCsv($level);
        }

        $stats     = $this->computeStats();
        $predictions = $this->buildTable($level, self::TABLE_LIMIT);
        $lastRun   = ChurnPrediction::max('computed_at');

        return view('admin.churn.dashboard', [
            'stats'        => $stats,
            'predictions'  => $predictions,
            'activeLevel'  => $level,
            'levels'       => ChurnPrediction::LEVELS,
            'lastRun'      => $lastRun ? Carbon::parse($lastRun) : null,
            'tableLimit'   => self::TABLE_LIMIT,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Aggregate counts + averages.
     *
     * @return array{
     *   total:int, low:int, medium:int, high:int, critical:int,
     *   at_risk:int, at_risk_pct:float, avg_score:?float
     * }
     */
    protected function computeStats(): array
    {
        $row = ChurnPrediction::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN risk_level = 'low'      THEN 1 ELSE 0 END) AS low_count")
            ->selectRaw("SUM(CASE WHEN risk_level = 'medium'   THEN 1 ELSE 0 END) AS medium_count")
            ->selectRaw("SUM(CASE WHEN risk_level = 'high'     THEN 1 ELSE 0 END) AS high_count")
            ->selectRaw("SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) AS critical_count")
            ->selectRaw('AVG(risk_score) AS avg_score')
            ->first();

        $total    = (int) ($row->total ?? 0);
        $low      = (int) ($row->low_count ?? 0);
        $medium   = (int) ($row->medium_count ?? 0);
        $high     = (int) ($row->high_count ?? 0);
        $critical = (int) ($row->critical_count ?? 0);
        $atRisk   = $high + $critical;

        return [
            'total'       => $total,
            'low'         => $low,
            'medium'      => $medium,
            'high'        => $high,
            'critical'    => $critical,
            'at_risk'     => $atRisk,
            'at_risk_pct' => $total > 0 ? round(($atRisk / $total) * 100, 1) : 0.0,
            'avg_score'   => $row->avg_score !== null ? round((float) $row->avg_score, 3) : null,
        ];
    }

    /**
     * Top N predictions, optionally filtered by level, ordered by risk desc.
     *
     * Eager-loads the user so the view doesn't N+1.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ChurnPrediction>
     */
    protected function buildTable(?string $level, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        $q = ChurnPrediction::query()
            ->with('user:id,name,email')
            ->orderedByRisk()
            ->limit($limit);

        if ($level !== null) {
            $q->level($level);
        } else {
            // Default view = "show me anything I should worry about" — i.e. drop the lows.
            $q->whereIn('risk_level', [
                ChurnPrediction::LEVEL_MEDIUM,
                ChurnPrediction::LEVEL_HIGH,
                ChurnPrediction::LEVEL_CRITICAL,
            ]);
        }

        return $q->get();
    }

    /**
     * Stream the filtered prediction list as CSV.
     */
    protected function exportCsv(?string $level): StreamedResponse
    {
        $filename = 'churn-predictions-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
        ];

        $query = ChurnPrediction::query()
            ->with('user:id,name,email')
            ->orderedByRisk()
            ->limit(self::EXPORT_LIMIT);

        if ($level !== null) {
            $query->level($level);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // BOM so Excel reads UTF-8 cleanly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'user_id',
                'name',
                'email',
                'risk_score',
                'risk_level',
                'days_since_last_watch',
                'subscription_status',
                'subscription_expires_in_days',
                'watch_count_this_week',
                'watch_count_last_week',
                'ratings_last_30_days',
                'watchlist_unused_count',
                'suggested_action',
                'computed_at',
            ]);

            $query->chunkById(500, function ($chunk) use ($out) {
                foreach ($chunk as $p) {
                    /** @var ChurnPrediction $p */
                    $signals = is_array($p->signals) ? $p->signals : [];
                    fputcsv($out, [
                        $p->user_id,
                        $p->user?->name ?? '',
                        $p->user?->email ?? '',
                        number_format((float) $p->risk_score, 3, '.', ''),
                        $p->risk_level,
                        $signals['days_since_last_watch']        ?? '',
                        $signals['subscription_status']          ?? '',
                        $signals['subscription_expires_in_days'] ?? '',
                        $signals['watch_count_this_week']        ?? '',
                        $signals['watch_count_last_week']        ?? '',
                        $signals['ratings_last_30_days']         ?? '',
                        $signals['watchlist_unused_count']       ?? '',
                        (string) ($p->suggested_action ?? ''),
                        optional($p->computed_at)->toIso8601String() ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Validate the ?level= query string against the enum.
     */
    protected function normalizeLevel(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $raw = strtolower(trim($raw));
        return in_array($raw, ChurnPrediction::LEVELS, true) ? $raw : null;
    }
}
