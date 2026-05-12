<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Performance\PerformanceMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Admin Performance Dashboard.
 *
 * Aggregates PerformanceMonitor outputs and renders /admin/performance.
 * The aggregate is cached for 5 minutes; the "Refresh" button on the view
 * sends `?refresh=1` to bust the cache.
 */
class PerformanceDashboardController extends Controller
{
    private const CACHE_KEY = 'admin:performance:dashboard:v1';
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __construct(private readonly PerformanceMonitor $monitor)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY);
            return redirect()->route('admin.performance.index');
        }

        $hours = (int) $request->integer('hours', 24);
        if ($hours < 1 || $hours > 720) {
            $hours = 24;
        }

        $cacheKey = self::CACHE_KEY . ':' . $hours;

        /** @var array<string,mixed> $data */
        $data = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($hours): array {
            return [
                'ai'           => $this->monitor->aiServiceStats($hours),
                'queue'        => $this->monitor->queueLag(),
                'cache_stats'  => $this->monitor->cacheStats(),
                'db'           => $this->monitor->dbStats(),
                'slow_queries' => $this->monitor->slowQueries(20),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return view('admin.performance.dashboard', [
            'ai'            => $data['ai'],
            'queue'         => $data['queue'],
            'cacheStats'    => $data['cache_stats'],
            'db'            => $data['db'],
            'slowQueries'   => $data['slow_queries'],
            'generatedAt'   => $data['generated_at'],
            'windowHours'   => $hours,
        ]);
    }
}
