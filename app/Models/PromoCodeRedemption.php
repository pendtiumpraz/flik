<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PromoCodeRedemption — append-only ledger row.
 *
 * Never edit a redemption after creation. Refunds/reversals are tracked
 * on the parent Subscription (status='cancelled') — keeping this table
 * append-only makes the marketing report trustworthy.
 */
class PromoCodeRedemption extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'promo_code_id',
        'user_id',
        'subscription_id',
        'discount_applied_idr',
        'redeemed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'discount_applied_idr' => 'decimal:2',
        'redeemed_at'          => 'datetime',
    ];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
