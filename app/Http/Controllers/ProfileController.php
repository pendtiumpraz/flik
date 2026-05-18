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
     * Self-service "View My Permissions" page.
     *
     * Renders the authenticated user's effective roles + the flat permission
     * set those roles grant. Intentionally defensive: if the Permission/Role
     * tables haven't been migrated yet (fresh install, peer migrations in
     * flight) the view simply renders empty collections instead of throwing.
     *
     * Super-admins get a sentinel banner because their effective permission
     * set is "every permission" via `Gate::before`, not via the pivot — so a
     * naive `$user->permissions()` would under-report their actual access.
     */
    public function permissions()
    {
        /** @var User $user */
        $user = auth()->user();

        // Roles (with priority + assignment metadata) and the flat permission
        // collection — both relations are memoized inside User so the second
        // call is free.
        $roles = $this->safeLoadRoles($user);
        $permissions = $this->safeLoadPermissions($user);

        // Group permissions by category so the view can render collapsible
        // sections instead of one long flat list. Falls back to "other" when
        // a permission has no category (custom-seeded perms from the admin
        // UI, hand-inserted rows, etc.).
        $grouped = $permissions
            ->groupBy(fn ($perm) => $perm->category ?? 'other')
            ->sortKeys();

        return view('profile.permissions', [
            'user'                  => $user,
            'roles'                 => $roles,
            'permissions'           => $permissions,
            'groupedPermissions'    => $grouped,
            'isSuperAdmin'          => $user->isSuperAdmin(),
            'totalPermissionsCount' => $permissions->count(),
        ]);
    }

    /**
     * Resolve the user's role collection in a way that never crashes the
     * profile page — if the pivot table is missing (pre-migration) we
     * surface an empty collection and let the view say "no roles yet".
     */
    private function safeLoadRoles(User $user): \Illuminate\Support\Collection
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('role_user')
                || ! class_exists(\App\Models\Role::class)) {
                return collect();
            }

            return $user->roles()->orderBy('priority')->get();
        } catch (\Throwable $e) {
            // Schema mismatch, missing column, DB unavailable — log + degrade.
            \Illuminate\Support\Facades\Log::warning('profile.permissions: failed to load roles', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Same defensive wrapper for the flat permission set.
     */
    private function safeLoadPermissions(User $user): \Illuminate\Support\Collection
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('permission_role')
                || ! class_exists(\App\Models\Permission::class)) {
                return collect();
            }

            return $user->permissions();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('profile.permissions: failed to load permissions', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return collect();
        }
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
