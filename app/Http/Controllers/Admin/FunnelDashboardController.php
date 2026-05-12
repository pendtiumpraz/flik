<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\FunnelAnalyzer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin dashboard for the 6-stage engagement funnel (D3).
 *
 * Read-only — all aggregation lives in {@see FunnelAnalyzer}. Accepts a
 * `?days=` query string (clamped 1..365, default 30) so PMs can compare
 * 7d / 30d / 90d windows from the same URL.
 *
 * Route convention (already wired in routes/web.php):
 *   GET /admin/funnel → name('admin.funnel.index')
 */
class FunnelDashboardController extends Controller
{
    /** Allowed values for the window selector chips in the view. */
    public const WINDOW_OPTIONS = [7, 14, 30, 60, 90];

    public function __construct(
        protected FunnelAnalyzer $analyzer,
    ) {}

    public function index(Request $request): View
    {
        $days = $this->normalizeDays($request->query('days'));
        $funnel = $this->analyzer->engagementFunnel($days);

        // Pre-compute drop-off alerts so the view stays presentational.
        // "Drop > 50%" between consecutive stages → red flag.
        $alerts = [];
        foreach ($funnel as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $drop = 100.0 - $row['percent_from_previous'];
            if ($drop > 50.0) {
                $alerts[] = [
                    'stage'      => $row['stage'],
                    'label'      => $row['label'],
                    'drop'       => round($drop, 1),
                    'from'       => $funnel[$i - 1]['label'],
                    'from_count' => $funnel[$i - 1]['count'],
                    'to_count'   => $row['count'],
                ];
            }
        }

        return view('admin.funnel.dashboard', [
            'funnel'         => $funnel,
            'alerts'         => $alerts,
            'days'           => $days,
            'windowOptions'  => self::WINDOW_OPTIONS,
            'topCount'       => $funnel[0]['count'] ?? 0,
            'bottomCount'    => end($funnel)['count'] ?? 0,
        ]);
    }

    /**
     * Clamp `?days=` to a sane integer in [1, 365], default 30.
     */
    protected function normalizeDays(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 30;
        }
        $days = (int) $raw;
        if ($days < 1) {
            return 30;
        }
        return min($days, 365);
    }
}
