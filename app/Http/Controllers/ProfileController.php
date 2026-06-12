<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\User;
use App\Rules\NotBreached;
use App\Rules\StrongPassword;
use App\Services\Security\FileUploadValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * Achievement showcase — grid of every achievement with locked/unlocked
     * state. Used as the standalone "Hall of Fame" page reached from the
     * profile and the streak widget.
     *
     * We render even when the user has unlocked zero — the locked tiles
     * double as a roadmap of what's available.
     */
    public function achievements(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $all = Achievement::query()
            ->where('is_active', true)
            ->orderBy('condition_type')
            ->orderBy('condition_value')
            ->get();

        // Eager-load the user's pivot rows once and key by achievement_id so
        // the view can render unlocked_at without an N+1.
        $unlocked = $user->achievements()
            ->get(['achievements.id'])
            ->mapWithKeys(fn ($a) => [$a->id => $a->pivot->unlocked_at]);

        // Filter — only applied when the column exists (the seeder doesn't
        // populate a `category` column today, so this is forward-compatible).
        $hasCategoryColumn = Schema::hasColumn('achievements', 'category');
        $filter = (string) $request->query('category', '');
        $categories = collect();

        if ($hasCategoryColumn) {
            $categories = $all->pluck('category')->filter()->unique()->values();
            if ($filter !== '' && $categories->contains($filter)) {
                $all = $all->where('category', $filter)->values();
            }
        }

        $unlockedCount = $unlocked->count();
        $totalCount = $all->count();

        return view('profile.achievements', [
            'user' => $user,
            'achievements' => $all,
            'unlockedMap' => $unlocked,
            'unlockedCount' => $unlockedCount,
            'totalCount' => $totalCount,
            'hasCategoryColumn' => $hasCategoryColumn,
            'categories' => $categories,
            'activeCategory' => $filter,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.auth()->id(),
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
            'user' => $user,
            'roles' => $roles,
            'permissions' => $permissions,
            'groupedPermissions' => $grouped,
            'isSuperAdmin' => $user->isSuperAdmin(),
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
                'error' => $e->getMessage(),
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
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Update the public-profile fields (bio, avatar, cover, visibility, DM).
     *
     * Image uploads go through {@see FileUploadValidator} when available
     * (peer SEC #11) — it re-encodes via GD/Imagick to strip EXIF and any
     * embedded payload before we write to disk. When the validator is not
     * present (early installs) we fall back to a strict Laravel `image`
     * rule, which is weaker but still useful.
     */
    public function updatePublic(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'username' => [
                'nullable', 'string', 'max:32',
                'regex:/^[A-Za-z0-9_\.]+$/',
                'unique:users,username,'.$user->id,
            ],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'file', 'image', 'max:5000'],
            'cover' => ['nullable', 'file', 'image', 'max:8000'],
            'is_public' => ['nullable', 'boolean'],
            'allow_dm' => ['nullable', 'boolean'],
        ], [
            'username.regex' => 'Username may only contain letters, digits, underscore, and period.',
        ]);

        $payload = [
            'bio' => $data['bio'] ?? null,
            'is_public' => (bool) ($request->boolean('is_public', $user->is_public ?? true)),
            'allow_dm' => (bool) ($request->boolean('allow_dm', $user->allow_dm ?? true)),
        ];

        // Username is optional but unique — only write when supplied so
        // we don't overwrite an existing handle with an empty input.
        if (! empty($data['username'] ?? null)) {
            $payload['username'] = Str::lower(trim((string) $data['username']));
        }

        // ── Avatar upload ──────────────────────────────────────
        if ($request->hasFile('avatar')) {
            $stored = $this->persistImage($request->file('avatar'), 'avatars', $user->avatar_path);
            if ($stored !== null) {
                $payload['avatar_path'] = $stored;
            }
        }

        // ── Cover upload ───────────────────────────────────────
        if ($request->hasFile('cover')) {
            $stored = $this->persistImage($request->file('cover'), 'covers', $user->cover_path);
            if ($stored !== null) {
                $payload['cover_path'] = $stored;
            }
        }

        $user->fill($payload)->save();

        return back()->with('success', 'Public profile updated.');
    }

    /**
     * Validate + persist an uploaded image, returning the storage-relative
     * path (suitable for `avatar_path` / `cover_path`). Returns null when
     * validation fails — the caller logs nothing and leaves the existing
     * value alone, so a bad upload is a silent no-op for the field rather
     * than wiping the user's prior image.
     */
    private function persistImage(\Illuminate\Http\UploadedFile $file, string $folder, ?string $oldPath): ?string
    {
        // Prefer the hardened validator (re-encode + EXIF strip).
        if (class_exists(FileUploadValidator::class)) {
            try {
                /** @var FileUploadValidator $validator */
                $validator = app(FileUploadValidator::class);
                $result = $validator->validateImage($file);
                if (! ($result['ok'] ?? false)) {
                    return null;
                }

                $ext = $result['extension'] ?? $file->getClientOriginalExtension();
                $name = $folder.'/'.auth()->id().'-'.Str::random(16).'.'.$ext;

                // The safe_path is a server-local temp file containing the
                // re-encoded copy — stream it into the public disk.
                $contents = @file_get_contents((string) ($result['safe_path'] ?? $file->getRealPath()));
                if ($contents === false) {
                    return null;
                }
                Storage::disk(\App\Support\MediaDisk::name())->put($name, $contents);

                $this->maybeForgetOldPath($oldPath);

                return $name;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('profile.public: image upload failed', [
                    'folder' => $folder,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Fallback: vanilla Laravel storage with the validation we already ran.
        $name = $folder.'/'.auth()->id().'-'.Str::random(16).'.'.$file->getClientOriginalExtension();
        $stored = $file->storeAs(dirname($name), basename($name), ['disk' => \App\Support\MediaDisk::name()]);

        $this->maybeForgetOldPath($oldPath);

        return $stored ?: null;
    }

    /**
     * Best-effort cleanup of the previous avatar/cover on the public disk.
     * Errors here are swallowed — leaving a stale file is far less bad than
     * 500-ing the profile update because of a filesystem quirk.
     */
    private function maybeForgetOldPath(?string $oldPath): void
    {
        if ($oldPath === null || $oldPath === '') {
            return;
        }
        if (str_starts_with($oldPath, 'http://') || str_starts_with($oldPath, 'https://')) {
            return; // never delete an external URL
        }
        try {
            Storage::disk(\App\Support\MediaDisk::name())->delete($oldPath);
        } catch (\Throwable $e) {
            // Intentional: orphaned files are an ops problem, not a user
            // problem. The profile update succeeds either way.
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
                new NotBreached,
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
