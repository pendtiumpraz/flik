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

            // Store pending subscription
            $subscription = Subscription::create([
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
                    $subscription->update([
                        'status' => 'active',
                        'payment_method' => $notification->payment_type ?? 'midtrans',
                        'paid_at' => now(),
                    ]);

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
                }
            } elseif (in_array($status, ['deny', 'cancel', 'expire'])) {
                $subscription->update(['status' => 'cancelled']);
            } elseif ($status == 'pending') {
                $subscription->update(['status' => 'pending']);
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
     * Activate free plan immediately.
     */
    private function activateFreePlan(SubscriptionPlan $plan)
    {
        $user = auth()->user();

        Subscription::create([
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
