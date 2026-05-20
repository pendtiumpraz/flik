<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GiftSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin surface for purchased gift subscriptions.
 *
 * Read-only listing today; refund flow is intentionally not implemented
 * inline here — refunds should be issued through Midtrans' dashboard so
 * the source of truth stays on the gateway side. We do expose the
 * Midtrans transaction_id for ops to copy-paste into Midtrans.
 *
 * Filters: status (paid/unpaid, redeemed/unredeemed/expired), plan,
 * recipient or purchaser email substring.
 */
class GiftSubscriptionAdminController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $query = GiftSubscription::query()
            ->with(['plan:id,name,slug', 'purchaser:id,name,email', 'redeemer:id,name,email'])
            ->latest();

        if ($q = trim((string) $request->input('q', ''))) {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->where('code', 'like', $like)
                  ->orWhere('purchaser_email', 'like', $like)
                  ->orWhere('recipient_email', 'like', $like)
                  ->orWhere('recipient_name', 'like', $like)
                  ->orWhere('midtrans_order_id', 'like', $like);
            });
        }

        // Status filter — we synthesize this from the persisted flags
        // because the lifecycle is "paid → redeemed → expired" rather
        // than a single column.
        $status = trim((string) $request->input('status', ''));
        match ($status) {
            'unpaid'      => $query->whereNull('paid_at'),
            'paid'        => $query->whereNotNull('paid_at')->whereNull('redeemed_at'),
            'redeemed'    => $query->whereNotNull('redeemed_at'),
            'expired'     => $query->whereNull('redeemed_at')
                                  ->whereNotNull('expires_at')
                                  ->where('expires_at', '<=', now()),
            default       => null,
        };

        $gifts = $query->paginate(self::PER_PAGE)->withQueryString();

        $totals = [
            'all'      => GiftSubscription::count(),
            'paid'     => GiftSubscription::whereNotNull('paid_at')->whereNull('redeemed_at')->count(),
            'redeemed' => GiftSubscription::whereNotNull('redeemed_at')->count(),
            'unpaid'   => GiftSubscription::whereNull('paid_at')->count(),
            'revenue'  => (int) GiftSubscription::whereNotNull('paid_at')->sum('amount_paid_idr'),
        ];

        return view('admin.gifts.index', [
            'title'  => 'Gift Subscriptions',
            'gifts'  => $gifts,
            'totals' => $totals,
        ]);
    }
}
