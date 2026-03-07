<?php

namespace App\Http\Controllers;

use App\Models\WatchHistory;
use App\Models\UserLevel;
use Illuminate\Http\Request;

class WatchHistoryController extends Controller
{
    /**
     * Record video progress (called via AJAX from player).
     */
    public function updateProgress(Request $request)
    {
        $request->validate([
            'movie_id' => 'required|exists:movies,id',
            'current_time' => 'required|numeric|min:0',
            'duration' => 'required|numeric|min:1',
        ]);

        $userId = auth()->id();
        $progress = min(100, round(($request->current_time / $request->duration) * 100));
        $completed = $progress >= 90;

        $history = WatchHistory::updateOrCreate(
            ['user_id' => $userId, 'movie_id' => $request->movie_id],
            [
                'current_time' => $request->current_time,
                'duration' => $request->duration,
                'completed' => $completed,
                'last_watched_at' => now(),
            ]
        );

        // Award XP when completing a movie for the first time
        if ($completed && $history->wasRecentlyCreated) {
            $level = UserLevel::firstOrCreate(
                ['user_id' => $userId],
                ['level' => 1, 'xp' => 0, 'xp_to_next' => 100]
            );
            $level->addXp(20); // 20 XP for completing a movie

            // Award coins
            \App\Models\Coin::earn($userId, 5, 'watch_complete', "Menonton film selesai");
        }

        return response()->json([
            'status' => 'ok',
            'progress' => $progress,
            'completed' => $completed,
        ]);
    }

    /**
     * Get resume positions for a user (for continue watching).
     */
    public function getProgress(Request $request)
    {
        $movieId = $request->query('movie_id');
        $history = WatchHistory::where('user_id', auth()->id())
            ->where('movie_id', $movieId)
            ->first();

        if (!$history) {
            return response()->json(['current_time' => 0, 'completed' => false]);
        }

        return response()->json([
            'current_time' => $history->current_time,
            'duration' => $history->duration,
            'completed' => $history->completed,
            'progress' => $history->progress_percentage,
        ]);
    }
}
