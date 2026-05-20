<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReferralConversion — the audit + reward ledger row for one
 * (referrer, referred) attribution.
 *
 * SECURITY: writes here are SYSTEM-driven via {@see \App\Services\Referrals\ReferralService}.
 * End users never POST data that lands directly on this table, so
 * `$guarded = []` is safe — there is no user-controlled mass-assignment
 * vector reaching the model.
 */
class ReferralConversion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'qualified_at' => 'datetime',
        'rewarded_at'  => 'datetime',

        'referrer_reward_coins' => 'integer',
        'referrer_reward_xp'    => 'integer',
        'referred_reward_coins' => 'integer',
        'referred_reward_xp'    => 'integer',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REWARDED  = 'rewarded';
    public const STATUS_EXPIRED   = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUALIFIED,
        self::STATUS_REWARDED,
        self::STATUS_EXPIRED,
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * Human-readable label for the dashboard table. Indonesian-first
     * (matches the rest of the FLiK UI) with English fallback.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => 'Menunggu',
            self::STATUS_QUALIFIED => 'Terkualifikasi',
            self::STATUS_REWARDED  => 'Diberi Hadiah',
            self::STATUS_EXPIRED   => 'Kedaluwarsa',
            default                => ucfirst((string) $this->status),
        };
    }

    /**
     * Colour token used by the dashboard badge. Returns one of the gold
     * theme's status tokens so the view stays free of inline CSS logic.
     */
    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => '#888',
            self::STATUS_QUALIFIED => '#3b82f6',
            self::STATUS_REWARDED  => '#22c55e',
            self::STATUS_EXPIRED   => '#ef4444',
            default                => '#666',
        };
    }
}
