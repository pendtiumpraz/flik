# Audit 10 — Social Features

**Scope:** Public profiles (`/u/{username}`), Follow system, User-curated Lists, Activity Feed, Watch Parties.
**Date:** 2026-05-20
**Branch:** `main`
**Verdict:** PARTIAL — features are largely wired correctly, but two **release-blocking bugs** in the Watch Party path (one fatal runtime error, two missing views) plus several smaller correctness issues exist.

---

## 1. Public Profile (`/u/{username}`)

### Reachable & renders
- **YES.** Route: `Route::get('/u/{username}', PublicProfileController@show)` at `routes/web.php:1121` (public, no auth).
- Username lookup is case-insensitive (`whereRaw('LOWER(username) = ?')`, `PublicProfileController.php:243-246`).
- View: `resources/views/profile/public/show.blade.php` — tabs (Activity / Watchlist / Lists / Achievements), cover banner, avatar, stats grid, follow button (Alpine optimistic flip).
- Private profiles (`is_public=false`) fast-path to `profile.public.private` view; deep data is omitted server-side, not just hidden client-side (`PublicProfileController.php:47-53`).
- 404 (not 403) is returned for private followers/following pages so existence of a private account doesn't leak via status-code differences (`PublicProfileController.php:253-260`).

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| P-1 | LOW | Profile **show** route binds `{username}` (string), but `followers`/`following`/`follow`/`unfollow` routes bind `{user}` and use `whereNumber('user')` (`routes/web.php:1125-1138`). The view at `show.blade.php:111` calls `route('profile.public.followers', $user)` — Laravel generates `/u/<id>/followers` (numeric), so clicking "Followers" on `/u/alice` jumps to `/u/123/followers`. UX inconsistency; not a security/availability bug. |  |
| P-2 | LOW | `feed.index` and `safeCountMoviesWatched` defensively guard for missing tables; good. But `recentComments` view at `show.blade.php:203` renders `{!! $comment->body !!}` raw — relies on `Comment::setBodyAttribute` sanitization (`App\Models\Comment.php:38`). Sanitization is in place; flagged for future review if anyone bypasses the setter (e.g. direct DB writes / mass insert in seeders). | `show.blade.php:203` |

---

## 2. Follow System

### Follow button (auth)
- **YES.** Routes: `POST /u/{user}/follow`, `DELETE /u/{user}/follow` inside `auth` middleware group (`routes/web.php:1132-1139`).
- Trait `App\Models\Concerns\Follows` implements `follow()`, `unfollow()`, `isFollowing()`, `isFollowedBy()`, `isMutualWith()`, cached `followersCount()`/`followingCount()` (5-min TTL).
- Idempotency via `UNIQUE (follower_id, followed_id)` index + `Follow::create()` after pre-check (`Follows.php:79-91`); race-safe.
- Self-follow guarded both at app layer (`Follows::follow()` lines 67-71) and DB CHECK constraint on MySQL/MariaDB/Postgres (`2026_05_10_080001_create_follows_table.php:65-75`).
- Cache invalidation: `forgetFollowCounts()` drops both sides of the edge on every write.
- Cascade-on-delete in migration → GDPR-friendly (delete account = both halves of graph wiped).

### Issues
None blocking. Implementation looks solid.

---

## 3. User-Generated Lists

### CRUD reachable
- **YES.**
  - Browse: `GET /lists` → `UserListController@index` (guest-accessible, supports `sort`/`q`/`user` filters)
  - Owner inventory: `GET /lists/mine`, `GET /lists/following`, `GET /lists/create` (auth)
  - Show: `GET /lists/{user:username}/{list:slug}` with `->scopeBindings()` so slug is resolved within owner (race-safe against slug collision across users)
  - Mutations: `PUT`/`DELETE` `/lists/{user}/{list}`, items add/remove/reorder, follow/unfollow (all auth + policy-gated)
- Visibility enforced at TWO layers: `UserListPolicy::view` defers to `UserList::isVisibleTo()` (model). Public + unlisted readable by anyone with URL; private = owner-only.
- Per-user slug uniqueness (`UserList::generateSlugFor` loops with `-2`, `-3` suffix until unique within owner) → `user_lists_user_slug_unique` index.
- Counters (`items_count`, `followers_count`, `views_count`) excluded from `$guarded` so forged forms can't inflate popularity. View counter rate-limited per session+list+hour via `Cache::add` lock (`UserListController::bumpViewsThrottled` lines 405-425).

### Drag-reorder
- **YES, BUT FRAGILE.** Native HTML5 drag/drop (no SortableJS dep) in `resources/views/lists/show.blade.php:417-491`. Drag handle sets `draggable=true` on mousedown; drop reorders DOM then POSTs new IDs to `route('user-lists.reorder')` which expects `{movie_ids: int[]}` (controller validates `max:1000`).
- Works on desktop. Touch devices: no `touchstart`/`touchmove` polyfill — drag-reorder will **NOT work on mobile/tablet**. Owners on mobile must use the API directly or wait for SortableJS.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| L-1 | MEDIUM | When a list owner has no `username` (legacy OAuth signup), all generated URLs use the fallback `(string) $user->id` (`lists/show.blade.php:5`). Route regex `[A-Za-z0-9_\.]+` matches digits, but `scopeBindings()` resolves `{user:username}` by the `username` column — so `/lists/123/<slug>/follow` will **404** at the user-lookup stage. `UserListController::store` already handles this fallback by redirecting to `/lists/mine` instead of the canonical URL, but the show page renders broken action URLs for guests viewing those lists. |  `UserListController.php:166-168`, `lists/show.blade.php:5` |
| L-2 | LOW | No mobile (touch) drag-reorder. Document or import SortableJS to cover the gap. | `lists/show.blade.php:417` |
| L-3 | LOW | `UserListPolicy::viewAny` always returns `true` but the `/lists` browse route is unauthenticated and doesn't invoke the policy — the `viewAny` declaration is dead code right now. Harmless. | `UserListPolicy.php:27-31` |

---

## 4. Activity Feed (`/feed`)

### Accurate (last 7d)?
- **YES.** `ActivityFeedController::index` merges three streams (Rating / Comment / Watchlist) for users in `$viewer->following()`, windowed `created_at >= now()-7d`, capped at 50 items, sorted desc.
- Auth-gated (`routes/web.php:222`, inside auth group at line 209).
- Defensive against missing `follows` table (fresh installs).
- No materialised feed table — query-time merge. Each stream eager-loads `user:id,name,username,avatar_path` + `movie:id,title,slug,poster_path` to avoid N+1.

### Issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| F-1 | LOW | Each of the three sub-queries does `->limit(self::FEED_LIMIT)` (50) before the final cap — could return up to 150 items into PHP memory for a heavy follow graph. Fine at current scale; revisit if a single follower has >1000 follows or peak burst of activity. | `ActivityFeedController.php:63-94` |
| F-2 | LOW | No `is_public` check on followed users' activity. If user A follows user B, then B switches profile to private, A still sees B's ratings/comments/watchlist in the feed. Either acceptable (B can't retroactively hide from established connections) or a bug (privacy expectation broken) — depends on product call. | `ActivityFeedController.php:50-94` |
| F-3 | INFO | Tab "Lists added" or "Started following X" are NOT included in the feed taxonomy. Could be expanded. |  |

---

## 5. Watch Parties

### Room joinable via code?
- **PARTIALLY BROKEN.**
- POST `/watch-party/join` (`joinByCode`) validates `room_code` is exactly 8 chars, looks up by `room_code`, checks `hasEnded`/`isFull`, redirects to `watch-party.show`. **OK on the controller side.**
- GET `/watch-party/join` (`joinForm`) returns `view('watch-party.join', ...)` — **`watch-party/join.blade.php` does NOT exist** (only `show.blade.php` lives in `resources/views/watch-party/`). Hitting the form route 500s with `View [watch-party.join] not found`.
- GET `/watch-party/create/{movie}` (`createForm`) returns `view('watch-party.create', ...)` — **`watch-party/create.blade.php` does NOT exist either.** Same 500.
- Deep-linked `/watch-party/{roomCode}` works (the host-create POST flow + the show view exist), so users can be invited via direct URL share. But the discovery surfaces (create form / join form) are dead until those views are written.

### Realtime sync via Pusher actually wired?
- **YES on the broadcasting layer, ONE FATAL BUG in the chat path.**
- `WatchPartySync` and `WatchPartyChat` both implement `ShouldBroadcast` on `PresenceChannel('watch-party.{roomCode}')`.
- Channel auth: `routes/channels.php:23` → `WatchPartyChannel::join()` allows only host or active (non-`left_at`) members; returns presence data `{id, name, is_host}`.
- Frontend (`resources/js/watch-party.js`) subscribes via Pusher SDK loaded conditionally (`watch-party/show.blade.php:189-192`), handles `pusher:member_added`/`removed`, `watch-party.sync` events with `applyingRemote` guard to suppress feedback loops, throttles seek broadcasts at 250 ms, and reconciles host position only when delta > 1 s.
- Pusher is treated as **optional infrastructure** — `WatchPartyController::pusherConfigured()` checks `BROADCAST_DRIVER` and config keys; absent → controller still serves the room but flashes a yellow "Pusher setup required" banner and disables chat (`watch-party/show.blade.php:22-41`, `:171-180`).
- Chat message sanitization happens in `WatchPartyChat::__construct` via `HtmlSanitizer` (server-side); frontend also calls `escapeHtml()` defensively in `appendChat()`.

### CRITICAL BUG
- **`WatchPartyController::chat()` line 378 calls `$this->safeBroadcastEvent(new WatchPartyChat(...))` — that method DOES NOT EXIST on the controller.** Only `safeBroadcast(WatchPartySync $event)` is defined (lines 440-455), and its type-hint is strict `WatchPartySync`, so passing a `WatchPartyChat` would also fail.
- Result: every chat POST throws `BadMethodCallException: Method App\Http\Controllers\WatchPartyController::safeBroadcastEvent does not exist.` → 500.
- Backward-compat dispatch of `WatchPartySync` action=`chat` on the next line (lines 385-394) does still fire, so the legacy frontend listener (`watch-party.js:213-216`) renders chat correctly anyway. But the dedicated `WatchPartyChat` event is dead, and the 500 ALSO means the user gets `{"error":"500"}` instead of the chat fan-out (the legacy fan-out is never reached because the BadMethodCallException fires first).

### Other issues
| # | Severity | Issue | Location |
|---|----------|-------|----------|
| W-1 | **BLOCKER** | `safeBroadcastEvent` does not exist; every POST `/watch-party/{roomCode}/chat` 500s. **Fix:** either rename call to `safeBroadcast` (after relaxing the type-hint to a union or a `ShouldBroadcast` interface), or add a new private helper `safeBroadcastEvent(ShouldBroadcast $event)`. | `WatchPartyController.php:378`, `:440` |
| W-2 | **BLOCKER** | Missing view `watch-party/create.blade.php` — `GET /watch-party/create/{movie}` 500s. | `WatchPartyController.php:55`, `resources/views/watch-party/` |
| W-3 | **BLOCKER** | Missing view `watch-party/join.blade.php` — `GET /watch-party/join` 500s. | `WatchPartyController.php:69`, `resources/views/watch-party/` |
| W-4 | MEDIUM | `show()` re-join path (lines 173-187) skips the `isFull()` capacity check when an existing membership row is found (even if it's soft-left). A returning member can push the room past `max_members`. Fix: re-check capacity when `$membership->left_at !== null` before clearing it. | `WatchPartyController.php:174-187` |
| W-5 | MEDIUM | `isFull()` in `WatchParty.php:83-86` runs a fresh `count()` query per call; called from both `joinByCode` and `show` and `join` — could be cached on the request or replaced with a `withCount('activeMembers')` eager-load. Minor perf, not correctness. |  |
| W-6 | LOW | Host leaving via `leave()` (lines 254-269) marks the room ended and fans out a sync event, but does NOT update its own `left_at` timestamp on the host's member row. Cosmetic — `activeMembers` count drops by 1 silently if anyone re-reads. | `WatchPartyController.php:254-269` |
| W-7 | LOW | `leave()` for non-hosts (lines 272-275) does not check `$party->hasEnded()` — leaving an already-ended party is a no-op but fans out a sync event for no one. Defensive `if ($party->hasEnded()) return ...` would be cleaner. |  |
| W-8 | LOW | YouTube-only movies show a "synchronized playback requires self-hosted video" notice (`watch-party/show.blade.php:96-101`) but the room is still created and members can still join → wasted state. Either block create at controller level when `$movie->video_path` and `$movie->video_url` are both null, or short-circuit the YouTube case. |  |

---

## Summary table

| Area | Reachable | Renders | Auth | Realtime | Verdict |
|------|-----------|---------|------|----------|---------|
| Public profile `/u/{username}` | YES | YES | guest-OK; writes auth | n/a | PASS |
| Follow / Unfollow | YES | YES (Alpine optimistic) | auth | n/a | PASS |
| Lists CRUD | YES | YES | mixed; mutations gated by `UserListPolicy` | n/a | PASS (mobile drag-reorder gap) |
| Activity Feed | YES | YES | auth | n/a (request-time merge) | PASS |
| Watch Party (deep link / show) | YES | YES | auth + presence channel | YES (Pusher optional) | PASS |
| Watch Party create form | **NO (500)** | view missing | auth | n/a | **FAIL** |
| Watch Party join form | **NO (500)** | view missing | auth | n/a | **FAIL** |
| Watch Party chat | reaches controller | **500 — BadMethodCallException** | auth + policy | broken | **FAIL** |

---

## Top fixes to ship before release

1. **`WatchPartyController.php:378`** — replace `safeBroadcastEvent(...)` with a working call. Smallest patch: rename the helper to accept any `ShouldBroadcast`, or add a sibling `safeBroadcastEvent` that handles `WatchPartyChat`.
2. **Create `resources/views/watch-party/create.blade.php`** and **`resources/views/watch-party/join.blade.php`** (controller already passes `$movie`/`$pusherEnabled`/`$prefillCode`).
3. **`WatchPartyController.php:174-187`** — re-check `isFull()` before un-soft-leaving a returning member.
4. **Optional, recommended**: write a `tests/Feature/Social/` suite — there are zero feature tests covering the social layer today.

---

## Files reviewed

Absolute paths:

- `D:\AI\velflix\velflix\app\Models\Concerns\Follows.php`
- `D:\AI\velflix\velflix\app\Models\Concerns\HasLists.php`
- `D:\AI\velflix\velflix\app\Models\Concerns\HasReferrals.php`
- `D:\AI\velflix\velflix\app\Models\Follow.php`
- `D:\AI\velflix\velflix\app\Models\UserList.php`
- `D:\AI\velflix\velflix\app\Models\UserListItem.php`
- `D:\AI\velflix\velflix\app\Models\UserListFollower.php`
- `D:\AI\velflix\velflix\app\Models\WatchParty.php`
- `D:\AI\velflix\velflix\app\Models\WatchPartyMember.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\PublicProfileController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\UserListController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\ActivityFeedController.php`
- `D:\AI\velflix\velflix\app\Http\Controllers\WatchPartyController.php`
- `D:\AI\velflix\velflix\app\Events\WatchPartySync.php`
- `D:\AI\velflix\velflix\app\Events\WatchPartyChat.php`
- `D:\AI\velflix\velflix\app\Policies\UserListPolicy.php`
- `D:\AI\velflix\velflix\app\Policies\WatchPartyPolicy.php`
- `D:\AI\velflix\velflix\app\Broadcasting\WatchPartyChannel.php`
- `D:\AI\velflix\velflix\resources\views\profile\public\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\profile\public\private.blade.php`
- `D:\AI\velflix\velflix\resources\views\profile\public\followers.blade.php`
- `D:\AI\velflix\velflix\resources\views\lists\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\lists\show.blade.php`
- `D:\AI\velflix\velflix\resources\views\lists\create.blade.php`
- `D:\AI\velflix\velflix\resources\views\feed\index.blade.php`
- `D:\AI\velflix\velflix\resources\views\watch-party\show.blade.php`
- `D:\AI\velflix\velflix\resources\js\watch-party.js`
- `D:\AI\velflix\velflix\routes\web.php` (social routes)
- `D:\AI\velflix\velflix\routes\channels.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_080001_create_follows_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_080002_add_profile_columns_to_users.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_160001_create_user_lists_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_160002_create_user_list_items_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_160003_create_user_list_followers_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_030004_create_watch_parties_table.php`
- `D:\AI\velflix\velflix\database\migrations\2026_05_10_030005_create_watch_party_members_table.php`
