<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Computes per-plan pricing performance signals (conversion, churn, LTV)
 * and asks the configured AI provider for pricing adjustment recommendations
 * in Bahasa Indonesia.
 *
 * "Conversion" definition (best-effort given the schema):
 *   subscriptions_in_window / new_users_in_window
 * — total platform conversion is split across plans by the plan's share of
 * paid subscriptions. We don't have per-plan landing page traffic, so this is
 * a coarse proxy and the AI prompt is told as much.
 *
 * "Churn" definition: subscriptions on this plan whose cancelled_at falls
 * within 90 days of starts_at, divided by total subscriptions on the plan
 * that have completed at least 90 days of life (or were cancelled within 90).
 *
 * "LTV" definition: avg(amount) × avg(lifetime_days / billing_cycle_days).
 * For free plans (price = 0) LTV is reported as 0 with a note.
 *
 * Output:
 *   [
 *     'generated_at'    => 'Y-m-d H:i:s',
 *     'window_days'     => 90,
 *     'global'          => ['new_users'=>..,'paid_subs'=>..,'conversion_pct'=>..],
 *     'plans'           => [
 *         [
 *             'id'=>.., 'slug'=>'premium', 'name'=>'Premium',
 *             'current_price'=>49000, 'current_price_fmt'=>'Rp 49.000',
 *             'is_active'=>true, 'billing_cycle'=>'monthly',
 *             'subscriptions_in_window'=>.., 'paid_revenue_in_window'=>..,
 *             'conversion_pct'=>.., 'churn_pct'=>.., 'ltv_idr'=>..,
 *             'avg_lifetime_days'=>.., 'discount_signups_share'=>..,
 *         ], ...
 *     ],
 *     'recommendations' => [['plan_slug'=>..,'current_price'=>..,'recommended_price'=>..,'reason'=>..,'confidence'=>..], ...],
 *     'ai_error'        => ?string,
 *   ]
 */
class PricingOptimizer
{
    /** Window for "recent" performance metrics (signups + revenue). */
    protected const WINDOW_DAYS = 90;

    /** Subscriptions cancelled within this many days of starting count as early churn. */
    protected const EARLY_CHURN_DAYS = 90;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function analyze(): array
    {
        $global = $this->collectGlobalStats();
        $plans = $this->collectPlanStats($global['paid_subs']);

        $recommendations = [];
        $aiError = null;

        try {
            $recommendations = $this->askAi($global, $plans);
        } catch (\Throwable $e) {
            Log::warning('PricingOptimizer: AI call failed', [
                'error' => $e->getMessage(),
            ]);
            $aiError = $e->getMessage();
        }

        return [
            'generated_at' => now()->toDateTimeString(),
            'window_days' => self::WINDOW_DAYS,
            'global' => $global,
            'plans' => $plans,
            'recommendations' => $recommendations,
            'ai_error' => $aiError,
        ];
    }

    // ── Stat collection ──────────────────────────────────────────────────

    /**
     * Platform-wide signals used to derive a baseline conversion %.
     *
     * @return array{new_users:int,paid_subs:int,conversion_pct:float}
     */
    protected function collectGlobalStats(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $newUsers = (int) User::query()->where('created_at', '>=', $since)->count();
        $paidSubs = (int) Subscription::query()
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                // Treat any subscription with a non-zero amount as paid;
                // free-plan signups should not inflate paid conversion.
                $q->where('amount', '>', 0)->orWhereNull('amount');
            })
            ->count();

        $conversion = $newUsers > 0 ? round(($paidSubs / $newUsers) * 100, 2) : 0.0;

        return [
            'new_users' => $newUsers,
            'paid_subs' => $paidSubs,
            'conversion_pct' => $conversion,
        ];
    }

    /**
     * Per-plan stats array.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function collectPlanStats(int $totalPaidSubsInWindow): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $hasPaidColumns = Schema::hasColumn('subscriptions', 'amount');
        $hasDiscountSig = $this->detectDiscountColumn();

        $plans = SubscriptionPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($plans as $plan) {
            $subsInWindow = Subscription::query()
                ->where('subscription_plan_id', $plan->id)
                ->where('created_at', '>=', $since)
                ->count();

            $revenueInWindow = $hasPaidColumns
                ? (float) Subscription::query()
                    ->where('subscription_plan_id', $plan->id)
                    ->where('created_at', '>=', $since)
                    ->sum('amount')
                : (float) ($plan->price * $subsInWindow);

            // Per-plan conversion: subscriptions on this plan / global new users.
            // (We can't isolate plan-page visitors without analytics tooling.)
            $conversionPct = $totalPaidSubsInWindow > 0
                ? round(($subsInWindow / max(1, $totalPaidSubsInWindow)) * 100, 2)
                : 0.0;

            // Early churn: cancelled within EARLY_CHURN_DAYS of starting,
            // measured against subs that had a chance to churn.
            [$churnPct, $churnSampleSize] = $this->computeEarlyChurn($plan->id);

            // LTV approximation.
            [$ltv, $avgLifetimeDays] = $this->computeLtv($plan, $hasPaidColumns);

            // Price elasticity hint: signups during discount periods, if we
            // can detect a discount column. Falls back to "n/a".
            $discountShare = null;
            if ($hasDiscountSig !== null) {
                $discountShare = $this->discountSignupShare($plan->id, $hasDiscountSig);
            }

            $out[] = [
                'id' => (int) $plan->id,
                'slug' => (string) $plan->slug,
                'name' => (string) $plan->name,
                'current_price' => (float) $plan->price,
                'current_price_fmt' => $plan->formatted_price,
                'is_active' => (bool) $plan->is_active,
                'billing_cycle' => (string) $plan->billing_cycle,
                'video_quality' => (string) ($plan->video_quality ?? ''),
                'max_screens' => (int) ($plan->max_screens ?? 1),
                'subscriptions_in_window' => $subsInWindow,
                'paid_revenue_in_window' => round($revenueInWindow, 2),
                'paid_revenue_fmt' => 'Rp '.number_format($revenueInWindow, 0, ',', '.'),
                'conversion_pct' => $conversionPct,
                'churn_pct' => $churnPct,
                'churn_sample_size' => $churnSampleSize,
                'ltv_idr' => round($ltv, 2),
                'ltv_fmt' => 'Rp '.number_format($ltv, 0, ',', '.'),
                'avg_lifetime_days' => $avgLifetimeDays,
                'discount_signups_share' => $discountShare,
            ];
        }

        return $out;
    }

    /**
     * @return array{0:float,1:int} [churnPct, sampleSize]
     */
    protected function computeEarlyChurn(int $planId): array
    {
        // Subs that have had a chance to churn: started > EARLY_CHURN_DAYS ago,
        // OR cancelled within EARLY_CHURN_DAYS of starting.
        $cutoff = now()->subDays(self::EARLY_CHURN_DAYS);

        $sample = Subscription::query()
            ->where('subscription_plan_id', $planId)
            ->where(function ($q) use ($cutoff) {
                $q->where('starts_at', '<=', $cutoff)
                    ->orWhereNotNull('cancelled_at');
            })
            ->get(['starts_at', 'cancelled_at']);

        if ($sample->isEmpty()) {
            return [0.0, 0];
        }

        $churned = 0;
        foreach ($sample as $sub) {
            if (! $sub->cancelled_at || ! $sub->starts_at) {
                continue;
            }
            $daysAlive = $sub->starts_at->diffInDays($sub->cancelled_at);
            if ($daysAlive >= 0 && $daysAlive <= self::EARLY_CHURN_DAYS) {
                $churned++;
            }
        }

        $pct = ($churned / $sample->count()) * 100;

        return [round($pct, 2), $sample->count()];
    }

    /**
     * @return array{0:float,1:int} [ltvIdr, avgLifetimeDays]
     */
    protected function computeLtv(SubscriptionPlan $plan, bool $hasAmount): array
    {
        if ((float) $plan->price <= 0) {
            return [0.0, 0];
        }

        // avg_amount (defaults to plan price if no amount column or no rows).
        $avgAmount = (float) $plan->price;
        if ($hasAmount) {
            $maybeAvg = Subscription::query()
                ->where('subscription_plan_id', $plan->id)
                ->where('amount', '>', 0)
                ->avg('amount');
            if ($maybeAvg !== null) {
                $avgAmount = (float) $maybeAvg;
            }
        }

        // avg_lifetime_days: across cancelled OR ended subscriptions.
        $rows = Subscription::query()
            ->where('subscription_plan_id', $plan->id)
            ->whereNotNull('starts_at')
            ->where(function ($q) {
                $q->whereNotNull('cancelled_at')->orWhereNotNull('ends_at');
            })
            ->get(['starts_at', 'cancelled_at', 'ends_at']);

        if ($rows->isEmpty()) {
            // Plan too new — fall back to one billing cycle as a safe estimate.
            $cycleDays = $this->billingCycleDays($plan->billing_cycle);

            return [round($avgAmount, 2), $cycleDays];
        }

        $totalDays = 0.0;
        $count = 0;
        foreach ($rows as $sub) {
            $end = $sub->cancelled_at ?? $sub->ends_at;
            if (! $end) {
                continue;
            }
            $days = $sub->starts_at->diffInDays($end);
            if ($days < 0) {
                continue;
            }
            $totalDays += $days;
            $count++;
        }

        if ($count === 0) {
            $cycleDays = $this->billingCycleDays($plan->billing_cycle);

            return [round($avgAmount, 2), $cycleDays];
        }

        $avgLifetimeDays = $totalDays / $count;
        $cycleDays = max(1, $this->billingCycleDays($plan->billing_cycle));
        $cyclesLived = $avgLifetimeDays / $cycleDays;
        $ltv = $avgAmount * max(1.0, $cyclesLived);

        return [round($ltv, 2), (int) round($avgLifetimeDays)];
    }

    protected function billingCycleDays(?string $cycle): int
    {
        return match (strtolower((string) $cycle)) {
            'yearly', 'annual', 'annually' => 365,
            'weekly' => 7,
            'daily' => 1,
            default => 30,
        };
    }

    /**
     * If the subscriptions table happens to carry a discount column
     * (`discount_amount`, `discount_code`, `coupon_code`, etc.) return its name,
     * else null. The current schema in this branch has none.
     */
    protected function detectDiscountColumn(): ?string
    {
        foreach (['discount_amount', 'discount_code', 'coupon_code', 'promo_code'] as $candidate) {
            if (Schema::hasColumn('subscriptions', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Share of subs on this plan (in window) where the discount column is set.
     */
    protected function discountSignupShare(int $planId, string $column): ?float
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $total = (int) Subscription::query()
            ->where('subscription_plan_id', $planId)
            ->where('created_at', '>=', $since)
            ->count();

        if ($total === 0) {
            return null;
        }

        $withDiscount = (int) Subscription::query()
            ->where('subscription_plan_id', $planId)
            ->where('created_at', '>=', $since)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, '!=', 0)
            ->count();

        return round(($withDiscount / $total) * 100, 2);
    }

    // ── AI step ──────────────────────────────────────────────────────────

    /**
     * @return array<int,array{plan_slug:string,current_price:float,recommended_price:float,reason:string,confidence:string}>
     */
    protected function askAi(array $global, array $plans): array
    {
        $context = [
            'mata_uang' => 'IDR',
            'jendela_analisis' => self::WINDOW_DAYS.' hari',
            'pengguna_baru_total' => $global['new_users'],
            'langganan_berbayar' => $global['paid_subs'],
            'konversi_global_pct' => $global['conversion_pct'],
            'paket' => $plans,
            'catatan' => 'Konversi per-paket adalah proxy berdasarkan share signup; bukan funnel landing page. '
                                   .'Churn diukur sebagai cancellation dalam 90 hari pertama. '
                                   .'LTV = avg(amount) × max(1, avg_lifetime_days / billing_cycle_days). '
                                   .'Jika discount_signups_share = null artinya tidak ada signal diskon di skema saat ini.',
        ];

        $system = 'Anda adalah analis pricing untuk FLiK, layanan streaming film Indonesia. '
            .'Tugas Anda: menganalisis performa setiap paket langganan lalu merekomendasikan penyesuaian harga. '
            .'Output WAJIB strict JSON tanpa prosa, tanpa fence markdown, tanpa komentar.';

        $user = "Berikan rekomendasi pricing untuk SETIAP paket aktif. Output WAJIB array JSON dengan schema:\n"
            ."[{\"plan_slug\":\"...\",\"current_price\":<number>,\"recommended_price\":<number>,\"reason\":\"...\",\"confidence\":\"high|medium|low\"}]\n\n"
            ."Aturan:\n"
            ."- Harga dalam IDR (Rupiah, integer atau decimal). Untuk paket gratis (price=0) tetap kembalikan 0.\n"
            ."- reason wajib menyebut metrik konkret (konversi, churn, LTV, atau share diskon) dalam Bahasa Indonesia.\n"
            ."- confidence 'high' hanya jika sample langganan paket >= 30 atau churn sample >= 30.\n"
            ."- Jika konversi sangat rendah dan churn tinggi → pertimbangkan turunkan harga atau tambahkan trial.\n"
            ."- Jika LTV >> harga dan churn rendah → pertimbangkan naikkan harga 5-15%.\n"
            ."- Jika data tidak cukup, kembalikan recommended_price = current_price dan jelaskan di reason.\n\n"
            ."Data:\n```json\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n```";

        $response = $this->ai->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            ['max_tokens' => 1200, 'temperature' => 0.4],
        );

        return $this->parseRecommendations((string) ($response['content'] ?? ''), $plans);
    }

    /**
     * @return array<int,array{plan_slug:string,current_price:float,recommended_price:float,reason:string,confidence:string}>
     */
    protected function parseRecommendations(string $raw, array $plans): array
    {
        $json = $this->extractJsonArray($raw);
        if ($json === null) {
            Log::warning('PricingOptimizer: could not extract JSON', [
                'raw' => Str::limit($raw, 400),
            ]);

            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        // Index plans by slug for current-price lookup + filtering hallucinated slugs.
        $bySlug = [];
        foreach ($plans as $p) {
            $bySlug[$p['slug']] = $p;
        }

        $allowedConfidence = ['high', 'medium', 'low'];
        $clean = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slug = trim((string) ($item['plan_slug'] ?? ''));
            if ($slug === '' || ! isset($bySlug[$slug])) {
                // Skip recommendations for plans that don't exist.
                continue;
            }

            $confidence = strtolower(trim((string) ($item['confidence'] ?? 'low')));
            if (! in_array($confidence, $allowedConfidence, true)) {
                $confidence = 'low';
            }

            $current = (float) ($bySlug[$slug]['current_price'] ?? 0);
            $recommended = isset($item['recommended_price']) && is_numeric($item['recommended_price'])
                ? round((float) $item['recommended_price'], 2)
                : $current;

            // Sanity clamp: recommended price must be non-negative; cap insane swings at ±60%.
            if ($recommended < 0) {
                $recommended = 0.0;
            }
            if ($current > 0) {
                $minBound = $current * 0.4;
                $maxBound = $current * 1.6;
                $recommended = max($minBound, min($maxBound, $recommended));
            }

            $clean[] = [
                'plan_slug' => $slug,
                'plan_name' => (string) ($bySlug[$slug]['name'] ?? $slug),
                'current_price' => $current,
                'current_price_fmt' => 'Rp '.number_format($current, 0, ',', '.'),
                'recommended_price' => $recommended,
                'recommended_price_fmt' => 'Rp '.number_format($recommended, 0, ',', '.'),
                'delta_pct' => $current > 0
                    ? round((($recommended - $current) / $current) * 100, 2)
                    : 0.0,
                'reason' => trim((string) ($item['reason'] ?? '')),
                'confidence' => $confidence,
            ];
        }

        return $clean;
    }

    protected function extractJsonArray(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }
}
