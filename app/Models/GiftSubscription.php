<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * GiftSubscription — pre-paid redemption code one user buys for another.
 *
 * SECURITY note: end users never POST data that lands directly on
 * `status`, `paid_at`, `redeemed_at` etc. — those are stamped by the
 * webhook / service layer. We still use `$guarded = []` (not a fillable
 * allowlist) because the public-facing fields (recipient name/email,
 * personal_message) are sanitised at the controller boundary via
 * $request->validate() and the model is otherwise only touched by
 * server-trusted code paths.
 *
 * Code generation:
 *   12-char alpha-num excluding ambiguous glyphs, prefixed `GIFT-` for
 *   the human-readable label (stored on the model raw — we only add
 *   the prefix in the email + UI display, never on disk).
 */
class GiftSubscription extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'paid_at'         => 'datetime',
        'redeemed_at'     => 'datetime',
        'expires_at'      => 'datetime',
        'duration_days'   => 'integer',
        'amount_paid_idr' => 'decimal:2',
    ];

    /**
     * Boot hook — back-fill defaults that the service layer would
     * otherwise have to remember on every create. Idempotent: only
     * sets a value when the caller did not.
     */
    protected static function booted(): void
    {
        static::creating(function (self $gift) {
            if (empty($gift->code)) {
                $gift->code = self::makeUniqueCode();
            }

            if (empty($gift->expires_at)) {
                $gift->expires_at = now()->addDays(90);
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by_user_id');
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_by_user_id !== null
            && $this->redeemed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Human-friendly label "GIFT-ABCD-EFGH" suitable for the email
     * template and the share box. Storage on disk stays raw (12 chars).
     */
    public function displayCode(): string
    {
        $code = (string) ($this->attributes['code'] ?? '');
        if (strlen($code) === 12) {
            return sprintf('GIFT-%s-%s-%s',
                substr($code, 0, 4),
                substr($code, 4, 4),
                substr($code, 8, 4),
            );
        }

        return 'GIFT-'.$code;
    }

    /**
     * Hand the gift to the recipient. Wraps
     * {@see \App\Services\Billing\GiftSubscriptionService::redeem()}
     * so callers that only have a model in scope don't need to wire
     * the service in. Internally still goes through the service so
     * the lock + validation logic lives in one place.
     */
    public function redeemFor(User $user): Subscription
    {
        return app(\App\Services\Billing\GiftSubscriptionService::class)
            ->redeem((string) $this->code, $user);
    }

    /**
     * Make a 12-char alpha-num code that does NOT collide with any
     * existing row. Skips the ambiguous O/0/I/1/L glyphs so customer
     * support doesn't have to re-read codes over the phone.
     */
    public static function makeUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < 12; $i++) {
                $candidate .= $alphabet[random_int(0, $len - 1)];
            }

            $exists = self::query()->where('code', $candidate)->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        // Last-resort: longer string. The unique index is the
        // authoritative line of defence.
        return strtoupper(Str::random(16));
    }
}
