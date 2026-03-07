<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show()
    {
        $user = auth()->user();
        $user->load(['watchlist', 'ratings', 'comments']);

        $level = $user->getOrCreateLevel();
        $achievements = $user->achievements;
        $coinBalance = $user->coin_balance;

        $stats = [
            'watchlist_count' => $user->watchlist->count(),
            'ratings_count' => $user->ratings->count(),
            'comments_count' => $user->comments->count(),
            'level' => $level->level,
            'xp' => $level->xp,
            'xp_next' => $level->xp_for_next_level,
            'xp_progress' => $level->xp_progress_percent,
            'coins' => $coinBalance,
            'achievements_count' => $achievements->count(),
            'streak' => $level->watch_streak,
        ];

        $recentWatchlist = $user->watchlistMovies()
            ->latest('watchlists.created_at')
            ->take(6)
            ->get();

        $recentRatings = $user->ratings()
            ->with('movie')
            ->latest()
            ->take(5)
            ->get();

        return view('profile.show', compact('user', 'stats', 'level', 'achievements', 'recentWatchlist', 'recentRatings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . auth()->id(),
        ]);

        auth()->user()->update($request->only('name', 'email'));

        return back()->with('success', 'Profil berhasil diupdate!');
    }
}
