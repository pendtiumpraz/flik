<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Doctor\HealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * HealthDashboardController
 * --------------------------------------------------------------------------
 * Backs /admin/health. Two endpoints:
 *
 *   GET /admin/health                  — full dashboard render (all sections)
 *   GET /admin/health/check/{section}  — JSON for one category, used by the
 *                                        Alpine auto-refresh poller in the
 *                                        dashboard view
 *
 * Authorization: per-route via `can:system.health`. Permission falls back to
 * the legacy `admin` gate when the new permission isn't yet seeded (see
 * AuthServiceProvider's Gate::before fallback).
 */
class HealthDashboardController extends Controller
{
    public function __construct(private HealthChecker $checker) {}

    public function index(Request $request): View
    {
        $quick = (bool) $request->boolean('quick');
        $results = $this->checker->runAll($quick);
        $summary = $this->checker->summarise($results);

        return view('admin.health.dashboard', [
            'title'   => 'Health Dashboard',
            'results' => $results,
            'summary' => $summary,
            'quick'   => $quick,
        ]);
    }

    /**
     * JSON for a single category. The dashboard view polls this every
     * 60 s per visible card so the operator gets near-real-time signal
     * without re-running the entire suite each time.
     */
    public function runCheck(Request $request, string $section): JsonResponse
    {
        $allowedSections = [
            'system', 'database', 'storage', 'cache', 'queue', 'mail',
            'redis', 'disks', 'ai', 'security', 'cron', 'external', 'pwa',
        ];
        if (! in_array($section, $allowedSections, true)) {
            return response()->json([
                'error' => 'unknown_section',
                'allowed' => $allowedSections,
            ], 404);
        }

        $quick = (bool) $request->boolean('quick', true);
        $results = $this->checker->runAll($quick, $section);
        $checks = $results[$section] ?? [];

        // Per-card summary lets the front-end colour the header pill
        // without re-running the aggregate.
        $cardSummary = $this->checker->summarise([$section => $checks]);

        return response()->json([
            'section'      => $section,
            'generated_at' => now()->toIso8601String(),
            'checks'       => $checks,
            'summary'      => $cardSummary,
        ]);
    }
}
