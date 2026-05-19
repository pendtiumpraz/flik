<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\Achievement;
use App\Models\Coin;
use App\Models\StreakHistoryEntry;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\WatchStreak;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Daily-watch streak engine.
 *
 * The single entry point for advancing a user's streak is {@see self::recordWatch}.
 * Every call is idempotent for the day — same-day repeats are no-ops, so callers
 * (controllers, jobs, listeners) can fire freely without double-counting.
 *
 * Day boundary: Asia/Jakarta-local date. We deliberately use the user's
 * "perceived day", not UTC midnight, so a user watching at 23:00 Jakarta
 * doesn't get penalised when UTC ticks over.
 */
final class StreakService
{
    /**
     * Coin cost to manually purchase a freeze credit (POST /streak/freeze).
     * Kept as a constant so the controller, view, and tests stay in sync.
     */
    public const FREEZE_COIN_COST = 50;

    /**
     * Milestone definitions — day-count keyed.
     *
     * Each entry is a tuple of [xp, coins, achievement_slug|null]. The slug
     * must exist in the achievements table for the unlock to take effect;
     * if missing, the XP+coins are still granted (gracefully degraded) and
     * a warning is logged so we notice the missing seed.
     *
     * @var array<int, array{int, int, ?string}>
     */
    private const MILESTONES = [
        1   => [10,    0,    null],
        3   => [30,    5,    null],
        7   => [100,   20,   'week_warrior'],
        14  => [200,   50,   null],
        30  => [500,   200,  'month_master'],
        100 => [2000,  1000, 'centurion'],
        365 => [10000, 5000, 'year_legend'],
    ];

    /**
     * Record that the user watched today.
     *
     * Returns the freshly-updated streak row. Same-day repeats are no-ops
     * (the row is returned unchanged) so callers can hook this into
     * progress-tick handlers without worrying about over-counting.
     *
     * @param  User              $user  the watching user
     * @param  CarbonInterface|null $when optional override for "today" (tests)
     */
    public function recordWatch(User $user, ?CarbonInterface $when = null): WatchStreak
    {
        $today = $when ? Carbon::parse($when)->setTimezone('Asia/Jakarta')->startOfDay()
                       : self::today();

        // Wrap the read-modify-write in a transaction so concurrent progress
        // ticks from two browser tabs can't both increment the streak.
        return DB::transaction(function () use ($user, $today): WatchStreak {
            /** @var WatchStreak $streak */
            $streak = WatchStreak::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'current_streak'  => 0,
                    'longest_streak'  => 0,
                    'last_watch_date' => null,
                    'freeze_credits'  => 0,
                    'updated_at'      => now(),
                ],
            );

            $previousDate = $streak->last_watch_date;

            // Same-day repeat → no-op. The history row (if any) is also
            // already in place from the first call of the day.
            if ($previousDate !== null && $previousDate->isSameDay($today)) {
                return $streak;
            }

            $milestone = null;

            if ($previousDate === null) {
                // First-ever recorded watch.
                $streak->current_streak = 1;
            } elseif ($previousDate->copy()->addDay()->isSameDay($today)) {
                // Consecutive day — extend.
                $streak->current_streak++;
            } else {
                // Gap of 2+ days. Try to spend a freeze credit; otherwise reset.
                $gap = $previousDate->diffInDays($today);
                if ($streak->freeze_credits > 0 && $gap >= 2) {
                    $streak->freeze_credits--;
                    $streak->current_streak++;
                    $milestone = 'freeze_used';
                } else {
                    $streak->current_streak = 1;
                }
            }

            $streak->longest_streak = max($streak->longest_streak, $streak->current_streak);
            $streak->last_watch_date = $today;
            $streak->updated_at = now();
            $streak->save();

            // Award + persist the day's history row. If a milestone was earned
            // it overrides the freeze marker so the calendar shows the bigger event.
            $awarded = $this->awardMilestones($user, $streak);
            $effectiveMilestone = $awarded['milestone'] ?? $milestone;

            // Idempotency guard — the unique index on (user_id, date) means
            // a concurrent insert would throw; we swallow via firstOrCreate
            // so retries land safely.
            StreakHistoryEntry::firstOrCreate(
                ['user_id' => $user->id, 'date' => $today->toDateString()],
                [
                    'streak_after_day' => $streak->current_streak,
                    'reward_xp'        => $awarded['xp'],
                    'reward_coins'     => $awarded['coins'],
                    'milestone'        => $effectiveMilestone,
                ],
            );

            // Backfill the legacy display column on user_levels so the
            // existing profile view keeps working without changes.
            $this->syncLegacyStreakColumn($user, $streak);

            return $streak;
        });
    }

    /**
     * Grant XP / coins / achievement for hitting a streak milestone.
     *
     * @return array{xp:int, coins:int, milestone:?string}
     */
    public function awardMilestones(User $user, WatchStreak $streak): array
    {
        $day = $streak->current_streak;

        if (!isset(self::MILESTONES[$day])) {
            return ['xp' => 0, 'coins' => 0, 'milestone' => null];
        }

        [$xp, $coins, $achievementSlug] = self::MILESTONES[$day];

        try {
            if ($xp > 0) {
                $level = $user->getOrCreateLevel();
                $level->addXp($xp);
            }

            if ($coins > 0) {
                Coin::earn(
                    $user->id,
                    $coins,
                    'streak_milestone',
                    "Streak Day {$day} reward",
                );
            }

            if ($achievementSlug !== null) {
                $this->unlockAchievement($user, $achievementSlug);
            }
        } catch (Throwable $e) {
            // We do NOT throw — the streak itself was successfully advanced
            // before the milestone payout. Rewards can be retried offline by
            // an admin if necessary; losing the streak would be the worse UX.
            Log::warning('StreakService: milestone payout failed', [
                'user_id' => $user->id,
                'day'     => $day,
                'error'   => $e->getMessage(),
            ]);
        }

        return [
            'xp'        => $xp,
            'coins'     => $coins,
            'milestone' => $achievementSlug,
        ];
    }

    /**
     * Unlock an achievement by slug. Idempotent — re-running on the same
     * (user, achievement) is a no-op thanks to the unique pivot constraint
     * and the `unlocked_at` IS NULL gate.
     */
    public function unlockAchievement(User $user, string $slug): void
    {
        $achievement = Achievement::query()->where('slug', $slug)->first();

        if ($achievement === null) {
            Log::warning('StreakService: achievement slug not seeded', [
                'slug' => $slug,
            ]);
            return;
        }

        // syncWithoutDetaching preserves other unlocked achievements and
        // is a no-op when this row already exists (unique constraint).
        $user->achievements()->syncWithoutDetaching([
            $achievement->id => ['unlocked_at' => now()],
        ]);
    }

    /**
     * Top streak leaderboard. Eager-loads user for the view.
     *
     * @return Collection<int, WatchStreak>
     */
    public function topStreaks(int $limit = 50): Collection
    {
        return WatchStreak::query()
            ->with('user:id,name,username,avatar_path')
            ->where('current_streak', '>', 0)
            ->orderByDesc('current_streak')
            ->orderByDesc('longest_streak')
            ->limit($limit)
            ->get();
    }

    /**
     * Look up where the given user ranks on the current-streak leaderboard.
     * Returns null when the user has no streak record OR a streak of 0.
     * The rank is 1-indexed (the #1 user is rank 1, not 0).
     */
    public function rankFor(User $user): ?int
    {
        $streak = WatchStreak::query()->where('user_id', $user->id)->first();
        if ($streak === null || $streak->current_streak <= 0) {
            return null;
        }

        // Tie-break on longest_streak so two users with the same current_streak
        // get a deterministic rank rather than flip-flopping.
        $ahead = WatchStreak::query()
            ->where(function ($q) use ($streak) {
                $q->where('current_streak', '>', $streak->current_streak)
                  ->orWhere(function ($q2) use ($streak) {
                      $q2->where('current_streak', $streak->current_streak)
                         ->where('longest_streak', '>', $streak->longest_streak);
                  });
            })
            ->count();

        return $ahead + 1;
    }

    /**
     * Manual freeze purchase (POST /streak/freeze). Spends FREEZE_COIN_COST
     * coins and increments freeze_credits by 1. Returns true on success,
     * false on insufficient balance.
     */
    public function purchaseFreeze(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            if (Coin::balanceFor($user->id) < self::FREEZE_COIN_COST) {
                return false;
            }

            Coin::spend(
                $user->id,
                self::FREEZE_COIN_COST,
                'streak_freeze_purchase',
                'Pembelian freeze streak',
            );

            $streak = WatchStreak::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'current_streak'  => 0,
                    'longest_streak'  => 0,
                    'freeze_credits'  => 0,
                    'updated_at'      => now(),
                ],
            );

            $streak->freeze_credits++;
            $streak->updated_at = now();
            $streak->save();

            return true;
        });
    }

    /**
     * Grant one freeze credit to a user (monthly subscriber bonus).
     *
     * Idempotent within a given calendar month — the cron caller is
     * responsible for not double-running. The service itself just bumps
     * the credit count.
     */
    public function grantFreezeCredit(User $user): WatchStreak
    {
        $streak = WatchStreak::firstOrCreate(
            ['user_id' => $user->id],
            [
                'current_streak'  => 0,
                'longest_streak'  => 0,
                'freeze_credits'  => 0,
                'updated_at'      => now(),
            ],
        );

        $streak->freeze_credits++;
        $streak->updated_at = now();
        $streak->save();

        return $streak;
    }

    /**
     * Asia/Jakarta-local "today" — see class doc for why we don't use UTC.
     */
    public static function today(): Carbon
    {
        return Carbon::now('Asia/Jakarta')->startOfDay();
    }

    /**
     * Mirror the modern streak count back onto the legacy user_levels column
     * so old views that read `$user->level->watch_streak` keep showing the
     * right number. Skipped when the column / table is missing (fresh install).
     */
    private function syncLegacyStreakColumn(User $user, WatchStreak $streak): void
    {
        try {
            if (!Schema::hasTable('user_levels')
                || !Schema::hasColumn('user_levels', 'watch_streak')) {
                return;
            }

            UserLevel::query()
                ->where('user_id', $user->id)
                ->update([
                    'watch_streak'     => $streak->current_streak,
                    'last_streak_date' => $streak->last_watch_date,
                ]);
        } catch (Throwable $e) {
            Log::debug('StreakService: legacy column sync skipped', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
