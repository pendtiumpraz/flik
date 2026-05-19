<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLevel;
use App\Models\WatchHistory;
use App\Models\WatchStreak;
use App\Services\Gamification\StreakService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Public-ish leaderboards. All boards require auth (so we can highlight the
 * viewer's own rank) but the data shown is aggregate / non-PII.
 */
class LeaderboardController extends Controller
{
    public function __construct(private readonly StreakService $streaks)
    {
    }

    /**
     * Top 50 daily-watch streaks. Highlights the viewer's rank when present.
     */
    public function streaks(): View
    {
        $top = $this->streaks->topStreaks(50);
        $myRank = $this->streaks->rankFor(auth()->user());
        $myStreak = WatchStreak::query()->where('user_id', auth()->id())->first();

        return view('leaderboards.streaks', [
            'top'      => $top,
            'myRank'   => $myRank,
            'myStreak' => $myStreak,
        ]);
    }

    /**
     * Top 50 XP earners. Pulls from user_levels (which is the canonical XP store).
     */
    public function xp(): View
    {
        // Order by (level desc, xp desc) so a level-7 user with 10 XP beats
        // a level-6 user with 99 XP, matching the visual hierarchy on the
        // profile page.
        $top = UserLevel::query()
            ->with('user:id,name,username,avatar_path')
            ->where('level', '>', 0)
            ->orderByDesc('level')
            ->orderByDesc('xp')
            ->limit(50)
            ->get();

        $myLevel = UserLevel::query()->where('user_id', auth()->id())->first();

        $myRank = null;
        if ($myLevel !== null) {
            $myRank = 1 + UserLevel::query()
                ->where(function ($q) use ($myLevel) {
                    $q->where('level', '>', $myLevel->level)
                      ->orWhere(function ($q2) use ($myLevel) {
                          $q2->where('level', $myLevel->level)
                             ->where('xp', '>', $myLevel->xp);
                      });
                })
                ->count();
        }

        return view('leaderboards.xp', [
            'top'     => $top,
            'myRank'  => $myRank,
            'myLevel' => $myLevel,
        ]);
    }

    /**
     * Top 50 watchers by completed-watch count.
     *
     * Counts only watch_histories rows with `completed = 1` (the server-derived
     * flag) so a user can't pad the board by opening and closing the player.
     */
    public function watches(): View
    {
        $top = WatchHistory::query()
            ->select('user_id', DB::raw('COUNT(*) as watch_count'))
            ->where('completed', true)
            ->groupBy('user_id')
            ->orderByDesc('watch_count')
            ->limit(50)
            ->with('user:id,name,username,avatar_path')
            ->get();

        // Pre-compute viewer's count + rank in two cheap queries.
        $myCount = (int) WatchHistory::query()
            ->where('user_id', auth()->id())
            ->where('completed', true)
            ->count();

        $myRank = null;
        if ($myCount > 0) {
            $ahead = DB::table('watch_histories')
                ->select('user_id', DB::raw('COUNT(*) as wc'))
                ->where('completed', true)
                ->groupBy('user_id')
                ->having('wc', '>', $myCount)
                ->get()
                ->count();
            $myRank = $ahead + 1;
        }

        return view('leaderboards.watches', [
            'top'     => $top,
            'myRank'  => $myRank,
            'myCount' => $myCount,
        ]);
    }
}
