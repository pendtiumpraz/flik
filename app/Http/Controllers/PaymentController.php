<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
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
     */
    public function checkout(SubscriptionPlan $plan)
    {
        if (!self::isEnabled()) {
            return back()->with('error', 'Payment gateway belum dikonfigurasi. Hubungi admin.');
        }

        if ($plan->price <= 0) {
            // Free plan - activate immediately
            return $this->activateFreePlan($plan);
        }

        $user = auth()->user();
        $orderId = 'FLIK-' . $user->id . '-' . time();

        // Set Midtrans config
        \Midtrans\Config::$serverKey = config('services.midtrans.server_key');
        \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
        \Midtrans\Config::$isSanitized = config('services.midtrans.is_sanitized');
        \Midtrans\Config::$is3ds = config('services.midtrans.is_3ds');

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $plan->price,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
            'item_details' => [
                [
                    'id' => $plan->slug,
                    'price' => (int) $plan->price,
                    'quantity' => 1,
                    'name' => 'FLiK ' . $plan->name . ' - ' . $plan->duration_days . ' Hari',
                ],
            ],
        ];

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);

            // Store pending subscription.
            // SECURITY: payment fields are guarded — use forceCreate so this
            // server-controlled write isn't blocked by mass-assignment guard.
            $subscription = Subscription::forceCreate([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'order_id' => $orderId,
                'status' => 'pending',
                'amount' => $plan->price,
                'starts_at' => now(),
                'ends_at' => now()->addDays($plan->duration_days),
            ]);

            return view('payment.checkout', [
                'snapToken' => $snapToken,
                'plan' => $plan,
                'subscription' => $subscription,
                'clientKey' => config('services.midtrans.client_key'),
            ]);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat transaksi: ' . $e->getMessage());
        }
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
