<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\GiftSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * GiftSubscriptionService — single entry point for gift purchase + redeem.
 *
 * Three responsibilities:
 *   purchase()      — create an unpaid GiftSubscription row + return it
 *                     so the caller can hand the Snap token off to
 *                     Midtrans. Does NOT touch the payment gateway —
 *                     that stays in the controller alongside the
 *                     regular checkout flow so config + key handling
 *                     lives in exactly one place.
 *   redeem()        — validate the code and turn it into a real
 *                     Subscription row for the recipient. Wraps the
 *                     check + write in a transaction with row locking
 *                     so two concurrent redeem clicks can't double-spend.
 *   sendGiftEmail() — fires the branded HTML mail to the recipient
 *                     after the Midtrans webhook stamps paid_at. Kept
 *                     here so the gift template lives next to its
 *                     business logic; the controller just calls this
 *                     method (or dispatches a job that does).
 */
class GiftSubscriptionService
{
    /**
     * Default expiry window for an unredeemed gift. Same as the
     * column default — kept in sync here so callers that pass
     * `$recipientData['expires_at']` know the convention.
     */
    public const DEFAULT_EXPIRY_DAYS = 90;

    /**
     * Create an unpaid GiftSubscription. The caller is expected to
     * hand the returned model + amount to Midtrans (or whatever
     * gateway adapter applies) and stamp `paid_at` from the webhook.
     *
     * `$recipientData` accepts:
     *   - recipient_name
     *   - recipient_email
     *   - personal_message
     *   - amount_paid_idr   (default = plan->price)
     *   - duration_days     (default = plan->duration_days or 30)
     *   - midtrans_order_id (set later by the controller after Snap token)
     */
    public function purchase(
        SubscriptionPlan $plan,
        string $purchaserEmail,
        ?User $purchaser = null,
        array $recipientData = []
    ): GiftSubscription {
        $duration = (int) (
            $recipientData['duration_days']
            ?? (property_exists($plan, 'duration_days') && $plan->duration_days ? $plan->duration_days : 30)
            ?? 30
        );

        $amount = (float) (
            $recipientData['amount_paid_idr']
            ?? $plan->price
            ?? 0
        );

        // The model's `creating` hook back-fills `code` + `expires_at`
        // when omitted — caller does not need to wire those.
        $gift = GiftSubscription::create([
            'plan_id'              => $plan->id,
            'duration_days'        => max(1, $duration),
            'purchased_by_user_id' => $purchaser?->id,
            'purchaser_email'      => $purchaserEmail,
            'recipient_name'       => $recipientData['recipient_name']    ?? null,
            'recipient_email'      => $recipientData['recipient_email']   ?? null,
            'personal_message'     => $recipientData['personal_message']  ?? null,
            'amount_paid_idr'      => $amount,
            'midtrans_order_id'    => $recipientData['midtrans_order_id'] ?? null,
        ]);

        return $gift;
    }

    /**
     * Redeem a gift code for the given user. Returns the freshly
     * created Subscription row.
     *
     * Throws \RuntimeException on a non-recoverable failure (unknown
     * code, expired, already redeemed, not yet paid) — the controller
     * catches and flashes a friendly Indonesian message.
     */
    public function redeem(string $code, User $user): Subscription
    {
        $normalised = strtoupper(trim($code));

        if ($normalised === '') {
            throw new \RuntimeException('Kode hadiah kosong.');
        }

        // Strip the human-friendly "GIFT-XXXX-XXXX-XXXX" wrapper if
        // the user pasted it verbatim from the email.
        $normalised = str_replace(['GIFT-', '-', ' '], '', $normalised);

        if (strlen($normalised) !== 12) {
            throw new \RuntimeException('Format kode hadiah tidak valid.');
        }

        return DB::transaction(function () use ($normalised, $user): Subscription {
            /** @var GiftSubscription|null $gift */
            $gift = GiftSubscription::query()
                ->where('code', $normalised)
                ->lockForUpdate()
                ->first();

            if ($gift === null) {
                throw new \RuntimeException('Kode hadiah tidak ditemukan.');
            }

            if (! $gift->isPaid()) {
                throw new \RuntimeException('Kode hadiah ini belum dibayar.');
            }

            if ($gift->isRedeemed()) {
                throw new \RuntimeException('Kode hadiah ini sudah pernah ditukarkan.');
            }

            if ($gift->isExpired()) {
                throw new \RuntimeException('Kode hadiah ini sudah kedaluwarsa.');
            }

            // Same user trying to redeem the same code twice in
            // parallel? lockForUpdate above held them; double-check
            // anyway so we surface a clean message instead of a 500.
            if ($gift->redeemed_by_user_id !== null && $gift->redeemed_by_user_id !== $user->id) {
                throw new \RuntimeException('Kode hadiah ini sudah ditukarkan oleh pengguna lain.');
            }

            $plan = $gift->plan;
            if ($plan === null) {
                throw new \RuntimeException('Paket hadiah ini tidak tersedia lagi.');
            }

            $now = now();

            // Create the Subscription row. payment fields are guarded,
            // so forceCreate bypasses mass-assignment protection — same
            // pattern as PaymentController::activateFreePlan().
            $subscription = Subscription::forceCreate([
                'user_id'              => $user->id,
                'subscription_plan_id' => $plan->id,
                'order_id'             => 'GIFT-'.$gift->id.'-'.$now->timestamp,
                'status'               => 'active',
                'amount'               => $gift->amount_paid_idr,
                'payment_method'       => 'gift',
                'starts_at'            => $now,
                'ends_at'              => $now->copy()->addDays((int) $gift->duration_days),
                'paid_at'              => $gift->paid_at ?? $now,
            ]);

            $gift->forceFill([
                'redeemed_by_user_id' => $user->id,
                'redeemed_at'         => $now,
            ])->save();

            return $subscription;
        });
    }

    /**
     * Send the branded gift-received HTML email to the recipient.
     *
     * Skips silently when there is no recipient_email (the buyer
     * chose to deliver the code themselves) or when SMTP is not
     * configured — failures here MUST NOT block the payment ack.
     */
    public function sendGiftEmail(GiftSubscription $gift): void
    {
        $to = $gift->recipient_email;
        if (empty($to)) {
            return;
        }

        try {
            $payload = [
                'gift'  => $gift,
                'code'  => $gift->displayCode(),
                'plan'  => $gift->plan,
            ];

            Mail::send('emails.gift-received', $payload, function ($message) use ($to, $gift) {
                $subject = 'Kamu menerima hadiah FLiK '
                    .($gift->plan?->name ?? 'Premium').'!';
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('GiftSubscriptionService::sendGiftEmail failed', [
                'gift_id' => $gift->id,
                'to'      => $to,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
