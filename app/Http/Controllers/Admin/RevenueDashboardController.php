<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Analytics\RevenueAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Admin Revenue Dashboard (D1).
 *
 * Thin controller — all the maths lives in {@see RevenueAnalytics}.
 * We aggregate the service outputs into a payload, cache it for 10 min,
 * and hand the latest 20 paid subscriptions through directly (cheap
 * single query, no need to cache).
 *
 * Pass `?refresh=1` to bust the cache.
 *
 * Defensive: every aggregate is computed inside a try/catch so a
 * partially-seeded install (no subscription rows yet) renders zeros
 * instead of a 500.
 */
class RevenueDashboardController extends Controller
{
    private const CACHE_KEY = 'admin:revenue:dashboard:v2';

    /** 10 minutes per spec. */
    private const CACHE_TTL = 600;

    public function __construct(private readonly RevenueAnalytics $analytics)
    {
    }

    public function index(Request $request): View
    {
        if ($request->query('refresh') === '1') {
            Cache::forget(self::CACHE_KEY);
        }

        $data = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn (): array => $this->buildPayload(),
        );

        // Latest paid subscriptions stays outside cache so the table is live.
        $latest = collect();
        try {
            $latest = Subscription::query()
                ->with(['user:id,name,email', 'plan:id,name,slug,price,billing_cycle'])
                ->where('amount', '>', 0)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (Throwable $e) {
            Log::warning('RevenueDashboard: latest subs query failed', ['error' => $e->getMessage()]);
        }

        return view('admin.revenue.dashboard', array_merge($data, [
            'latestSubscriptions' => $latest,
        ]));
    }

    /**
     * Build the cached aggregate payload from RevenueAnalytics.
     *
     * @return array{
     *   stats: array<string,mixed>,
     *   trend: array<int, array{day:string,label:string,daily_revenue:float,mrr_snapshot:float,new_subs:int}>,
     *   perPlan: array<int, array{name:string,slug:string,subs:int,mrr:float,revenue:float,color:string,count:int}>,
     *   ltvByPlan: array<int, array{name:string,slug:string,price:float,ltv:float,churn_rate:float}>,
     *   computedAt: string,
     * }
     */
    private function buildPayload(): array
    {
        // Pull every metric defensively — never let one failure cascade.
        $mrr             = $this->safe(fn () => $this->analytics->mrr(), 0.0);
        $arr             = $this->safe(fn () => $this->analytics->arr(), 0.0);
        $arpu            = $this->safe(fn () => $this->analytics->arpu(), 0.0);
        $ltv             = $this->safe(fn () => $this->analytics->ltv(), 0.0);
        $newRev30        = $this->safe(fn () => $this->analytics->newRevenueLast30Days(), 0.0);
        $churnRev30      = $this->safe(fn () => $this->analytics->churnRevenueLast30Days(), 0.0);
        $perPlanRaw      = $this->safe(fn () => $this->analytics->revenueByPlan(30), []);
        $timeline        = $this->safe(fn () => $this->analytics->revenueTimeline(90), []);

        // Reshape per-plan rows into the structure the view expects (with palette colors).
        $palette = ['#C5A55A', '#3b82f6', '#22c55e', '#a855f7', '#f97316', '#ec4899', '#06b6d4', '#facc15'];
        $perPlan = [];
        foreach ($perPlanRaw as $i => $row) {
            $perPlan[] = [
                'name'    => (string) ($row['plan'] ?? '—'),
                'slug'    => (string) ($row['slug'] ?? ''),
                'subs'    => 0, // populated below
                'mrr'     => round((float) ($row['mrr'] ?? 0), 2),
                'revenue' => round((float) ($row['revenue'] ?? 0), 2),
                'count'   => (int) ($row['count'] ?? 0),
                'color'   => $palette[$i % count($palette)],
            ];
        }

        // Active sub count per plan (cheap single query).
        try {
            $activeBySlug = Subscription::query()
                ->where('status', 'active')
                ->where(function ($q): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
                ->selectRaw('subscription_plans.slug as slug, COUNT(*) as cnt')
                ->groupBy('subscription_plans.slug')
                ->pluck('cnt', 'slug');

            foreach ($perPlan as &$row) {
                $row['subs'] = (int) ($activeBySlug[$row['slug']] ?? 0);
            }
            unset($row);
        } catch (Throwable $e) {
            Log::warning('RevenueDashboard: active-subs-per-plan query failed', ['error' => $e->getMessage()]);
        }

        // LTV per plan derived from per-plan MRR (estimate using global churn, floored).
        // We can't ask the service for per-plan LTV cheaply; surface a single
        // global LTV here and per-plan revenue for the table.
        $ltvByPlan = [];
        foreach ($perPlan as $row) {
            if ($row['mrr'] <= 0 && $row['revenue'] <= 0) {
                continue;
            }
            $perSubMonthly = $row['subs'] > 0 ? $row['mrr'] / $row['subs'] : 0.0;
            $ltvByPlan[] = [
                'name'       => $row['name'],
                'slug'       => $row['slug'],
                'price'      => round($perSubMonthly, 2),
                'ltv'        => $perSubMonthly > 0 && $arpu > 0
                    ? round(($perSubMonthly / max($arpu, 0.01)) * $ltv, 2)
                    : 0.0,
                'churn_rate' => $arpu > 0 ? round(($arpu / max($ltv, 0.01)) * 100, 2) : 0.0,
            ];
        }

        // Reshape timeline to what the view's chart expects (label + count + revenue).
        $trend = [];
        foreach ($timeline as $row) {
            $date = $row['date'] ?? '';
            $trend[] = [
                'day'           => $date,
                'label'         => $date !== '' ? \Carbon\Carbon::parse($date)->format('d M') : '',
                'daily_revenue' => (float) ($row['daily_revenue'] ?? 0),
                'mrr_snapshot'  => (float) ($row['mrr_snapshot'] ?? 0),
                'new_subs'      => (int) ($row['new_subs'] ?? 0),
                // Legacy aliases the existing view binds against.
                'count'         => (int) ($row['new_subs'] ?? 0),
                'revenue'       => (float) ($row['daily_revenue'] ?? 0),
            ];
        }

        $stats = [
            'mrr'                => $mrr,
            'arr'                => $arr,
            'arpu'               => $arpu,
            'ltv'                => $ltv,
            'active_subs'        => array_sum(array_column($perPlan, 'subs')),
            'revenue_this_month' => $newRev30,
            'revenue_last_month' => 0.0, // not exposed by the service spec; dashboard renders newRev30 vs churn
            'net_delta'          => round($newRev30 - $churnRev30, 2),
            'net_delta_pct'      => $churnRev30 > 0
                ? round((($newRev30 - $churnRev30) / max($churnRev30, 0.01)) * 100, 1)
                : ($newRev30 > 0 ? 100.0 : 0.0),
            'churn_revenue'      => $churnRev30,
            'churn_count'        => 0, // surfaced via service if needed; cheap fetch below
            'paid_plans_count'   => count(array_filter($perPlan, static fn ($p) => $p['mrr'] > 0 || $p['revenue'] > 0)),
            'new_revenue_30d'    => $newRev30,
            'churn_revenue_30d'  => $churnRev30,
        ];

        // Cancellations count (cheap, defensive).
        try {
            $stats['churn_count'] = (int) Subscription::query()
                ->whereNotNull('cancelled_at')
                ->where('cancelled_at', '>=', now()->subDays(30))
                ->count();
        } catch (Throwable $e) {
            // already 0
        }

        return [
            'stats'      => $stats,
            'trend'      => $trend,
            'perPlan'    => $perPlan,
            'ltvByPlan'  => $ltvByPlan,
            'computedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Run a closure and return its result, or `$fallback` on exception.
     *
     * @template T
     * @param  callable():T  $fn
     * @param  T  $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('RevenueDashboard: aggregate failed', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }
}
