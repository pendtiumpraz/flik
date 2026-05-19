<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\WatchHistory;
use App\Models\UserLevel;
use App\Services\Gamification\StreakService;
use App\Services\Trending\ViewTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WatchHistoryController extends Controller
{
    public function __construct(
        private readonly ViewTracker $viewTracker,
        private readonly StreakService $streaks,
    ) {
    }

    /**
     * Record video progress (called via AJAX from player).
     */
    public function updateProgress(Request $request)
    {
        $request->validate([
            'movie_id' => 'required|exists:movies,id',
            // Optional — present only when the playing item is a series episode.
            // Nullable so existing movie-only callers stay backwards-compatible.
            'episode_id' => 'nullable|integer|exists:episodes,id',
            'current_time' => 'required|numeric|min:0',
            'duration' => 'required|numeric|min:1',
        ]);

        $userId = auth()->id();
        $progress = min(100, round(($request->current_time / $request->duration) * 100));
        $completed = $progress >= 90;

        // SECURITY: `completed` is server-derived (mass-assignment audit,
        // 2026-05-13) and intentionally NOT in WatchHistory::$fillable so a
        // crafted AJAX payload can't farm completion XP/coins by lying.
        // Use a manual upsert + forceFill so the server-trusted value lands.
        //
        // The unique lookup key includes episode_id so each episode of a
        // series gets its own progress row, while standalone movies keep
        // the legacy (user_id, movie_id) one-row behaviour with episode_id=null.
        $history = WatchHistory::firstOrNew([
            'user_id'    => $userId,
            'movie_id'   => $request->movie_id,
            'episode_id' => $request->episode_id,
        ]);
        $isNew = ! $history->exists;
        $history->forceFill([
            'current_time'    => $request->current_time,
            'duration'        => $request->duration,
            'completed'       => $completed,
            'last_watched_at' => now(),
        ])->save();
        // updateOrCreate semantics callers depend on:
        $history->wasRecentlyCreated = $isNew;

        // Trending signal — strongest "they actually pressed play".
        // Fire only on the FIRST progress write per session so
        // mid-watch heartbeats don't reset the de-dup window. The
        // ViewTracker itself has a 30-min identity dedup as a second
        // safety net (covers the case where firstOrNew matched an
        // older row but the user really did start a brand-new view).
        if ($isNew) {
            $movie = Movie::find($request->movie_id);
            if ($movie) {
                $this->viewTracker->record(
                    movie: $movie,
                    user: auth()->user(),
                    request: $request,
                    durationSeconds: (int) $request->current_time,
                );
            }
        }

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

        // Daily-watch streak — only count "real" watch sessions, not the
        // opening seek-zero ping the player fires on mount. The 60-second
        // threshold means the user has been actively playing for at least
        // a minute; below that it's almost always an accidental open.
        // StreakService is idempotent per-day so refire on every progress
        // tick is safe, but we still gate to keep the cost (one tx) low.
        if ((float) $request->current_time >= 60) {
            try {
                $this->streaks->recordWatch(auth()->user());
            } catch (\Throwable $e) {
                // Streak failure must never break video progress writes.
                Log::warning('streak.record_watch failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
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
