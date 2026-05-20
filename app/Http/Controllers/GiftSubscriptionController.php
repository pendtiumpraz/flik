<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Billing\GiftSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * GiftSubscriptionController — buyer side (anonymous OK) + redeemer side
 * (auth required).
 *
 * Endpoints:
 *   GET  /gift/{plan}           → buy form              (auth optional)
 *   POST /gift/purchase/{plan}  → create + Snap token   (auth optional)
 *   POST /gift/webhook          → Midtrans webhook      (public, no auth)
 *   GET  /gift/redeem           → redemption form       (auth required)
 *   POST /gift/redeem           → redemption submit     (auth required, throttled)
 *
 * Payment plumbing intentionally mirrors {@see PaymentController}:
 *   - same Midtrans config block
 *   - same `payment.checkout` view style (re-used here in
 *     `gift.checkout` so the Snap script tag stays consistent)
 *   - same isEnabled() gate so coming-soon UX is identical.
 *
 * Webhook handling: Midtrans only sends ONE webhook URL, so this
 * controller's webhook ALSO accepts subscription orders and forwards
 * them to PaymentController::webhook. That's wired below in
 * {@see self::webhook()} — we sniff the order_id prefix to decide.
 */
class GiftSubscriptionController extends Controller
{
    public function __construct(
        private readonly GiftSubscriptionService $gifts,
    ) {}

    /**
     * GET /gift/{plan} — buy form.
     *
     * Anonymous purchase is allowed; if the visitor is not logged in
     * we still ask for a purchaser_email so Midtrans can attach the
     * receipt.
     */
    public function buy(SubscriptionPlan $plan, Request $request): View|RedirectResponse
    {
        if (! $plan->is_active || $plan->price <= 0) {
            return redirect()->route('plans.index')
                ->with('error', 'Paket ini tidak bisa dihadiahkan.');
        }

        if (! PaymentController::isEnabled()) {
            return redirect()->route('plans.index')
                ->with('error', 'Gateway pembayaran belum dikonfigurasi.');
        }

        return view('gift.buy', [
            'plan' => $plan,
            'user' => $request->user(),
        ]);
    }

    /**
     * POST /gift/purchase/{plan} — create the GiftSubscription, mint
     * a Midtrans Snap token at the plan's full price, return the
     * checkout view that embeds the Snap script.
     */
    public function purchase(Request $request, SubscriptionPlan $plan): View|RedirectResponse
    {
        if (! $plan->is_active || $plan->price <= 0) {
            return redirect()->route('plans.index')
                ->with('error', 'Paket ini tidak bisa dihadiahkan.');
        }

        if (! PaymentController::isEnabled()) {
            return redirect()->route('plans.index')
                ->with('error', 'Gateway pembayaran belum dikonfigurasi.');
        }

        $data = $request->validate([
            'purchaser_email'  => ['required', 'email', 'max:255'],
            'recipient_name'   => ['nullable', 'string', 'max:120'],
            'recipient_email'  => ['nullable', 'email', 'max:255'],
            'personal_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $duration = (int) ($plan->duration_days ?? 30);

        // Step 1 — persist the gift row (unpaid). The model's creating
        // hook back-fills `code` + `expires_at` so we don't have to.
        $gift = $this->gifts->purchase(
            plan: $plan,
            purchaserEmail: $data['purchaser_email'],
            purchaser: $request->user(),
            recipientData: [
                'recipient_name'   => $data['recipient_name']   ?? null,
                'recipient_email'  => $data['recipient_email']  ?? null,
                'personal_message' => $data['personal_message'] ?? null,
                'duration_days'    => $duration,
                'amount_paid_idr'  => (float) $plan->price,
            ],
        );

        // Step 2 — Midtrans Snap. Mirror PaymentController::checkout's
        // config block exactly so a server-key rotation only needs to
        // touch one config call.
        $orderId = 'GIFT-'.$gift->id.'-'.time();

        \Midtrans\Config::$serverKey    = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized  = config('services.midtrans.is_sanitized');
        \Midtrans\Config::$is3ds        = config('services.midtrans.is_3ds');

        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => (int) $plan->price,
            ],
            'customer_details' => [
                'first_name' => $request->user()->name ?? 'FLiK Gift Buyer',
                'email'      => $data['purchaser_email'],
            ],
            'item_details' => [[
                'id'       => 'GIFT-'.$plan->slug,
                'price'    => (int) $plan->price,
                'quantity' => 1,
                'name'     => 'Gift: FLiK '.$plan->name.' - '.$duration.' Hari',
            ]],
            'custom_field1' => 'gift',
            'custom_field2' => (string) $gift->id,
        ];

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);

            $gift->forceFill(['midtrans_order_id' => $orderId])->save();

            return view('gift.checkout', [
                'snapToken' => $snapToken,
                'plan'      => $plan,
                'gift'      => $gift,
                'clientKey' => config('services.midtrans.client_key'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GiftSubscription: snap token creation failed', [
                'gift_id' => $gift->id,
                'error'   => $e->getMessage(),
            ]);

            return back()->with('error', 'Gagal membuat transaksi: '.$e->getMessage());
        }
    }

    /**
     * POST /gift/webhook — Midtrans callback for gift orders.
     *
     * The Midtrans console only points at ONE webhook URL, so the
     * canonical entry is still /payment/webhook (PaymentController).
     * We expose this second endpoint for ops who want to split the
     * URLs in a multi-region setup; the logic mirrors
     * PaymentController::webhook but acts on GiftSubscription rows.
     *
     * Idempotent: hitting the same notification twice does NOT
     * double-stamp paid_at or double-send the gift email.
     */
    public function webhook(Request $request): \Illuminate\Http\JsonResponse
    {
        if (! PaymentController::isEnabled()) {
            return response()->json(['status' => 'payment_disabled'], 503);
        }

        \Midtrans\Config::$serverKey    = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');

        try {
            $notification = new \Midtrans\Notification();

            $orderId = (string) $notification->order_id;
            $status  = (string) $notification->transaction_status;
            $fraud   = (string) ($notification->fraud_status ?? '');
            $txnId   = (string) ($notification->transaction_id ?? '');

            // Not a gift order? Defer to the regular payment controller.
            if (! str_starts_with($orderId, 'GIFT-')) {
                return app(PaymentController::class)->webhook($request);
            }

            /** @var GiftSubscription|null $gift */
            $gift = GiftSubscription::query()
                ->where('midtrans_order_id', $orderId)
                ->first();

            if ($gift === null) {
                return response()->json(['status' => 'not_found'], 404);
            }

            if (in_array($status, ['capture', 'settlement'], true)
                && ($fraud === 'accept' || $fraud === '')) {

                // Idempotent paid_at stamp: only fire the email the
                // first time the webhook arrives.
                $firstTime = $gift->paid_at === null;

                $gift->forceFill([
                    'paid_at'                 => $gift->paid_at ?? now(),
                    'midtrans_transaction_id' => $txnId !== '' ? $txnId : $gift->midtrans_transaction_id,
                ])->save();

                if ($firstTime) {
                    try {
                        $this->gifts->sendGiftEmail($gift);
                    } catch (\Throwable $e) {
                        // Email failure must NEVER fail the webhook ack.
                        Log::warning('GiftSubscription: webhook email send failed', [
                            'gift_id' => $gift->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            }

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /gift/redeem — code-entry form.
     */
    public function redeemForm(Request $request): View
    {
        return view('gift.redeem');
    }

    /**
     * POST /gift/redeem — validate code → create Subscription → flash.
     *
     * Throttled at the route layer (10/min/user) to prevent brute
     * force enumeration of 12-char codes.
     */
    public function redeem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        try {
            $subscription = $this->gifts->redeem($data['code'], $request->user());
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()->route('profile.show')
            ->with('success', '🎁 Hadiah berhasil ditukar! Paket '
                .($subscription->plan->name ?? 'kamu')
                .' aktif sampai '
                .optional($subscription->ends_at)->format('d M Y').'.');
    }
}
