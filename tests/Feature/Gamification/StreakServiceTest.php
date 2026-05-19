<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\Coin;
use App\Models\StreakHistoryEntry;
use App\Models\User;
use App\Models\WatchStreak;
use App\Services\Gamification\StreakService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Skip the whole file when the test DB isn't reachable (CI without DB +
 * local quick-runs). The tests rely on the streak / achievements / coins
 * tables existing so there's no value in running them in a no-DB env.
 */
beforeEach(function () {
    try {
        if (!Schema::hasTable('watch_streaks')
            || !Schema::hasTable('achievements')
            || !Schema::hasTable('users')) {
            $this->markTestSkipped('Streak tables not migrated — skipping.');
        }
    } catch (\Throwable $e) {
        $this->markTestSkipped('DB unavailable for streak tests: ' . $e->getMessage());
    }

    // Make sure the week_warrior achievement exists so the milestone unlock
    // test can assert against a real pivot row.
    Achievement::query()->updateOrCreate(
        ['slug' => 'week_warrior'],
        [
            'name'            => 'Week Warrior',
            'description'     => '7-day streak',
            'icon'            => '🔥',
            'coin_reward'     => 0,
            'xp_reward'       => 0,
            'condition_type'  => 'watch_streak',
            'condition_value' => 7,
            'tier'            => 'silver',
            'is_active'       => true,
        ],
    );
});

function makeUser(): User
{
    return User::factory()->create();
}

it('records a streak of 1 on the first watch', function () {
    $service = app(StreakService::class);
    $user = makeUser();

    $streak = $service->recordWatch($user);

    expect($streak->current_streak)->toBe(1);
    expect($streak->longest_streak)->toBe(1);
    expect($streak->last_watch_date)->not->toBeNull();
    expect(StreakHistoryEntry::where('user_id', $user->id)->count())->toBe(1);
});

it('is idempotent for the same day', function () {
    $service = app(StreakService::class);
    $user = makeUser();
    $today = Carbon::parse('2026-05-10', 'Asia/Jakarta')->startOfDay();

    $service->recordWatch($user, $today);
    $service->recordWatch($user, $today);
    $service->recordWatch($user, $today);

    $streak = WatchStreak::where('user_id', $user->id)->first();
    expect($streak->current_streak)->toBe(1);
    expect(StreakHistoryEntry::where('user_id', $user->id)->count())->toBe(1);
});

it('extends the streak when watched the next day', function () {
    $service = app(StreakService::class);
    $user = makeUser();
    $day1 = Carbon::parse('2026-05-10', 'Asia/Jakarta')->startOfDay();
    $day2 = $day1->copy()->addDay();

    $service->recordWatch($user, $day1);
    $service->recordWatch($user, $day2);

    $streak = WatchStreak::where('user_id', $user->id)->first();
    expect($streak->current_streak)->toBe(2);
    expect($streak->longest_streak)->toBe(2);
});

it('resets the streak on a 2-day gap with no freeze credits', function () {
    $service = app(StreakService::class);
    $user = makeUser();
    $day1 = Carbon::parse('2026-05-10', 'Asia/Jakarta')->startOfDay();
    $day4 = $day1->copy()->addDays(3); // skips two whole days

    $service->recordWatch($user, $day1);
    $service->recordWatch($user, $day4);

    $streak = WatchStreak::where('user_id', $user->id)->first();
    expect($streak->current_streak)->toBe(1);
    // longest still holds the previous best
    expect($streak->longest_streak)->toBe(1);
    expect($streak->freeze_credits)->toBe(0);
});

it('spends a freeze credit to maintain the streak across a gap', function () {
    $service = app(StreakService::class);
    $user = makeUser();
    $day1 = Carbon::parse('2026-05-10', 'Asia/Jakarta')->startOfDay();
    $day3 = $day1->copy()->addDays(2);

    $service->recordWatch($user, $day1);

    // Grant a freeze credit BEFORE the gap.
    $service->grantFreezeCredit($user);

    $service->recordWatch($user, $day3);

    $streak = WatchStreak::where('user_id', $user->id)->first();
    expect($streak->current_streak)->toBe(2);
    expect($streak->freeze_credits)->toBe(0); // consumed by the gap
});

it('unlocks the week_warrior achievement at day 7', function () {
    $service = app(StreakService::class);
    $user = makeUser();
    $start = Carbon::parse('2026-05-01', 'Asia/Jakarta')->startOfDay();

    for ($i = 0; $i < 7; $i++) {
        $service->recordWatch($user, $start->copy()->addDays($i));
    }

    $streak = WatchStreak::where('user_id', $user->id)->first();
    expect($streak->current_streak)->toBe(7);

    $hasAchievement = $user->achievements()
        ->where('slug', 'week_warrior')
        ->exists();
    expect($hasAchievement)->toBeTrue();

    // Day 7 milestone — 20 coins per StreakService::MILESTONES
    expect(Coin::balanceFor($user->id))->toBeGreaterThanOrEqual(20);
});
