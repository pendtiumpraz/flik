<?php

namespace App\Http\Controllers;

use App\Models\Coin;
use App\Models\UserLevel;
use App\Models\WatchStreak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RewardsController
 * --------------------------------------------------------------------------
 * Owns the /rewards page and the once-per-day "daily check-in" XP/coin
 * bonus.
 *
 * IMPORTANT — separation of concerns vs. the watch-streak system:
 *
 *   - Watch-streak data (current day count, longest streak, freeze
 *     credits) is owned by `watch_streaks` table via {@see \App\Services\Gamification\StreakService}.
 *     Streak rows are advanced from {@see \App\Http\Controllers\WatchHistoryController::updateProgress}
 *     when the player heartbeats real watching. THIS controller MUST NOT
 *     mutate `user_levels.watch_streak` / `user_levels.last_streak_date`
 *     anymore — those columns are mirrored by StreakService for the
 *     benefit of legacy views.
 *
 *   - The daily check-in here is a SEPARATE concept: a per-calendar-day
 *     small XP+coin bonus that any authed user can manually claim from
 *     the /rewards page, regardless of whether they actually watched
 *     something today. The 7-day rolling reward calendar was removed
 *     because it duplicated the milestone payouts in
 *     {@see \App\Services\Gamification\StreakService::MILESTONES}.
 */
class RewardsController extends Controller
{
    /** Flat daily check-in payout — kept small so it doesn't double-pay the streak system. */
    private const DAILY_COIN_REWARD = 10;

    private const DAILY_XP_REWARD = 10;

    public function index()
    {
        $user = auth()->user();
        $level = $user->getOrCreateLevel();
        $coinBalance = $user->coin_balance;

        // Check if already claimed today
        $today = now()->toDateString();
        $claimedToday = DB::table('daily_rewards')
            ->where('user_id', $user->id)
            ->where('reward_date', $today)
            ->exists();

        // Watch-streak data is the source-of-truth from StreakService;
        // we just READ it for display. The legacy `$level->watch_streak`
        // mirror is updated by StreakService::syncLegacyStreakColumn().
        $watchStreak = WatchStreak::query()->where('user_id', $user->id)->first();
        $streakDay = (int) ($watchStreak->current_streak ?? 0);

        // Achievements
        $allAchievements = \App\Models\Achievement::active()->get();
        $unlockedIds = $user->achievements->pluck('id')->toArray();

        // Leaderboard
        $leaderboard = UserLevel::with('user')
            ->orderByDesc('level')
            ->orderByDesc('xp')
            ->take(10)
            ->get();

        // Daily-reward payout amounts so the view can echo them.
        $dailyCoinReward = self::DAILY_COIN_REWARD;
        $dailyXpReward = self::DAILY_XP_REWARD;

        return view('rewards.index', compact(
            'level', 'coinBalance', 'claimedToday', 'streakDay',
            'dailyCoinReward', 'dailyXpReward',
            'allAchievements', 'unlockedIds', 'leaderboard'
        ));
    }

    public function claimDaily()
    {
        $user = auth()->user();
        $level = $user->getOrCreateLevel();
        $today = now()->toDateString();

        // Check already claimed
        $alreadyClaimed = DB::table('daily_rewards')
            ->where('user_id', $user->id)
            ->where('reward_date', $today)
            ->exists();

        if ($alreadyClaimed) {
            return back()->with('error', 'Kamu sudah klaim reward hari ini!');
        }

        // NOTE: we deliberately do NOT touch user_levels.watch_streak /
        // user_levels.last_streak_date here anymore. The watch-streak
        // system is owned by App\Services\Gamification\StreakService
        // (table: watch_streaks). Daily check-in is a separate
        // gamification concept — a flat XP+coin bonus for opening the
        // app, independent of actually watching anything.

        // Record daily reward (idempotency guarded by DB unique key —
        // catch the duplicate-key race so a double-submit returns a
        // friendly flash instead of a 500).
        try {
            DB::table('daily_rewards')->insert([
                'user_id'      => $user->id,
                'reward_date'  => $today,
                'day_number'   => 1, // legacy column; daily reward is now flat
                'coins_earned' => self::DAILY_COIN_REWARD,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // Most likely a unique-violation from a parallel double-claim.
            return back()->with('error', 'Kamu sudah klaim reward hari ini!');
        }

        // Add coins (write-only ledger)
        Coin::earn($user->id, self::DAILY_COIN_REWARD, 'daily_reward', 'Daily check-in reward');

        // Add XP
        $level->addXp(self::DAILY_XP_REWARD);

        return back()->with(
            'success',
            "🎉 Daily check-in: +" . self::DAILY_COIN_REWARD . " coins, +" . self::DAILY_XP_REWARD . " XP!"
        );
    }
}
