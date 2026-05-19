<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Promo code performance dashboard.
 *
 * Aggregates the promo_code_redemptions ledger to produce:
 *   - Top redeemed codes (by count, with total discount $)
 *   - Total discount given (sum of discount_applied_idr)
 *   - Conversion uplift — % of subscriptions paid with a promo
 *     versus the global paid-conversion baseline. Read as
 *     "promo-attributed share of revenue".
 *
 * The 14-day sparkline mirrors the AI Usage dashboard convention
 * (see AiUsageController) so the visual language stays consistent.
 */
class PromoCodeReportController extends Controller
{
    public function index(): View
    {
        $totalRedemptions = PromoCodeRedemption::count();
        $totalDiscount    = (float) PromoCodeRedemption::sum('discount_applied_idr');

        // Top 10 codes by redemption count.
        $topCodes = PromoCode::query()
            ->select('promo_codes.*')
            ->selectSub(
                PromoCodeRedemption::selectRaw('COUNT(*)')
                    ->whereColumn('promo_code_redemptions.promo_code_id', 'promo_codes.id'),
                'redemption_count'
            )
            ->selectSub(
                PromoCodeRedemption::selectRaw('COALESCE(SUM(discount_applied_idr), 0)')
                    ->whereColumn('promo_code_redemptions.promo_code_id', 'promo_codes.id'),
                'total_discount_idr'
            )
            ->orderByDesc('redemption_count')
            ->limit(10)
            ->get();

        // 14-day sparkline of daily redemptions.
        $since = now()->subDays(13)->startOfDay();
        $daily = PromoCodeRedemption::query()
            ->where('redeemed_at', '>=', $since)
            ->selectRaw('DATE(redeemed_at) AS day, COUNT(*) AS n, SUM(discount_applied_idr) AS total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $sparkline = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $row = $daily->get($day);
            $sparkline[] = [
                'day'   => $day,
                'count' => $row?->n ?? 0,
                'total' => (float) ($row?->total ?? 0),
            ];
        }

        // Conversion uplift: of all PAID subscriptions in the last 30
        // days, what % were attributed to a promo? We treat any
        // subscription with at least one redemption row as "attributed".
        $paid30 = Subscription::query()
            ->where('amount', '>', 0)
            ->whereIn('status', ['active', 'cancelled', 'expired'])
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $attributed30 = PromoCodeRedemption::query()
            ->whereNotNull('subscription_id')
            ->where('redeemed_at', '>=', now()->subDays(30))
            ->distinct('subscription_id')
            ->count('subscription_id');

        $upliftPct = $paid30 > 0 ? round(($attributed30 / $paid30) * 100, 1) : 0.0;

        return view('admin.promo-codes.report', [
            'title'            => 'Promo Code Report',
            'totalRedemptions' => $totalRedemptions,
            'totalDiscount'    => $totalDiscount,
            'topCodes'         => $topCodes,
            'sparkline'        => $sparkline,
            'paid30'           => $paid30,
            'attributed30'     => $attributed30,
            'upliftPct'        => $upliftPct,
        ]);
    }
}
