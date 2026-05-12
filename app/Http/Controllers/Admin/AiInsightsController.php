<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tasks\ContentGapAnalyzer;
use App\Services\Ai\Tasks\PricingOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Admin AI Insights — read-only dashboards backed by ContentGapAnalyzer (D15)
 * and PricingOptimizer (D5).
 *
 * Both endpoints are heavy and call paid AI providers, so results are cached
 * for 24 hours. Append `?refresh=1` to force a recompute (admin-only routes
 * already gate this with the `can:admin` middleware in routes/web.php).
 */
class AiInsightsController extends Controller
{
    /** Cache TTL for insight reports (seconds). */
    protected const CACHE_TTL = 86400; // 24 hours

    protected const CACHE_KEY_CONTENT_GAP = 'admin.insights.content_gap.v1';

    protected const CACHE_KEY_PRICING = 'admin.insights.pricing.v1';

    /**
     * GET /admin/insights/content-gap
     */
    public function contentGap(Request $request, ContentGapAnalyzer $analyzer): View
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY_CONTENT_GAP);
        }

        $report = Cache::remember(
            self::CACHE_KEY_CONTENT_GAP,
            self::CACHE_TTL,
            fn () => $analyzer->analyze(),
        );

        return view('admin.insights.content-gap', [
            'report' => $report,
            'cachedAt' => $report['generated_at'] ?? null,
        ]);
    }

    /**
     * GET /admin/insights/pricing
     */
    public function pricing(Request $request, PricingOptimizer $optimizer): View
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY_PRICING);
        }

        $report = Cache::remember(
            self::CACHE_KEY_PRICING,
            self::CACHE_TTL,
            fn () => $optimizer->analyze(),
        );

        return view('admin.insights.pricing', [
            'report' => $report,
            'cachedAt' => $report['generated_at'] ?? null,
        ]);
    }
}
