<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\Notification;
use App\Models\User;
use App\Models\WatchHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * /u/{username} — public profile pages + follow/unfollow + lists.
 *
 * Most routes here are intentionally guest-accessible (the whole point of a
 * public profile is that strangers can see it). The mutating endpoints
 * (follow / unfollow) require auth — wired in routes/web.php — and run their
 * own self-action guard so a crafted POST can't make you follow yourself.
 *
 * Privacy contract:
 *   - When `is_public = false` AND the viewer is NOT the owner, the show()
 *     action renders the "private profile" minimal view (name + avatar +
 *     follow button only). All deep data (watchlist, ratings, comments,
 *     achievements) is omitted server-side, NOT just hidden in the view.
 */
class PublicProfileController extends Controller
{
    /**
     * Render a public profile.
     *
     * Username lookup is case-insensitive (`whereRaw lower(...)`) so people
     * who type "/u/Alice" reach the same page as "/u/alice".
     */
    public function show(string $username): View
    {
        $user = $this->findByUsernameOrFail($username);

        $viewer = auth()->user();
        $isOwner = $viewer !== null && $viewer->id === $user->id;
        $isPrivate = ! (bool) ($user->is_public ?? true);

        // Private-profile fast path — strangers get the bare minimum.
        if ($isPrivate && ! $isOwner) {
            return view('profile.public.private', [
                'user' => $user,
                'isAuthed' => $viewer !== null,
                'isFollowing' => $viewer ? $viewer->isFollowing($user) : false,
            ]);
        }

        // Eager-load engagement data. Counts come from the cached helpers
        // so we don't pay an extra query on every render.
        $recentRatings = $user->ratings()
            ->with('movie:id,title,slug,poster_path,backdrop_path')
            ->latest()
            ->take(10)
            ->get();

        $recentComments = $user->comments()
            ->with(['movie:id,title,slug,poster_path'])
            ->latest()
            ->take(10)
            ->get();

        // Owners always see their full watchlist; strangers see at most 12.
        $watchlistQuery = $user->watchlistMovies()
            ->latest('watchlists.created_at');
        $watchlistMovies = $isOwner
            ? $watchlistQuery->get()
            : $watchlistQuery->take(12)->get();

        $achievements = $user->achievements ?? collect();

        $followersCount = $user->followersCount();
        $followingCount = $user->followingCount();
        $moviesWatched = $this->safeCountMoviesWatched($user);

        // Curated user-lists (peer LISTS) — public lists only when viewed by
        // a stranger; the owner sees all of their own lists. Defensive against
        // a fresh install before the user_lists migration has run.
        $publicLists = collect();
        $publicListsCount = 0;
        try {
            if (Schema::hasTable('user_lists') && method_exists($user, 'lists')) {
                $listsQuery = $isOwner
                    ? $user->lists()
                    : $user->publicLists();
                $publicListsCount = (clone $listsQuery)->count();
                $publicLists = $listsQuery
                    ->with([
                        'cover:id,title,slug,poster_path,backdrop_path',
                        'items' => fn ($q) => $q->orderBy('position')->limit(4),
                        'items.movie:id,title,poster_path,backdrop_path',
                    ])
                    ->take(8)
                    ->get();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('public-profile: lists load failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return view('profile.public.show', [
            'user' => $user,
            'isOwner' => $isOwner,
            'isAuthed' => $viewer !== null,
            'isFollowing' => $viewer ? $viewer->isFollowing($user) : false,
            'isFollowedBy' => $viewer ? $viewer->isFollowedBy($user) : false,
            'isMutual' => $viewer ? $viewer->isMutualWith($user) : false,
            'recentRatings' => $recentRatings,
            'recentComments' => $recentComments,
            'watchlistMovies' => $watchlistMovies,
            'achievements' => $achievements,
            'followersCount' => $followersCount,
            'followingCount' => $followingCount,
            'moviesWatched' => $moviesWatched,
            'publicLists' => $publicLists,
            'publicListsCount' => $publicListsCount,
        ]);
    }

    /**
     * POST /u/{user}/follow
     *
     * Idempotent: a second POST is a no-op. Emits a user-facing notification
     * on the FIRST successful follow only (so spamming follow/unfollow
     * doesn't flood the target with bell-rings).
     */
    public function follow(Request $request, User $user): RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($viewer->id === $user->id) {
            return back()->withErrors(['follow' => 'You cannot follow yourself.']);
        }

        $newlyFollowed = $viewer->follow($user);

        if ($newlyFollowed) {
            $this->notifyTargetOfNewFollower($viewer, $user);
            $this->dispatchAdminNotifier('follow.created', $viewer, $user);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'following' => true,
                'followers_count' => $user->followersCount(),
                'newly_followed' => $newlyFollowed,
            ]);
        }

        return back()->with('success', $newlyFollowed
            ? "You're now following {$user->name}."
            : "You already follow {$user->name}.");
    }

    /**
     * DELETE /u/{user}/follow
     */
    public function unfollow(Request $request, User $user): RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($viewer->id === $user->id) {
            return back()->withErrors(['follow' => 'You cannot unfollow yourself.']);
        }

        $deleted = $viewer->unfollow($user);

        if ($request->wantsJson()) {
            return response()->json([
                'following' => false,
                'followers_count' => $user->followersCount(),
                'unfollowed' => $deleted > 0,
            ]);
        }

        return back()->with('success', $deleted > 0
            ? "Unfollowed {$user->name}."
            : "You were not following {$user->name}.");
    }

    /**
     * GET /u/{user}/followers — paginated list.
     */
    public function followers(User $user): View
    {
        $this->guardPrivateList($user);

        $viewer = auth()->user();

        $followers = $user->followers()
            ->select('users.id', 'users.name', 'users.username', 'users.bio', 'users.avatar_path', 'users.is_public')
            ->latest('follows.created_at')
            ->paginate(24);

        return view('profile.public.followers', [
            'user' => $user,
            'users' => $followers,
            'viewer' => $viewer,
            'mode' => 'followers',
        ]);
    }

    /**
     * GET /u/{user}/following — paginated list.
     */
    public function following(User $user): View
    {
        $this->guardPrivateList($user);

        $viewer = auth()->user();

        $following = $user->following()
            ->select('users.id', 'users.name', 'users.username', 'users.bio', 'users.avatar_path', 'users.is_public')
            ->latest('follows.created_at')
            ->paginate(24);

        return view('profile.public.followers', [
            'user' => $user,
            'users' => $following,
            'viewer' => $viewer,
            'mode' => 'following',
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────

    /**
     * Look up by username case-insensitively. Throws 404 on miss so the
     * route never leaks "user exists but is private" via a 403 vs 404.
     */
    private function findByUsernameOrFail(string $username): User
    {
        return User::query()
            ->whereRaw('LOWER(username) = ?', [strtolower($username)])
            ->firstOrFail();
    }

    /**
     * For follower/following list pages: if the profile is private and the
     * viewer isn't the owner, treat it as 404. (Same response shape as
     * "user doesn't exist" so we don't leak the existence of private rows.)
     */
    private function guardPrivateList(User $user): void
    {
        $viewer = auth()->user();
        $isOwner = $viewer !== null && $viewer->id === $user->id;
        $isPrivate = ! (bool) ($user->is_public ?? true);

        abort_if($isPrivate && ! $isOwner, 404);
    }

    /**
     * Count "movies watched" defensively — the WatchHistory table is the
     * canonical source for resume/completion state but a fresh install
     * before WatchHistory rows exist would otherwise crash the profile.
     */
    private function safeCountMoviesWatched(User $user): int
    {
        try {
            if (! Schema::hasTable('watch_histories')) {
                return 0;
            }

            // "Watched" = at least one history row with completed=true; falls
            // back to total distinct movies if the `completed` column is
            // missing (older schema variants).
            $query = WatchHistory::query()
                ->where('user_id', $user->id);

            if (Schema::hasColumn('watch_histories', 'completed')) {
                $query->where('completed', true);
            }

            return (int) $query->distinct('movie_id')->count('movie_id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Persist a user-facing in-app Notification for the followed party.
     *
     * Defensive against the legacy `notifications` table being missing on
     * fresh installs — silently no-ops in that case rather than failing
     * the follow.
     */
    private function notifyTargetOfNewFollower(User $follower, User $target): void
    {
        try {
            if (! Schema::hasTable('notifications')) {
                return;
            }

            $url = $follower->publicProfileUrl() ?? url('/profile');

            Notification::create([
                'user_id' => $target->id,
                'type' => 'social.follow',
                'title' => "{$follower->name} started following you",
                'message' => '@'.($follower->username ?? 'someone').' added you to their following list.',
                'action_url' => $url,
            ]);
        } catch (\Throwable $e) {
            // Notification failure must not break the follow itself —
            // log and move on. Production logs will catch repeat failures.
            \Illuminate\Support\Facades\Log::warning('public-profile: notify-followed failed', [
                'follower_id' => $follower->id,
                'target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Optionally notify staff via the AdminNotifier (peer NOTIF #1).
     * Wrapped in defence-in-depth — when the service isn't bound (early
     * installs or partial deploys) we skip silently rather than 500.
     */
    private function dispatchAdminNotifier(string $category, User $follower, User $target): void
    {
        try {
            if (! class_exists(\App\Services\Notifications\AdminNotifier::class)) {
                return;
            }
            $notifier = app(\App\Services\Notifications\AdminNotifier::class);
            $notifier->notify(
                category: $category,
                title: 'New follow',
                message: "@{$follower->username} followed @{$target->username}",
                meta: [
                    'follower_id' => $follower->id,
                    'target_id' => $target->id,
                ],
                severity: 'info',
                audience: 'all_admins',
            );
        } catch (\Throwable $e) {
            // Admin notifier is purely informational — never let it block
            // the social action it observes.
        }
    }
}
