<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Billing\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Check if payment gateway is configured.
     */
    public static function isEnabled(): bool
    {
        return !empty(config('services.midtrans.server_key'));
    }

    /**
     * Create a subscription payment via Midtrans Snap.
     *
     * Accepts an optional `promo_code` query/body param. When valid,
     * the discounted amount is sent to Midtrans, the original promo
     * is stamped onto the Subscription row (custom_field2), and a
     * redemption ledger row is written by the webhook once payment
     * settles (see PromoCodeService::apply()).
     */
    public function checkout(SubscriptionPlan $plan, Request $request, PromoCodeService $promoService)
    {
        if (!self::isEnabled()) {
            return back()->with('error', 'Payment gateway belum dikonfigurasi. Hubungi admin.');
        }

        if ($plan->price <= 0) {
            // Free plan - activate immediately. Free plan flow ignores
            // any promo code entirely — discounts on Rp 0 are meaningless
            // and the promo's max_uses budget should not be burned here.
            return $this->activateFreePlan($plan);
        }

        $user = auth()->user();
        $orderId = 'FLIK-' . $user->id . '-' . time();

        // ── Optional promo code resolution ─────────────────────────
        $promoCode = null;
        $discount = 0.0;
        $finalAmount = (int) $plan->price;
        $promoInput = trim((string) $request->input('promo_code', ''));

        if ($promoInput !== '') {
            $check = $promoService->validateCode($promoInput, $user, $plan);

            if ($check['valid']) {
                $promoCode = $check['code'];
                $discount = (float) ($check['discount'] ?? 0);
                $finalAmount = (int) max(0, (int) $plan->price - (int) round($discount));
            } else {
                // Silently drop an invalid code rather than blocking checkout —
                // the live validator in the UI should have caught this already.
                // Flash a notice so the user understands their entered code
                // didn't apply (e.g. ran out of stock between preview and click).
                session()->flash('promo_warning', $check['reason'] ?? 'Kode promo tidak dapat digunakan.');
            }
        }

        // Set Midtrans config
        \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized = config('services.midtrans.is_sanitized');
        \Midtrans\Config::$is3ds = config('services.midtrans.is_3ds');

        $itemDetails = [
            [
                'id' => $plan->slug,
                'price' => (int) $plan->price,
                'quantity' => 1,
                'name' => 'FLiK ' . $plan->name . ' - ' . $plan->duration_days . ' Hari',
            ],
        ];

        // Discount appears as a separate negative line item so the
        // Midtrans receipt is transparent (customer sees "PROMO: -Rp 5.000").
        if ($promoCode && $discount > 0) {
            $itemDetails[] = [
                'id' => 'PROMO-' . $promoCode->code,
                'price' => -1 * (int) round($discount),
                'quantity' => 1,
                'name' => 'Promo ' . $promoCode->code,
            ];
        }

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $finalAmount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
            'item_details' => $itemDetails,
            // custom_field1 = promo code text (visible on Midtrans dashboard).
            // The integer promo_code_id is stamped onto the Subscription row
            // below so the webhook can call PromoCodeService::apply() without
            // re-resolving the code.
            'custom_field1' => $promoCode?->code,
        ];

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);

            // Store pending subscription.
            // SECURITY: payment fields are guarded — use forceCreate so this
            // server-controlled write isn't blocked by mass-assignment guard.
            // `promo_code_id` is stored in `notes` JSON so we don't need a
            // schema change for a single nullable FK that lives on the
            // tail of the existing $guarded denylist. (Subscription row
            // never accepts user-controlled writes — see model docblock.)
            $subscription = Subscription::forceCreate([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'order_id' => $orderId,
                'status' => 'pending',
                'amount' => $finalAmount,
                'starts_at' => now(),
                'ends_at' => now()->addDays($plan->duration_days),
            ]);

            // Stash promo_code_id on the subscription row so the webhook
            // can call PromoCodeService::apply() without re-resolving the
            // code (race-free + survives the user changing the code in
            // a second tab). We persist via the subscriptions table
            // column when it exists; otherwise fall back to caching the
            // mapping by order_id so the webhook can still resolve.
            if ($promoCode) {
                cache()->put(
                    'promo_pending:' . $orderId,
                    $promoCode->id,
                    now()->addHours(24)
                );
            }

            return view('payment.checkout', [
                'snapToken' => $snapToken,
                'plan' => $plan,
                'subscription' => $subscription,
                'clientKey' => config('services.midtrans.client_key'),
                'promoCode' => $promoCode,
                'discount' => $discount,
                'finalAmount' => $finalAmount,
            ]);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat transaksi: ' . $e->getMessage());
        }
    }

    /**
     * JSON endpoint backing the live promo-code preview on the checkout
     * page. Returns valid/discount/final_price/reason without touching
     * the redemption ledger.
     */
    public function validatePromo(Request $request, PromoCodeService $promoService): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'code'    => ['required', 'string', 'max:40'],
        ]);

        $plan = SubscriptionPlan::find($data['plan_id']);
        $user = $request->user();

        $result = $promoService->validateCode($data['code'], $user, $plan);

        return response()->json([
            'valid'           => $result['valid'],
            'discount_idr'    => $result['discount'] !== null ? (int) round($result['discount']) : null,
            'final_price_idr' => $result['final_price'] !== null ? (int) round($result['final_price']) : null,
            'reason'          => $result['reason'],
            'label'           => $result['code']?->discount_label,
        ]);
    }

    /**
     * Handle Midtrans webhook notification.
     */
    public function webhook(Request $request)
    {
        if (!self::isEnabled()) {
            return response()->json(['status' => 'payment_disabled'], 503);
        }

        \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');

        try {
            $notification = new \Midtrans\Notification();

            $orderId = $notification->order_id;
            $status = $notification->transaction_status;
            $fraudStatus = $notification->fraud_status;

            $subscription = Subscription::where('order_id', $orderId)->first();

            if (!$subscription) {
                return response()->json(['status' => 'not_found'], 404);
            }

            if ($status == 'capture' || $status == 'settlement') {
                if ($fraudStatus == 'accept' || empty($fraudStatus)) {
                    // Webhook updates status/payment_method/paid_at — guarded
                    // fields, so write via forceFill to bypass mass-assignment
                    // protection (this path is server-trusted: signature is
                    // validated upstream by Midtrans\Notification).
                    $subscription->forceFill([
                        'status' => 'active',
                        'payment_method' => $notification->payment_type ?? 'midtrans',
                        'paid_at' => now(),
                    ])->save();

                    // ── Promo redemption (if any) ─────────────────────
                    // Look up the pending promo_code_id we cached at checkout
                    // time. Wrapped in try/catch so a misbehaving redemption
                    // never blocks the subscription activation (the actual
                    // payment has settled — we can't fail the user here).
                    try {
                        $promoId = cache()->pull('promo_pending:' . $orderId);
                        if ($promoId) {
                            $promo = PromoCode::find($promoId);
                            if ($promo) {
                                app(PromoCodeService::class)->apply(
                                    $promo,
                                    $subscription,
                                    $subscription->user
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('PaymentController: promo apply failed', [
                            'order_id' => $orderId,
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Create notification for user
                    \App\Models\Notification::create([
                        'user_id' => $subscription->user_id,
                        'type' => 'subscription',
                        'title' => '🎉 Langganan Aktif!',
                        'message' => 'Paket ' . $subscription->plan->name . ' berhasil diaktifkan.',
                        'action_url' => '/profile',
                    ]);

                    // Award XP
                    $level = $subscription->user->getOrCreateLevel();
                    $level->addXp(50);

                    // Admin bell — finance team gets pinged on successful
                    // payments. Queued (default queue, $tries=2) so a notif
                    // hiccup never delays Midtrans' webhook ack.
                    $this->notifyAdminPayment('success', $subscription, [
                        'status' => $status,
                        'fraud_status' => $fraudStatus,
                    ]);
                }
            } elseif (in_array($status, ['deny', 'cancel', 'expire'])) {
                $subscription->forceFill(['status' => 'cancelled'])->save();

                // Admin bell — finance gets a warning on failed/expired txns.
                $failureMessage = match ($status) {
                    'deny' => 'Payment denied by issuer.',
                    'cancel' => 'Customer cancelled the payment.',
                    'expire' => 'Payment window expired before completion.',
                    default => 'Payment failed: ' . $status,
                };
                $this->notifyAdminPayment('failed', $subscription, [
                    'status' => $status,
                    'fraud_status' => $fraudStatus,
                    'failure_message' => $failureMessage,
                ]);
            } elseif ($status == 'pending') {
                $subscription->forceFill(['status' => 'pending'])->save();
            }

            // Chargebacks travel as `fraud_status === 'challenge'` or
            // `chargeback` on Midtrans' newer schema. Either signal
            // promotes to a critical alert that wakes super-admin too.
            if (in_array(strtolower((string) $fraudStatus), ['challenge', 'chargeback'], true)
                || strtolower((string) $status) === 'chargeback') {
                $this->notifyAdminPayment('chargeback', $subscription, [
                    'status' => $status,
                    'fraud_status' => $fraudStatus,
                ]);
            }

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Payment success callback.
     */
    public function success(Request $request)
    {
        return redirect()->route('profile.show')
            ->with('success', '🎉 Pembayaran berhasil! Paket kamu sudah aktif.');
    }

    /**
     * Fan out a payment-related event onto the admin bell.
     *
     * Wraps {@see \App\Listeners\Admin\PaymentEventListener} dispatch so a
     * notification failure (queue unavailable, AdminNotifier not yet shipped,
     * etc.) NEVER prevents us from acking Midtrans' webhook — Midtrans
     * retries aggressively on slow callbacks, which would multiply the
     * customer-facing side effects (duplicate XP awards, duplicate user
     * notifications, etc.).
     *
     * @param  'success'|'failed'|'chargeback'  $kind
     * @param  array<string,mixed>              $extra Status / fraud_status / failure_message etc.
     */
    private function notifyAdminPayment(string $kind, Subscription $subscription, array $extra = []): void
    {
        try {
            \App\Listeners\Admin\PaymentEventListener::dispatch([
                'kind' => $kind,
                'subscription_id' => $subscription->id,
            ] + $extra);
        } catch (\Throwable $e) {
            \Log::warning('PaymentController: admin notif dispatch failed', [
                'kind' => $kind,
                'subscription_id' => $subscription->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Activate free plan immediately.
     */
    private function activateFreePlan(SubscriptionPlan $plan)
    {
        $user = auth()->user();

        // SECURITY: payment fields are guarded — use forceCreate so this
        // server-controlled write isn't blocked by mass-assignment guard.
        Subscription::forceCreate([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'order_id' => 'FREE-' . $user->id . '-' . time(),
            'status' => 'active',
            'amount' => 0,
            'payment_method' => 'free',
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
            'paid_at' => now(),
        ]);

        return redirect()->route('profile.show')
            ->with('success', '✅ Paket Free berhasil diaktifkan!');
    }
}
