<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PromoCodeService — single entry point for promo validation + apply.
 *
 * Two-phase usage by design:
 *   1. validateCode()  — called at checkout preview AND at checkout
 *                        submission. Pure read-only, never writes.
 *   2. apply()         — called from the Midtrans webhook AFTER the
 *                        payment is confirmed. Writes the redemption
 *                        ledger row inside a transaction so concurrent
 *                        webhook retries don't double-count.
 *
 * Splitting validate from apply matters because the user can see the
 * discounted price WAY before the bank settles — we don't want to burn
 * a single-use code just because they hovered over the checkout button.
 */
class PromoCodeService
{
    /**
     * Validate a code for a given (user, plan) pair.
     *
     * @return array{valid: bool, code: ?PromoCode, discount: ?float, reason: ?string, final_price: ?float}
     */
    public function validateCode(string $code, User $user, ?SubscriptionPlan $plan = null): array
    {
        $normalised = strtoupper(trim($code));

        if ($normalised === '') {
            return $this->fail('Kode promo kosong.');
        }

        /** @var PromoCode|null $promo */
        $promo = PromoCode::query()->where('code', $normalised)->first();

        if ($promo === null) {
            return $this->fail('Kode promo tidak ditemukan.');
        }

        [$ok, $reason] = $promo->isRedeemableBy($user, $plan);

        if (! $ok) {
            return [
                'valid'       => false,
                'code'        => $promo,
                'discount'    => null,
                'reason'      => $reason,
                'final_price' => null,
            ];
        }

        $discount = $plan !== null
            ? $promo->computeDiscount((float) $plan->price)
            : 0.0;

        $finalPrice = $plan !== null
            ? max(0.0, (float) $plan->price - $discount)
            : null;

        return [
            'valid'       => true,
            'code'        => $promo,
            'discount'    => $discount,
            'reason'      => null,
            'final_price' => $finalPrice,
        ];
    }

    /**
     * Record a redemption + return the IDR discount that was applied.
     *
     * IDEMPOTENCY: protected by a unique (user_id, promo_code_id, subscription_id)
     * lookup. If a row already exists for this triple we return the
     * historical discount instead of writing a duplicate. This makes
     * the function safe to call from a webhook that Midtrans retries.
     */
    public function apply(PromoCode $code, Subscription $subscription, User $user): float
    {
        return DB::transaction(function () use ($code, $subscription, $user): float {
            // Lock the row to prevent two concurrent webhook deliveries
            // from both passing the max_uses gate.
            /** @var PromoCode $locked */
            $locked = PromoCode::query()
                ->whereKey($code->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: same (user, code, subscription) returns the
            // existing redemption rather than writing twice.
            $existing = PromoCodeRedemption::query()
                ->where('promo_code_id', $locked->id)
                ->where('user_id', $user->id)
                ->where('subscription_id', $subscription->id)
                ->first();

            if ($existing) {
                return (float) $existing->discount_applied_idr;
            }

            // Defensive: re-validate inside the transaction so a code
            // that hit max_uses between checkout and webhook is denied
            // (the webhook should still ack — caller decides whether
            // to refund, but we never write a phantom redemption).
            [$ok, $reason] = $locked->isRedeemableBy($user, $subscription->plan);

            if (! $ok) {
                Log::warning('PromoCodeService::apply rejected at webhook time', [
                    'promo_code_id'   => $locked->id,
                    'user_id'         => $user->id,
                    'subscription_id' => $subscription->id,
                    'reason'          => $reason,
                ]);
                return 0.0;
            }

            $discount = $locked->computeDiscount((float) $subscription->amount);

            $locked->recordRedemption($user, $subscription, $discount);

            return $discount;
        });
    }

    /**
     * @return array{valid: bool, code: null, discount: null, reason: string, final_price: null}
     */
    private function fail(string $reason): array
    {
        return [
            'valid'       => false,
            'code'        => null,
            'discount'    => null,
            'reason'      => $reason,
            'final_price' => null,
        ];
    }
}
