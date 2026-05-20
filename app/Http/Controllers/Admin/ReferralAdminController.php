<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralConversion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin surface for the refer-a-friend program.
 *
 * Two views:
 *   index()  — filterable table of every conversion (status + window).
 *   report() — KPI cards + top-referrers leaderboard. Suitable for
 *              the "is this driving growth?" question the finance /
 *              marketing exec asks at week-end.
 *
 * Both are double-gated: this lives under the admin route group
 * (auth + can:admin) and the per-route `can:admin` middleware on the
 * route declaration keeps the surface bare-minimum admin (we don't
 * require an extra permission slug because the program is operational
 * — any admin who can read the user table can see this).
 */
class ReferralAdminController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $query = ReferralConversion::query()
            ->with([
                'referrer:id,name,email,referral_code',
                'referred:id,name,email',
                'subscription:id,subscription_plan_id,status,amount',
            ])
            ->latest();

        if ($status = trim((string) $request->input('status', ''))) {
            if (in_array($status, ReferralConversion::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            // Match against the referrer's or referred's email/name.
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->whereHas('referrer', fn ($r) => $r
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('referral_code', 'like', $like))
                ->orWhereHas('referred', fn ($r) => $r
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            });
        }

        $conversions = $query->paginate(self::PER_PAGE)->withQueryString();

        $totals = [
            'total'     => ReferralConversion::count(),
            'pending'   => ReferralConversion::where('status', ReferralConversion::STATUS_PENDING)->count(),
            'qualified' => ReferralConversion::where('status', ReferralConversion::STATUS_QUALIFIED)->count(),
            'rewarded'  => ReferralConversion::where('status', ReferralConversion::STATUS_REWARDED)->count(),
            'expired'   => ReferralConversion::where('status', ReferralConversion::STATUS_EXPIRED)->count(),
        ];

        return view('admin.referrals.index', [
            'title'       => 'Referrals',
            'conversions' => $conversions,
            'totals'      => $totals,
            'statuses'    => ReferralConversion::STATUSES,
        ]);
    }

    /**
     * Aggregate KPI page: top referrers + program-wide funnel rates.
     */
    public function report(Request $request): View
    {
        $totals = [
            'total'     => ReferralConversion::count(),
            'pending'   => ReferralConversion::where('status', ReferralConversion::STATUS_PENDING)->count(),
            'qualified' => ReferralConversion::where('status', ReferralConversion::STATUS_QUALIFIED)->count(),
            'rewarded'  => ReferralConversion::where('status', ReferralConversion::STATUS_REWARDED)->count(),
            'expired'   => ReferralConversion::where('status', ReferralConversion::STATUS_EXPIRED)->count(),
        ];

        $coinsPaid = (int) DB::table('referral_conversions')
            ->sum(DB::raw('referrer_reward_coins + referred_reward_coins'));

        $xpPaid = (int) DB::table('referral_conversions')
            ->sum(DB::raw('referrer_reward_xp + referred_reward_xp'));

        // Top-10 referrers by lifetime rewarded count. Pulls denormalised
        // counter on users so this is one indexed query, not a GROUP BY
        // on the ledger.
        $topReferrers = User::query()
            ->where('referrals_count', '>', 0)
            ->orderByDesc('referrals_count')
            ->orderByDesc('referrals_rewards_earned')
            ->limit(10)
            ->get([
                'id', 'name', 'email', 'referral_code',
                'referrals_count', 'referrals_rewards_earned',
            ]);

        // Conversion-rate snapshot — pending → qualified → rewarded.
        $conversionRate = [
            'qualify_rate' => $totals['total'] > 0
                ? round(($totals['qualified'] + $totals['rewarded']) / $totals['total'] * 100, 1)
                : 0.0,
            'reward_rate' => $totals['total'] > 0
                ? round($totals['rewarded'] / $totals['total'] * 100, 1)
                : 0.0,
        ];

        return view('admin.referrals.report', [
            'title'           => 'Referrals Report',
            'totals'          => $totals,
            'coinsPaid'       => $coinsPaid,
            'xpPaid'          => $xpPaid,
            'topReferrers'    => $topReferrers,
            'conversionRate'  => $conversionRate,
        ]);
    }
}
