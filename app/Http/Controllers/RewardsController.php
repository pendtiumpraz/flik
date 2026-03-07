<?php

namespace App\Http\Controllers;

use App\Models\Coin;
use App\Models\UserLevel;
use App\Models\DailyReward;
use Illuminate\Http\Request;

class RewardsController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $level = $user->getOrCreateLevel();
        $coinBalance = $user->coin_balance;

        // Check if already claimed today
        $today = now()->toDateString();
        $claimedToday = \DB::table('daily_rewards')
            ->where('user_id', $user->id)
            ->where('reward_date', $today)
            ->exists();

        // Streak info
        $streakDay = min($level->watch_streak + 1, 7); // Day 1-7
        $rewards = [
            1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 30, 6 => 50, 7 => 100,
        ];

        // Achievements
        $allAchievements = \App\Models\Achievement::active()->get();
        $unlockedIds = $user->achievements->pluck('id')->toArray();

        // Leaderboard
        $leaderboard = UserLevel::with('user')
            ->orderByDesc('level')
            ->orderByDesc('xp')
            ->take(10)
            ->get();

        return view('rewards.index', compact(
            'level', 'coinBalance', 'claimedToday', 'streakDay',
            'rewards', 'allAchievements', 'unlockedIds', 'leaderboard'
        ));
    }

    public function claimDaily()
    {
        $user = auth()->user();
        $level = $user->getOrCreateLevel();
        $today = now()->toDateString();

        // Check already claimed
        $alreadyClaimed = \DB::table('daily_rewards')
            ->where('user_id', $user->id)
            ->where('reward_date', $today)
            ->exists();

        if ($alreadyClaimed) {
            return back()->with('error', 'Kamu sudah klaim reward hari ini!');
        }

        // Calculate streak
        $yesterday = now()->subDay()->toDateString();
        if ($level->last_streak_date && $level->last_streak_date->toDateString() === $yesterday) {
            $level->watch_streak = min($level->watch_streak + 1, 7);
        } else if (!$level->last_streak_date || $level->last_streak_date->toDateString() !== $today) {
            $level->watch_streak = 1;
        }
        $level->last_streak_date = $today;
        $level->save();

        // Rewards per streak day
        $rewards = [1 => 5, 2 => 10, 3 => 15, 4 => 20, 5 => 30, 6 => 50, 7 => 100];
        $day = min($level->watch_streak, 7);
        $coins = $rewards[$day];

        // Record daily reward
        \DB::table('daily_rewards')->insert([
            'user_id' => $user->id,
            'reward_date' => $today,
            'day_number' => $day,
            'coins_earned' => $coins,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add coins
        Coin::earn($user->id, $coins, 'daily_reward', "Daily reward Day {$day}");

        // Add XP
        $level->addXp(10);

        return back()->with('success', "🎉 Day {$day} reward: +{$coins} coins! Streak: {$level->watch_streak} hari");
    }
}
