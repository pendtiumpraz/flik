<?php

declare(strict_types=1);

namespace App\Services\Referrals;

use App\Models\Coin;
use App\Models\Notification;
use App\Models\ReferralConversion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ReferralService — single entry point for the refer-a-friend lifecycle.
 *
 * Three-phase by design (mirrors the conversion's 3 active states):
 *
 *   attribute()      → /r/{code} hit → register → row created in `pending`
 *   markQualified()  → onboarding done / first watch → both sides get a
 *                      small thank-you coin grant (default 100 coins each)
 *   markRewarded()   → referred user activates a paid subscription →
 *                      referrer gets a bigger bonus (500 coins + 50 XP)
 *                      and the denormalised counters bump.
 *
 * Every mutation is wrapped in a DB transaction + idempotency guard
 * (firstOrCreate / unique constraint / status check) so a webhook
 * retry, a double-tap on the verification link, or a race between two
 * concurrent attribution attempts can never double-pay rewards.
 *
 * Rewards are intentionally NOT thrown — every helper logs + returns
 * gracefully on failure so a missing role table / coin ledger never
 * breaks the auth flow that called us.
 */
class ReferralService
{
    /**
     * Tunables. Stored as class constants so the values are visible in
     * the dashboard explainer copy AND honoured by the service. If you
     * change one, also update `resources/views/referrals/dashboard.blade.php`.
     */
    public const QUALIFY_REFERRER_COINS = 100;
    public const QUALIFY_REFERRED_COINS = 100;
    public const REWARD_REFERRER_COINS  = 500;
    public const REWARD_REFERRER_XP     = 50;
    public const REWARD_REFERRED_COINS  = 200;
    public const REWARD_REFERRED_XP     = 20;

    /**
     * Phase 1 — record the (referrer → referred) attribution.
     *
     * Idempotent: the unique index on
     * (referrer_user_id, referred_user_id) guarantees we never write a
     * duplicate even under a registration race. Returns the existing
     * row when one is already present.
     *
     * Rejects:
     *   - empty / invalid code
     *   - self-referral (newUser IS the referrer)
     *   - newUser already has a `referred_by_user_id`
     */
    public function attribute(string $code, User $newUser): ?ReferralConversion
    {
        $normalised = strtoupper(trim($code));
        if ($normalised === '' || ! Schema::hasTable('referral_conversions')) {
            return null;
        }

        // newUser already attributed (re-running attribute is a no-op).
        if (! empty($newUser->referred_by_user_id)) {
            return ReferralConversion::query()
                ->where('referrer_user_id', $newUser->referred_by_user_id)
                ->where('referred_user_id', $newUser->id)
                ->first();
        }

        /** @var User|null $referrer */
        $referrer = User::query()->where('referral_code', $normalised)->first();
        if ($referrer === null) {
            return null;
        }

        // Self-referral guard — someone pasting their own code back in.
        if ($referrer->id === $newUser->id) {
            return null;
        }

        return DB::transaction(function () use ($referrer, $newUser): ReferralConversion {
            // Stamp the back-edge on the new user. forceFill because
            // `referred_by_user_id` is not in $fillable on purpose.
            $newUser->forceFill(['referred_by_user_id' => $referrer->id])->save();

            // The unique index makes this race-safe.
            return ReferralConversion::firstOrCreate(
                [
                    'referrer_user_id' => $referrer->id,
                    'referred_user_id' => $newUser->id,
                ],
                [
                    'status' => ReferralConversion::STATUS_PENDING,
                ]
            );
        });
    }

    /**
     * Phase 2 — referred user finished onboarding (or first watch). Both
     * sides get a small coin grant.
     *
     * Idempotent against the status — re-calling this on an already
     * qualified/rewarded conversion is a no-op.
     */
    public function markQualified(User $newUser): void
    {
        if (! Schema::hasTable('referral_conversions') || empty($newUser->referred_by_user_id)) {
            return;
        }

        try {
            DB::transaction(function () use ($newUser): void {
                /** @var ReferralConversion|null $conv */
                $conv = ReferralConversion::query()
                    ->where('referred_user_id', $newUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($conv === null || $conv->status !== ReferralConversion::STATUS_PENDING) {
                    return;
                }

                $conv->forceFill([
                    'status'                => ReferralConversion::STATUS_QUALIFIED,
                    'qualified_at'          => now(),
                    'referrer_reward_coins' => self::QUALIFY_REFERRER_COINS,
                    'referred_reward_coins' => self::QUALIFY_REFERRED_COINS,
                ])->save();

                $this->grantCoins(
                    $conv->referrer_user_id,
                    self::QUALIFY_REFERRER_COINS,
                    'referral_qualified_referrer',
                    'Bonus referral: temanmu menyelesaikan onboarding'
                );

                $this->grantCoins(
                    $newUser->id,
                    self::QUALIFY_REFERRED_COINS,
                    'referral_qualified_referred',
                    'Bonus selamat datang dari referral'
                );

                $this->notifyReferrer(
                    $conv->referrer_user_id,
                    '🎉 Referralmu Aktif!',
                    'Temanmu menyelesaikan onboarding. Kamu dapat '
                        .self::QUALIFY_REFERRER_COINS.' koin!'
                );
            });
        } catch (\Throwable $e) {
            Log::warning('ReferralService::markQualified failed', [
                'user_id' => $newUser->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 3 — referred user activated a paid subscription. Referrer
     * gets the big reward + denormalised counters bump.
     *
     * Tolerates being called twice (idempotent on status===rewarded).
     * Tolerates the conversion never having reached `qualified` — if a
     * user goes straight from signup to paying, we promote them through
     * the qualified state inline so the audit trail stays clean.
     */
    public function markRewarded(User $newUser, Subscription $sub): void
    {
        if (! Schema::hasTable('referral_conversions') || empty($newUser->referred_by_user_id)) {
            return;
        }

        try {
            DB::transaction(function () use ($newUser, $sub): void {
                /** @var ReferralConversion|null $conv */
                $conv = ReferralConversion::query()
                    ->where('referred_user_id', $newUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($conv === null) {
                    return;
                }

                // Already rewarded → no-op.
                if ($conv->status === ReferralConversion::STATUS_REWARDED) {
                    return;
                }

                // Skipped the qualified state? Backfill it inline so the
                // audit trail shows both qualified_at and rewarded_at.
                $now = now();
                $conv->forceFill([
                    'status'                => ReferralConversion::STATUS_REWARDED,
                    'qualified_at'          => $conv->qualified_at ?? $now,
                    'rewarded_at'           => $now,
                    'subscription_id'       => $sub->id,
                    'referrer_reward_coins' => self::QUALIFY_REFERRER_COINS + self::REWARD_REFERRER_COINS,
                    'referrer_reward_xp'    => self::REWARD_REFERRER_XP,
                    'referred_reward_coins' => self::QUALIFY_REFERRED_COINS + self::REWARD_REFERRED_COINS,
                    'referred_reward_xp'    => self::REWARD_REFERRED_XP,
                ])->save();

                // Grant rewards. If we already paid the qualification
                // bonus, only pay the delta now — the conv row tracks
                // the cumulative amount so we never double-grant.
                $this->grantCoins(
                    $conv->referrer_user_id,
                    self::REWARD_REFERRER_COINS,
                    'referral_rewarded_referrer',
                    'Bonus referral: temanmu berlangganan paket berbayar'
                );

                $this->grantCoins(
                    $newUser->id,
                    self::REWARD_REFERRED_COINS,
                    'referral_rewarded_referred',
                    'Bonus tambahan dari referral'
                );

                $this->grantXp($conv->referrer_user_id, self::REWARD_REFERRER_XP);
                $this->grantXp($newUser->id, self::REWARD_REFERRED_XP);

                // Bump the denormalised counters on the referrer. Done in
                // SQL so concurrent rewards never lose increments.
                User::query()
                    ->whereKey($conv->referrer_user_id)
                    ->update([
                        'referrals_count'           => DB::raw('referrals_count + 1'),
                        'referrals_rewards_earned'  => DB::raw('referrals_rewards_earned + '.self::REWARD_REFERRER_COINS),
                    ]);

                $this->notifyReferrer(
                    $conv->referrer_user_id,
                    '💰 Bonus Referral Besar!',
                    'Temanmu berlangganan paket berbayar. Kamu dapat '
                        .self::REWARD_REFERRER_COINS.' koin + '
                        .self::REWARD_REFERRER_XP.' XP!'
                );
            });
        } catch (\Throwable $e) {
            Log::warning('ReferralService::markRewarded failed', [
                'user_id'         => $newUser->id,
                'subscription_id' => $sub->id ?? null,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cron entry point — expire pending referrals older than $daysOld
     * days. Returns the number of rows updated for the command output.
     */
    public function expirePending(int $daysOld = 90): int
    {
        if (! Schema::hasTable('referral_conversions')) {
            return 0;
        }

        $cutoff = now()->subDays(max(1, $daysOld));

        return (int) ReferralConversion::query()
            ->where('status', ReferralConversion::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update([
                'status'     => ReferralConversion::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    // ── Internal helpers ────────────────────────────────────────────

    private function grantCoins(int $userId, int $amount, string $type, string $description): void
    {
        if ($amount <= 0 || ! class_exists(Coin::class)) {
            return;
        }

        try {
            Coin::earn($userId, $amount, $type, $description);
        } catch (\Throwable $e) {
            Log::warning('ReferralService: coin grant failed', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function grantXp(int $userId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        try {
            $user = User::find($userId);
            if ($user === null) {
                return;
            }
            $user->getOrCreateLevel()->addXp($amount);
        } catch (\Throwable $e) {
            Log::warning('ReferralService: XP grant failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function notifyReferrer(int $userId, string $title, string $message): void
    {
        try {
            if (! Schema::hasTable('notifications')) {
                return;
            }

            Notification::create([
                'user_id'    => $userId,
                'type'       => 'referral',
                'title'      => $title,
                'message'    => $message,
                'action_url' => '/referrals',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReferralService: in-app notification failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
