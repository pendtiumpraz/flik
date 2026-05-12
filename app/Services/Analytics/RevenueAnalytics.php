<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Revenue analytics for the Finance / Founders dashboard (D1).
 *
 * Every public method is wrapped in a try/catch so a missing table or
 * an unseeded install never crashes the dashboard — we degrade to an
 * empty/zero result and log the failure.
 *
 * Pricing semantics:
 *  - `subscription_plans.price` is the headline price for the plan's
 *    `billing_cycle`. We normalise it to a monthly figure for MRR / ARPU
 *    / LTV so plans on different cycles can be summed apples-to-apples.
 *  - `subscriptions.amount` is the cash amount actually paid (snapshot
 *    of what Midtrans charged) — that's what we use for revenue rollups
 *    by date window. Falls back to `created_at` when `paid_at` is null
 *    (legacy rows from before `paid_at` was added).
 */
class RevenueAnalytics
{
    /**
     * Floor for monthly churn used when computing LTV. Without it, a
     * single retained cohort drives LTV to infinity.
     */
    private const MIN_MONTHLY_CHURN = 0.005; // 0.5%

    /**
     * Default monthly churn rate used when historical data is too thin
     * to derive a real number (e.g. fresh installs with no cancellations).
     */
    private const DEFAULT_MONTHLY_CHURN = 0.05; // 5%

    /**
     * Monthly Recurring Revenue.
     *
     * Sum of monthly-normalised plan prices across all currently-active
     * subscriptions. If `$asOf` is supplied we honour it, otherwise we
     * use the present moment.
     */
    public function mrr(?Carbon $asOf = null): float
    {
        try {
            if (!Schema::hasTable('subscriptions') || !Schema::hasTable('subscription_plans')) {
                return 0.0;
            }

            $when = $asOf ?? Carbon::now();

            $subs = Subscription::query()
                ->where('status', 'active')
                ->where(function ($q) use ($when): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $when);
                })
                ->where('starts_at', '<=', $when)
                ->with('plan:id,price,billing_cycle')
                ->get();

            $mrr = 0.0;
            foreach ($subs as $sub) {
                $mrr += $this->monthlyValueFor($sub->plan);
            }

            return round($mrr, 2);
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::mrr failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /**
     * Annual Recurring Revenue (= MRR × 12).
     */
    public function arr(): float
    {
        return round($this->mrr() * 12, 2);
    }

    /**
     * Per-plan revenue + active-sub count rollup.
     *
     * `revenue` here is the cash collected in the trailing `$days`
     * window (default 30 days); `count` is the number of subscriptions
     * for the plan that contributed.
     *
     * @return array<int, array{plan: string, slug: string, revenue: float, count: int, mrr: float}>
     */
    public function revenueByPlan(int $days = 30): array
    {
        try {
            if (!Schema::hasTable('subscriptions') || !Schema::hasTable('subscription_plans')) {
                return [];
            }

            $since = Carbon::now()->subDays(max(1, $days));

            $plans = SubscriptionPlan::query()->orderBy('sort_order')->get();

            // Pre-compute per-plan MRR contribution from currently active subs.
            $mrrPerPlan = [];
            $activeSubs = Subscription::query()
                ->where('status', 'active')
                ->where(function ($q): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now());
                })
                ->with('plan:id,price,billing_cycle')
                ->get();
            foreach ($activeSubs as $sub) {
                $pid = (int) $sub->subscription_plan_id;
                $mrrPerPlan[$pid] = ($mrrPerPlan[$pid] ?? 0.0) + $this->monthlyValueFor($sub->plan);
            }

            $out = [];
            foreach ($plans as $plan) {
                $paidQuery = Subscription::query()
                    ->where('subscription_plan_id', $plan->id)
                    ->where('amount', '>', 0);

                $paid = (clone $paidQuery)
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '>=', $since)
                    ->sum('amount');

                $legacy = (clone $paidQuery)
                    ->whereNull('paid_at')
                    ->where('created_at', '>=', $since)
                    ->sum('amount');

                $count = (clone $paidQuery)
                    ->where(function ($q) use ($since): void {
                        $q->where(function ($qq) use ($since): void {
                            $qq->whereNotNull('paid_at')->where('paid_at', '>=', $since);
                        })->orWhere(function ($qq) use ($since): void {
                            $qq->whereNull('paid_at')->where('created_at', '>=', $since);
                        });
                    })
                    ->count();

                $out[] = [
                    'plan'    => (string) $plan->name,
                    'slug'    => (string) $plan->slug,
                    'revenue' => round((float) $paid + (float) $legacy, 2),
                    'count'   => (int) $count,
                    'mrr'     => round((float) ($mrrPerPlan[$plan->id] ?? 0.0), 2),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::revenueByPlan failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Monthly-value sum of subscriptions whose `cancelled_at` (or `ends_at`,
     * fallback) lies in the last 30 days. Treats churn in MRR-equivalent
     * terms — i.e. "how much recurring monthly revenue did we lose".
     */
    public function churnRevenueLast30Days(): float
    {
        try {
            if (!Schema::hasTable('subscriptions')) {
                return 0.0;
            }

            $since = Carbon::now()->subDays(30);

            $cancelled = Subscription::query()
                ->where(function ($q) use ($since): void {
                    $q->whereNotNull('cancelled_at')->where('cancelled_at', '>=', $since);
                })
                ->orWhere(function ($q) use ($since): void {
                    $q->whereIn('status', ['cancelled', 'expired'])
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '>=', $since)
                        ->where('ends_at', '<=', Carbon::now());
                })
                ->with('plan:id,price,billing_cycle')
                ->get();

            $sum = 0.0;
            foreach ($cancelled as $sub) {
                $sum += $this->monthlyValueFor($sub->plan);
            }

            return round($sum, 2);
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::churnRevenueLast30Days failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /**
     * Sum of `amount` paid on subscriptions started in the last 30 days.
     * Uses `paid_at` when present, falls back to `created_at`.
     */
    public function newRevenueLast30Days(): float
    {
        try {
            if (!Schema::hasTable('subscriptions')) {
                return 0.0;
            }

            $since = Carbon::now()->subDays(30);

            $paid = (float) Subscription::query()
                ->where('amount', '>', 0)
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $since)
                ->sum('amount');

            $legacy = (float) Subscription::query()
                ->where('amount', '>', 0)
                ->whereNull('paid_at')
                ->where('created_at', '>=', $since)
                ->sum('amount');

            return round($paid + $legacy, 2);
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::newRevenueLast30Days failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /**
     * Daily revenue for the last `$days` days, plus an MRR snapshot
     * carried forward day-by-day. `mrr_snapshot` is approximate: we
     * recompute the MRR from active subscriptions as of midnight on
     * each day in the window. Cheap when `$days` is small (default 90).
     *
     * @return array<int, array{date: string, daily_revenue: float, mrr_snapshot: float, new_subs: int}>
     */
    public function revenueTimeline(int $days = 90): array
    {
        try {
            if (!Schema::hasTable('subscriptions')) {
                return [];
            }

            $days = max(1, $days);
            $now = Carbon::now();
            $start = $now->copy()->subDays($days - 1)->startOfDay();

            // Bulk query daily revenue (paid_at preferred, fall back to created_at).
            $paidRows = Subscription::query()
                ->where('amount', '>', 0)
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $start)
                ->select(
                    DB::raw('DATE(paid_at) as day'),
                    DB::raw('SUM(amount) as total'),
                    DB::raw('COUNT(*) as cnt'),
                )
                ->groupBy('day')
                ->pluck('total', 'day');

            $legacyRows = Subscription::query()
                ->where('amount', '>', 0)
                ->whereNull('paid_at')
                ->where('created_at', '>=', $start)
                ->select(
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('SUM(amount) as total'),
                )
                ->groupBy('day')
                ->pluck('total', 'day');

            $newSubsRows = Subscription::query()
                ->where('amount', '>', 0)
                ->where('created_at', '>=', $start)
                ->select(
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('COUNT(*) as cnt'),
                )
                ->groupBy('day')
                ->pluck('cnt', 'day');

            $timeline = [];
            for ($d = $days - 1; $d >= 0; $d--) {
                $date = $now->copy()->subDays($d);
                $key = $date->toDateString();

                $daily = (float) ($paidRows[$key] ?? 0) + (float) ($legacyRows[$key] ?? 0);

                $timeline[] = [
                    'date'          => $key,
                    'daily_revenue' => round($daily, 2),
                    'mrr_snapshot'  => $this->mrrSnapshotAt($date->copy()->endOfDay()),
                    'new_subs'      => (int) ($newSubsRows[$key] ?? 0),
                ];
            }

            return $timeline;
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::revenueTimeline failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Average Revenue Per User = total MRR ÷ active subscriber count.
     */
    public function arpu(): float
    {
        try {
            if (!Schema::hasTable('subscriptions')) {
                return 0.0;
            }

            $activeCount = Subscription::query()
                ->where('status', 'active')
                ->where(function ($q): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now());
                })
                ->count();

            if ($activeCount <= 0) {
                return 0.0;
            }

            return round($this->mrr() / $activeCount, 2);
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::arpu failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /**
     * Lifetime Value (estimated) = ARPU ÷ monthly_churn_rate.
     *
     * The churn rate is derived from the trailing 90 days globally; we
     * floor it at MIN_MONTHLY_CHURN so a single retained cohort can't
     * push LTV to infinity, and fall back to DEFAULT_MONTHLY_CHURN when
     * we have no churn signal at all.
     */
    public function ltv(): float
    {
        try {
            $arpu = $this->arpu();
            if ($arpu <= 0) {
                return 0.0;
            }

            $churn = $this->globalMonthlyChurnRate();
            return round($arpu / max($churn, self::MIN_MONTHLY_CHURN), 2);
        } catch (Throwable $e) {
            Log::warning('RevenueAnalytics::ltv failed', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Convert a plan's headline price to a monthly recurring value.
     */
    private function monthlyValueFor(?SubscriptionPlan $plan): float
    {
        if ($plan === null) {
            return 0.0;
        }

        $price = (float) $plan->price;
        $cycle = strtolower((string) $plan->billing_cycle);

        return match ($cycle) {
            'yearly', 'annual', 'annually' => $price / 12.0,
            'quarterly'                    => $price / 3.0,
            'weekly'                       => $price * (52 / 12),
            default                        => $price, // monthly / unknown
        };
    }

    /**
     * Re-compute MRR as of an arbitrary moment in the past.
     * Used for the daily MRR snapshot in `revenueTimeline`.
     */
    private function mrrSnapshotAt(Carbon $when): float
    {
        try {
            $subs = Subscription::query()
                ->where('starts_at', '<=', $when)
                ->where(function ($q) use ($when): void {
                    $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $when);
                })
                ->where(function ($q) use ($when): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $when);
                })
                ->with('plan:id,price,billing_cycle')
                ->get();

            $mrr = 0.0;
            foreach ($subs as $sub) {
                $mrr += $this->monthlyValueFor($sub->plan);
            }
            return round($mrr, 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Global monthly churn rate over the trailing 90 days, normalised
     * to a 30-day-equivalent rate. Returns DEFAULT_MONTHLY_CHURN when
     * we don't have enough data to derive a real number.
     */
    private function globalMonthlyChurnRate(): float
    {
        try {
            $now = Carbon::now();
            $from = $now->copy()->subDays(90);

            $cancelled = (int) Subscription::query()
                ->whereNotNull('cancelled_at')
                ->whereBetween('cancelled_at', [$from, $now])
                ->count();

            $exposed = (int) Subscription::query()
                ->where('created_at', '<=', $now)
                ->where(function ($q) use ($from): void {
                    $q->whereNull('cancelled_at')
                        ->orWhere('cancelled_at', '>=', $from);
                })
                ->count();

            if ($exposed <= 0) {
                return self::DEFAULT_MONTHLY_CHURN;
            }

            $windowDays = max(1, $from->diffInDays($now));
            $periodicRate = $cancelled / $exposed;
            return $periodicRate * (30 / $windowDays);
        } catch (Throwable $e) {
            return self::DEFAULT_MONTHLY_CHURN;
        }
    }
}
