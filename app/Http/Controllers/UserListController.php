<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\User;
use App\Models\UserList;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * User-curated, shareable movie lists.
 *
 * Distinct concept from {@see WatchlistController} (per-user private bookmark
 * pivot). Lists here are objects in their own right — they have a title,
 * description, visibility, ordering, follower graph, and a public URL.
 *
 * Routing convention: `/lists/{user:username}/{list:slug}` with
 * `->scopeBindings()` so Laravel resolves `{list}` only within `{user}`'s
 * owned lists (a slug like "horror-2025" can exist under multiple users
 * without colliding).
 *
 * SECURITY:
 *   - The {@see UserListPolicy} gates every mutating action (`update`,
 *     `delete`, `addMovie`, `removeMovie`, `reorder`). View access is
 *     gated by {@see UserList::isVisibleTo} so unlisted-with-link works
 *     for guests without leaking via a 403.
 *   - The view count is rate-limited per session to defeat trivial
 *     refresh-spam inflation (see `bumpViewsThrottled`).
 *   - Slug generation goes through the model helper so per-user uniqueness
 *     is guaranteed even under a race between concurrent stores.
 */
class UserListController extends Controller
{
    // ── Browse / discovery ───────────────────────────────────────

    /**
     * Public browse of all public lists. Guest-accessible.
     *
     * Filters: `sort` (newest|most-followed|most-items), `q` (free-text on
     * title), `user` (filter to a specific username's public lists).
     */
    public function index(Request $request): View
    {
        $sort = $request->string('sort', 'most-followed')->toString();
        $q = trim((string) $request->input('q', ''));
        $username = trim((string) $request->input('user', ''));

        $query = UserList::query()
            ->public()
            ->with(['user:id,name,username,avatar_path', 'cover:id,title,slug,poster_path,backdrop_path']);

        if ($q !== '') {
            $query->whereLike('title', '%'.$q.'%');
        }

        if ($username !== '') {
            $owner = User::query()
                ->whereRaw('LOWER(username) = ?', [strtolower($username)])
                ->first();
            // No such user → empty result set rather than a 404; matches the
            // "search returned nothing" UX rather than "page broken".
            $query->where('user_id', $owner?->id ?? 0);
        }

        $query = match ($sort) {
            'newest' => $query->latest(),
            'most-items' => $query->orderByDesc('items_count')->orderByDesc('id'),
            // default: most-followed — featured lists float to the top so
            // admin curation wins ties against organic popularity.
            default => $query->orderByDesc('is_featured')
                ->orderByDesc('followers_count')
                ->orderByDesc('id'),
        };

        $lists = $query->paginate(24)->withQueryString();

        return view('lists.index', [
            'lists' => $lists,
            'filters' => [
                'sort' => $sort,
                'q' => $q,
                'user' => $username,
            ],
        ]);
    }

    /** Lists owned by the current user. */
    public function mine(Request $request): View
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $lists = $viewer->lists()
            ->with(['cover:id,title,slug,poster_path,backdrop_path'])
            ->paginate(24);

        return view('lists.mine', [
            'lists' => $lists,
            'owner' => $viewer,
        ]);
    }

    /** Lists the current user follows. */
    public function following(Request $request): View
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $lists = $viewer->followedLists()
            ->with([
                'user:id,name,username,avatar_path',
                'cover:id,title,slug,poster_path,backdrop_path',
            ])
            ->orderByDesc('user_list_followers.created_at')
            ->paginate(24);

        return view('lists.following', [
            'lists' => $lists,
            'viewer' => $viewer,
        ]);
    }

    // ── CRUD ─────────────────────────────────────────────────────

    public function create(): View
    {
        $this->authorize('create', UserList::class);

        return view('lists.create', [
            'visibilities' => UserList::VISIBILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', UserList::class);

        $data = $this->validateListPayload($request);

        /** @var User $owner */
        $owner = $request->user();

        $list = DB::transaction(function () use ($owner, $data) {
            $slug = UserList::generateSlugFor($owner, $data['title']);

            return UserList::create([
                'user_id' => $owner->id,
                'slug' => $slug,
                'title' => $data['title'],
                'description' => $data['description'],
                'visibility' => $data['visibility'],
                'cover_movie_id' => $data['cover_movie_id'],
            ]);
        });

        // Owner without a username can't reach the canonical URL — fall
        // back to /lists/mine so the redirect never 404s.
        $target = $owner->username !== null && $owner->username !== ''
            ? route('user-lists.show', ['user' => $owner->username, 'list' => $list->slug])
            : route('user-lists.mine');

        return redirect($target)->with('success', 'List berhasil dibuat.');
    }

    /**
     * Public show. Increments view counter (rate-limited per
     * session+list) and renders the items in their stored order.
     */
    public function show(Request $request, User $user, UserList $list): View
    {
        // Scoped binding already enforced ownership via routes/web.php, but
        // double-check defensively (caller could be importing this method).
        abort_if((int) $list->user_id !== (int) $user->id, 404);

        $viewer = $request->user();
        abort_unless($list->isVisibleTo($viewer), 404);

        $this->bumpViewsThrottled($request, $list);

        $list->loadMissing(['user:id,name,username,avatar_path,bio,is_public']);
        $items = $list->items()
            ->with(['movie:id,title,slug,poster_path,backdrop_path,release_date,vote_average'])
            ->get();

        return view('lists.show', [
            'list' => $list,
            'owner' => $list->user,
            'items' => $items,
            'isOwner' => $list->isOwnedBy($viewer),
            'isFollowing' => $list->isFollowedBy($viewer),
            'canFollow' => $viewer !== null && ! $list->isOwnedBy($viewer),
        ]);
    }

    public function edit(Request $request, User $user, UserList $list): View
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('update', $list);

        $list->loadMissing('cover:id,title,slug,poster_path');

        return view('lists.edit', [
            'list' => $list,
            'owner' => $user,
            'visibilities' => UserList::VISIBILITIES,
        ]);
    }

    public function update(Request $request, User $user, UserList $list): RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('update', $list);

        $data = $this->validateListPayload($request);

        DB::transaction(function () use ($list, $data, $user) {
            $payload = [
                'title' => $data['title'],
                'description' => $data['description'],
                'visibility' => $data['visibility'],
                'cover_movie_id' => $data['cover_movie_id'],
            ];

            // Only regenerate slug when the title actually changed —
            // breaks fewer external links and keeps the URL stable across
            // tiny copy edits.
            if ($data['title'] !== $list->title) {
                $payload['slug'] = UserList::generateSlugFor($user, $data['title'], $list->id);
            }

            $list->update($payload);
        });

        return redirect()
            ->route('user-lists.show', ['user' => $user->username ?? (string) $user->id, 'list' => $list->fresh()->slug])
            ->with('success', 'List diperbarui.');
    }

    public function destroy(Request $request, User $user, UserList $list): RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('delete', $list);

        $list->delete();

        return redirect()
            ->route('user-lists.mine')
            ->with('success', 'List dihapus.');
    }

    // ── Item management (owner only) ─────────────────────────────

    public function addMovie(Request $request, User $user, UserList $list): JsonResponse|RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('addMovie', $list);

        $data = $request->validate([
            'movie_id' => ['required', 'integer', 'exists:movies,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $movie = Movie::query()->findOrFail($data['movie_id']);
        $item = $list->addMovie($movie, $data['note'] ?? null);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'item' => [
                    'id' => $item->id,
                    'movie_id' => $movie->id,
                    'title' => $movie->title,
                    'position' => $item->position,
                ],
                'items_count' => $list->fresh()->items_count,
            ]);
        }

        return back()->with('success', 'Film ditambahkan ke list.');
    }

    public function removeMovie(Request $request, User $user, UserList $list, Movie $movie): JsonResponse|RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('removeMovie', $list);

        $list->removeMovie($movie);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'items_count' => $list->fresh()->items_count,
            ]);
        }

        return back()->with('success', 'Film dihapus dari list.');
    }

    public function reorder(Request $request, User $user, UserList $list): JsonResponse|RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('reorder', $list);

        $data = $request->validate([
            'movie_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'movie_ids.*' => ['integer', 'min:1'],
        ]);

        $list->reorder($data['movie_ids']);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Urutan disimpan.');
    }

    // ── Follower graph ───────────────────────────────────────────

    public function follow(Request $request, User $user, UserList $list): JsonResponse|RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);
        $this->authorize('follow', $list);

        /** @var User $viewer */
        $viewer = $request->user();
        $newlyFollowed = $list->follow($viewer);

        if ($request->wantsJson()) {
            return response()->json([
                'following' => true,
                'newly_followed' => $newlyFollowed,
                'followers_count' => $list->fresh()->followers_count,
            ]);
        }

        return back()->with('success', $newlyFollowed
            ? 'Kamu sekarang mengikuti list ini.'
            : 'Kamu sudah mengikuti list ini.');
    }

    public function unfollow(Request $request, User $user, UserList $list): JsonResponse|RedirectResponse
    {
        abort_if((int) $list->user_id !== (int) $user->id, 404);

        /** @var User $viewer */
        $viewer = $request->user();
        $deleted = $list->unfollow($viewer);

        if ($request->wantsJson()) {
            return response()->json([
                'following' => false,
                'unfollowed' => $deleted > 0,
                'followers_count' => $list->fresh()->followers_count,
            ]);
        }

        return back()->with('success', $deleted > 0
            ? 'Berhenti mengikuti list.'
            : 'Kamu memang tidak mengikuti list ini.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Shared validation for create + update. Centralised so the schema can
     * only drift in one place.
     *
     * @return array{title:string,description:?string,visibility:string,cover_movie_id:?int}
     */
    private function validateListPayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['required', Rule::in(UserList::VISIBILITIES)],
            'cover_movie_id' => ['nullable', 'integer', 'exists:movies,id'],
        ]);

        return [
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'visibility' => $data['visibility'],
            'cover_movie_id' => $data['cover_movie_id'] ?? null,
        ];
    }

    /**
     * Increment views_count at most once per session per list per hour.
     *
     * Cache lock keeps the counter from being inflated by refresh-spam
     * (or by clients that prefetch the URL). 1-hour TTL chosen to align
     * with how a "popular today" sort would aggregate — long enough that
     * F5-spam doesn't matter, short enough that returning visitors are
     * still counted across sessions of the day.
     */
    private function bumpViewsThrottled(Request $request, UserList $list): void
    {
        // Use the session id when available, else hash the IP+UA so guests
        // without a session cookie still throttle (anti-spam over anti-bot).
        $key = sprintf(
            'user-list:view-lock:%d:%s',
            $list->id,
            $request->session()?->getId()
                ?? hash('sha256', (string) $request->ip().'|'.(string) $request->userAgent())
        );

        // Cache::add returns true only when the key is freshly created — a
        // race-free "first-call-wins" gate.
        $isFirstView = Cache::add($key, 1, now()->addHour());
        if (! $isFirstView) {
            return;
        }

        // forceFill — bypass $guarded for the counter write.
        $list->forceFill(['views_count' => $list->views_count + 1])->save();
    }
}
