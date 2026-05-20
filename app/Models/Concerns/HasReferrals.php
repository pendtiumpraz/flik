<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ReferralConversion;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Refer-a-friend behaviour for {@see \App\Models\User}.
 *
 * Kept in a trait so the User model stays focused on auth/roles/payments.
 * Persistence lives on the users table (`referral_code`,
 * `referred_by_user_id`, denormalised counters) and the
 * `referral_conversions` ledger.
 *
 * Code generation strategy:
 *   - 12 chars, [A-Z0-9], excluding ambiguous glyphs (O/0/I/1) so a
 *     recipient can't typo their way into someone else's referral.
 *   - We loop with a unique-lookup retry rather than relying on a UUID
 *     so the share URL stays short + memorable.
 *
 * Every helper is defensive against fresh installs where the migration
 * has not yet run — Schema::hasColumn / hasTable guards mean a missing
 * column returns 0 / null instead of throwing.
 */
trait HasReferrals
{
    /**
     * Conversions where THIS user is the referrer. The "referrals" of
     * this user, i.e. the people they brought in.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(ReferralConversion::class, 'referrer_user_id');
    }

    /**
     * The user (if any) who referred THIS user. Inverse of `referrals`.
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /**
     * Convenience: count of confirmed referrals (qualified + rewarded).
     * Reads the denormalised column first so we don't COUNT() on every
     * page load; falls back to the live query when the column is NULL
     * (e.g. a legacy row that pre-dates the counter migration).
     */
    public function referralsCount(): int
    {
        if (! Schema::hasColumn('users', 'referrals_count')) {
            return 0;
        }

        $cached = (int) ($this->attributes['referrals_count'] ?? 0);
        if ($cached > 0) {
            return $cached;
        }

        // Fall back to a live count for the first read; this hydrates
        // the cached value implicitly on the next ReferralService
        // invocation that bumps the counter.
        if (! Schema::hasTable('referral_conversions')) {
            return 0;
        }

        return (int) ReferralConversion::query()
            ->where('referrer_user_id', $this->id)
            ->whereIn('status', ['qualified', 'rewarded'])
            ->count();
    }

    /**
     * Public share URL for this user. Returns the code-less base URL
     * when the user has no code yet (fresh install, legacy row).
     */
    public function referralsLink(): string
    {
        $code = $this->attributes['referral_code'] ?? null;

        if (empty($code)) {
            return url('/r');
        }

        return url('/r/'.$code);
    }

    /**
     * Generate (and persist) a unique referral code for this user.
     *
     * Idempotent: returns the existing code when one is already set.
     * Otherwise loops with a unique-lookup retry up to 8 times before
     * falling back to a longer Str::random to guarantee progress.
     */
    public function generateReferralCode(): string
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            return '';
        }

        $existing = $this->attributes['referral_code'] ?? null;
        if (! empty($existing)) {
            return (string) $existing;
        }

        $code = self::makeUniqueReferralCode();

        // Bypass the mass-assignment guard — `referral_code` is not in
        // $fillable on purpose (only the observer / this helper writes
        // it). Use forceFill so a stale $guarded list never blocks us.
        $this->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * Pure helper — produce a candidate 12-char code that does NOT
     * collide with any existing row in `users.referral_code`.
     *
     * Excludes ambiguous glyphs (O/0/I/1/L) for receipt-friendliness.
     */
    protected static function makeUniqueReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < 12; $i++) {
                $candidate .= $alphabet[random_int(0, $len - 1)];
            }

            // Re-query each loop; a race here is acceptable — the
            // unique index on the column is the actual line of defence.
            $exists = User::query()
                ->where('referral_code', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        // Last-resort: longer random string. Statistically impossible
        // to collide but keeps us from spinning forever on a bad PRNG.
        return strtoupper(Str::random(16));
    }
}
