<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\NotBreached;
use App\Rules\StrongPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

    /**
     * Change the authenticated user's password.
     *
     * Requires the current password (defence against session-hijack abuse) and
     * runs the new candidate through the full FLiK policy + HIBP breach check.
     * The User mutator stamps `password_changed_at` automatically.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                new StrongPassword($user),
                new NotBreached(),
            ],
        ]);

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            return back()->withErrors([
                'current_password' => 'Password saat ini salah. / Current password is incorrect.',
            ]);
        }

        // Setting the attribute fires the bcrypt mutator + stamps
        // password_changed_at — see App\Models\User::setPasswordAttribute.
        $user->password = (string) $request->input('password');
        $user->save();

        return back()->with('success', 'Password berhasil diperbarui. / Password updated.');
    }
}
