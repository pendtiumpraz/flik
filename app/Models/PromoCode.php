<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PromoCode — marketing-issued discount token.
 *
 * Lifecycle:
 *   1. Admin creates via /admin/promo-codes (manual or bulkGenerate).
 *   2. User enters at checkout. PromoCodeService::validateCode() runs
 *      every gate (active, in-window, has uses, plan eligible, per-user
 *      cap, first-time-only).
 *   3. After Midtrans confirms payment, PromoCodeService::apply() writes
 *      a PromoCodeRedemption row.
 *
 * The `code` column is always uppercased on write and queried uppercased
 * on read, giving case-insensitive lookups without depending on database
 * collation. See the migration docblock for the storage rationale.
 */
class PromoCode extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_FREE_TRIAL_DAYS = 'free_trial_days';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED,
        self::TYPE_FREE_TRIAL_DAYS,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'applies_to_plans',
        'max_uses',
        'max_uses_per_user',
        'min_subscription_months',
        'starts_at',
        'expires_at',
        'is_active',
        'is_first_time_only',
        'created_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'discount_value'         => 'decimal:2',
        'applies_to_plans'       => 'array',
        'max_uses'               => 'integer',
        'max_uses_per_user'      => 'integer',
        'min_subscription_months' => 'integer',
        'starts_at'              => 'datetime',
        'expires_at'             => 'datetime',
        'is_active'              => 'boolean',
        'is_first_time_only'     => 'boolean',
    ];

    /**
     * Auto-uppercase on write so a unique index gives us case-insensitive
     * lookups for free. Pair with strtoupper() on the query side
     * (see PromoCodeService::validateCode()).
     */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : strtoupper(trim($value));
    }

    // ─── Relations ─────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Active = flag on AND within the [starts_at, expires_at] window.
     * Null window bounds are treated as "no bound".
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * Available = active AND still has total uses left.
     *
     * Implemented as: max_uses IS NULL (unlimited) OR
     * COUNT(redemptions) < max_uses. We use a withCount-less subquery
     * because eager counting every row in the listing is fine — the
     * promo_codes table is expected to stay <10k rows.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->active()->where(function (Builder $q) {
            $q->whereNull('max_uses')
              ->orWhereRaw('(SELECT COUNT(*) FROM promo_code_redemptions WHERE promo_code_id = promo_codes.id) < max_uses');
        });
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Total number of times this code has been redeemed.
     */
    public function timesRedeemed(): int
    {
        return $this->redemptions()->count();
    }

    /**
     * Number of times a specific user has redeemed this code.
     */
    public function redemptionCountForUser(User $user): int
    {
        return $this->redemptions()->where('user_id', $user->id)->count();
    }

    /**
     * Single source of truth for "can this user use this code on this plan?".
     *
     * Returns [ok bool, reason string]. The reason string is shown to
     * the end user in the checkout JSON response, so it must be friendly
     * Bahasa Indonesia (matches the rest of the storefront copy).
     *
     * @return array{0:bool, 1:string}
     */
    public function isRedeemableBy(User $user, ?SubscriptionPlan $plan = null): array
    {
        // 1. Active flag
        if (! $this->is_active) {
            return [false, 'Kode promo ini tidak aktif.'];
        }

        // 2. Activation window
        $now = now();
        if ($this->starts_at && $this->starts_at->greaterThan($now)) {
            return [false, 'Kode promo ini belum dapat digunakan.'];
        }
        if ($this->expires_at && $this->expires_at->lessThanOrEqualTo($now)) {
            return [false, 'Kode promo ini sudah kadaluarsa.'];
        }

        // 3. Global usage cap
        if ($this->max_uses !== null && $this->timesRedeemed() >= $this->max_uses) {
            return [false, 'Kuota kode promo ini sudah habis.'];
        }

        // 4. Per-user cap
        if ($this->max_uses_per_user > 0
            && $this->redemptionCountForUser($user) >= $this->max_uses_per_user) {
            return [false, 'Kamu sudah menggunakan kode promo ini.'];
        }

        // 5. Plan eligibility — applies_to_plans NULL means "any plan".
        if ($plan !== null
            && is_array($this->applies_to_plans)
            && count($this->applies_to_plans) > 0
            && ! in_array((int) $plan->id, array_map('intval', $this->applies_to_plans), true)) {
            return [false, 'Kode promo ini tidak berlaku untuk paket yang dipilih.'];
        }

        // 6. First-time-only — only users with zero past PAID subscriptions.
        //    Free-plan rows (amount = 0) are excluded so seeding the Free plan
        //    doesn't burn the WELCOME promo.
        if ($this->is_first_time_only) {
            $paidPastSubs = $user->subscriptions()
                ->where('amount', '>', 0)
                ->whereIn('status', ['active', 'cancelled', 'expired'])
                ->count();

            if ($paidPastSubs > 0) {
                return [false, 'Kode promo ini hanya untuk pengguna baru.'];
            }
        }

        return [true, 'OK'];
    }

    /**
     * Compute the discount applied to a given base price in IDR.
     *
     * Returns the absolute IDR amount to subtract (never negative,
     * never exceeds the base price). The "free_trial_days" type returns
     * the full base price (full month free) — callers that want a true
     * trial-extension instead of a price waiver should treat the
     * discount_value as a number of days separately.
     */
    public function computeDiscount(float $basePrice): float
    {
        if ($basePrice <= 0) {
            return 0.0;
        }

        $discount = match ($this->discount_type) {
            self::TYPE_PERCENTAGE => $basePrice * (min(100, max(0, (float) $this->discount_value)) / 100),
            self::TYPE_FIXED      => (float) $this->discount_value,
            // free_trial_days waives the equivalent of one billing period
            // when applied at checkout (the actual extra days are added
            // to ends_at by the controller).
            self::TYPE_FREE_TRIAL_DAYS => $basePrice,
            default => 0.0,
        };

        // Clamp: never negative, never more than the base price.
        return (float) min($basePrice, max(0, $discount));
    }

    /**
     * Record a redemption. Always writes — assumes the caller has
     * already run isRedeemableBy() / validateCode().
     */
    public function recordRedemption(User $user, ?Subscription $subscription, float $discountApplied): PromoCodeRedemption
    {
        return $this->redemptions()->create([
            'user_id'              => $user->id,
            'subscription_id'      => $subscription?->id,
            'discount_applied_idr' => $discountApplied,
            'redeemed_at'          => now(),
        ]);
    }

    /**
     * Friendly label for the discount, used by admin index + checkout UI.
     */
    public function getDiscountLabelAttribute(): string
    {
        return match ($this->discount_type) {
            self::TYPE_PERCENTAGE      => rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.') . '%',
            self::TYPE_FIXED           => 'Rp ' . number_format((float) $this->discount_value, 0, ',', '.'),
            self::TYPE_FREE_TRIAL_DAYS => ((int) $this->discount_value) . ' hari gratis',
            default                    => (string) $this->discount_value,
        };
    }

    /**
     * Whether the code is currently in its activation window. Used by
     * the admin list to render the right badge colour without re-running
     * a full query.
     */
    public function isWithinWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->starts_at && $this->starts_at->greaterThan($at)) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->lessThanOrEqualTo($at)) {
            return false;
        }

        return true;
    }
}
