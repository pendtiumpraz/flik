<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            // Watch milestones
            ['name' => 'First Watch', 'slug' => 'first-watch', 'description' => 'Tonton film pertamamu di FLiK', 'icon' => '🎬', 'coin_reward' => 10, 'xp_reward' => 20, 'condition_type' => 'watch_count', 'condition_value' => 1, 'tier' => 'bronze'],
            ['name' => 'Movie Buff', 'slug' => 'movie-buff', 'description' => 'Tonton 10 film', 'icon' => '🎥', 'coin_reward' => 50, 'xp_reward' => 100, 'condition_type' => 'watch_count', 'condition_value' => 10, 'tier' => 'silver'],
            ['name' => 'Cinema Lover', 'slug' => 'cinema-lover', 'description' => 'Tonton 50 film', 'icon' => '🍿', 'coin_reward' => 200, 'xp_reward' => 500, 'condition_type' => 'watch_count', 'condition_value' => 50, 'tier' => 'gold'],
            ['name' => 'Marathon Master', 'slug' => 'marathon-master', 'description' => 'Tonton 100 film', 'icon' => '🏆', 'coin_reward' => 500, 'xp_reward' => 1000, 'condition_type' => 'watch_count', 'condition_value' => 100, 'tier' => 'platinum'],

            // Rating milestones
            ['name' => 'Film Critic', 'slug' => 'film-critic', 'description' => 'Berikan rating pertamamu', 'icon' => '⭐', 'coin_reward' => 10, 'xp_reward' => 20, 'condition_type' => 'rating_count', 'condition_value' => 1, 'tier' => 'bronze'],
            ['name' => 'Expert Reviewer', 'slug' => 'expert-reviewer', 'description' => 'Berikan 25 rating', 'icon' => '📝', 'coin_reward' => 100, 'xp_reward' => 250, 'condition_type' => 'rating_count', 'condition_value' => 25, 'tier' => 'gold'],

            // Streak milestones
            ['name' => 'Consistent', 'slug' => 'consistent', 'description' => 'Login streak 3 hari berturut-turut', 'icon' => '🔥', 'coin_reward' => 30, 'xp_reward' => 50, 'condition_type' => 'streak', 'condition_value' => 3, 'tier' => 'bronze'],
            ['name' => 'Dedicated Fan', 'slug' => 'dedicated-fan', 'description' => 'Login streak 7 hari berturut-turut', 'icon' => '💪', 'coin_reward' => 100, 'xp_reward' => 200, 'condition_type' => 'streak', 'condition_value' => 7, 'tier' => 'silver'],
            ['name' => 'FLiK Addict', 'slug' => 'flik-addict', 'description' => 'Login streak 30 hari berturut-turut', 'icon' => '👑', 'coin_reward' => 500, 'xp_reward' => 1000, 'condition_type' => 'streak', 'condition_value' => 30, 'tier' => 'platinum'],

            // Comment milestones
            ['name' => 'Conversationalist', 'slug' => 'conversationalist', 'description' => 'Tulis 10 komentar', 'icon' => '💬', 'coin_reward' => 30, 'xp_reward' => 50, 'condition_type' => 'comment_count', 'condition_value' => 10, 'tier' => 'bronze'],

            // Watchlist milestones
            ['name' => 'Collector', 'slug' => 'collector', 'description' => 'Tambahkan 20 film ke watchlist', 'icon' => '📚', 'coin_reward' => 50, 'xp_reward' => 100, 'condition_type' => 'watchlist_count', 'condition_value' => 20, 'tier' => 'silver'],

            // Genre explorer
            ['name' => 'Genre Explorer', 'slug' => 'genre-explorer', 'description' => 'Tonton film dari 10 genre berbeda', 'icon' => '🧭', 'coin_reward' => 100, 'xp_reward' => 200, 'condition_type' => 'genre_count', 'condition_value' => 10, 'tier' => 'gold'],

            // ━━━ Watch-streak milestones (StreakService::MILESTONES) ━━━
            // Slugs use snake_case to match the literal strings in StreakService.
            // The rewards listed here are bonus pivot-unlock rewards on TOP of
            // the XP+coins already granted by StreakService when the day count
            // is hit, so we keep them modest to avoid double-paying.
            ['name' => 'Week Warrior', 'slug' => 'week_warrior', 'description' => '7 hari berturut-turut nonton — streak Day 7!', 'icon' => '🔥', 'coin_reward' => 0, 'xp_reward' => 0, 'condition_type' => 'watch_streak', 'condition_value' => 7, 'tier' => 'silver'],
            ['name' => 'Month Master', 'slug' => 'month_master', 'description' => '30 hari berturut-turut nonton — streak Day 30!', 'icon' => '🌙', 'coin_reward' => 0, 'xp_reward' => 0, 'condition_type' => 'watch_streak', 'condition_value' => 30, 'tier' => 'gold'],
            ['name' => 'Centurion', 'slug' => 'centurion', 'description' => '100 hari streak — gak ada hari yang skip!', 'icon' => '💯', 'coin_reward' => 0, 'xp_reward' => 0, 'condition_type' => 'watch_streak', 'condition_value' => 100, 'tier' => 'platinum'],
            ['name' => 'Year Legend', 'slug' => 'year_legend', 'description' => '365 hari streak — legendaris!', 'icon' => '👑', 'coin_reward' => 0, 'xp_reward' => 0, 'condition_type' => 'watch_streak', 'condition_value' => 365, 'tier' => 'platinum'],
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(
                ['slug' => $achievement['slug']],
                $achievement
            );
        }
    }
}
