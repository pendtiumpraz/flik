<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Gamification\StreakService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User-facing streak actions.
 *
 * Currently exposes only the manual freeze-credit purchase. The streak itself
 * is advanced by WatchHistoryController::updateProgress (which calls
 * StreakService::recordWatch); there is no public "record" endpoint because
 * a user could otherwise farm streaks without actually watching.
 */
class StreakController extends Controller
{
    public function __construct(private readonly StreakService $streaks)
    {
    }

    /**
     * POST /streak/freeze — spend coins to buy one freeze credit.
     *
     * Cost is `StreakService::FREEZE_COIN_COST` (50 coins). On insufficient
     * balance we flash an error and bounce back so the rewards/streak widget
     * surfaces the failure inline rather than via JSON.
     */
    public function freeze(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $ok = $this->streaks->purchaseFreeze($user);

        if ($ok) {
            return back()->with(
                'success',
                'Freeze credit berhasil dibeli! Streak kamu aman untuk 1 hari skip.',
            );
        }

        return back()->withErrors([
            'freeze' => 'Coins tidak cukup. Butuh '
                . StreakService::FREEZE_COIN_COST
                . ' coins untuk membeli freeze.',
        ]);
    }
}
