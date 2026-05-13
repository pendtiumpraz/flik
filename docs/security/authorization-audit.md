# Authorization Audit — IDOR sweep

**Date:** 2026-05-13
**Scope:** every controller method that accepts a route-model-bound model.
**Goal:** close IDOR gaps by ensuring every per-user resource is protected
either by an explicit policy call (`$this->authorize(...)`), a Gate check,
the `owns:` middleware, or a query already scoped to `auth()->id()`.

## Bypass model (super-admin)

`AuthServiceProvider::boot()` registers a `Gate::before` hook that returns
`true` for any user passing `User::isSuperAdmin()` (`role === 'super_admin'`
**or** the legacy `is_admin === true`). Returning `true` short-circuits
every policy/gate call below — admins always pass. We never return `false`
from `Gate::before` so per-method denials still apply to non-admins.

The legacy `Gate::define('admin', ...)` (used by `can:admin` route
middleware) is unchanged, so existing admin route groups keep working.

## New artefacts

| Path                                          | Purpose                                                  |
| --------------------------------------------- | -------------------------------------------------------- |
| `app/Policies/CommentPolicy.php`              | view/create/update/delete; admin override via Gate::before |
| `app/Policies/WatchlistPolicy.php`            | strict per-user                                          |
| `app/Policies/WatchHistoryPolicy.php`         | strict per-user (resume positions)                       |
| `app/Policies/WatchPartyPolicy.php`           | + `chat` / `end` / `sync` / `join` / `leave` abilities   |
| `app/Policies/MovieSchedulePolicy.php`        | strict per-user                                          |
| `app/Policies/SubscriptionPolicy.php`         | view/cancel by owner; mutations via webhook only         |
| `app/Policies/QuizAttemptPolicy.php`          | per-user (leaderboard reads aggregate, not row)          |
| `app/Policies/RatingPolicy.php`               | public read, owner-only write                            |
| `app/Policies/NotificationPolicy.php`         | + `markAsRead` ability                                   |
| `app/Policies/KnownDevicePolicy.php`          | per-user device registry                                 |
| `app/Http/Middleware/EnsureOwnership.php`     | `owns:<param>[,<column>]` quick guard                    |

All policies registered in `AuthServiceProvider::$policies`.

## Per-controller-method audit

Legend:
- **Pre-existing safe** — no IDOR; ownership enforced via scoped query
  (`where('user_id', auth()->id())`) or pure non-mutating endpoint.
- **Fixed** — replaced ad-hoc `if (... !== auth()->id()) abort(403)`
  with a policy call, OR added a missing policy call.
- **Admin-only (route middleware)** — protected by `can:admin` group.

### CommentController

| Method            | Route binding   | Status | Notes                                                          |
| ----------------- | --------------- | ------ | -------------------------------------------------------------- |
| `store`           | —               | Fixed  | Now `$this->authorize('create', Comment::class)`               |
| `destroy(Comment)`| `comment`       | Fixed  | Replaced ad-hoc check with `$this->authorize('delete', $comment)` |

### NotificationController

| Method                          | Binding         | Status | Notes                                                    |
| ------------------------------- | --------------- | ------ | -------------------------------------------------------- |
| `index`                         | —               | Pre-existing safe | Reads `auth()->user()->notifications()` only |
| `markAsRead(Notification)`      | `notification`  | Fixed  | Replaced manual user_id check with `authorize('markAsRead', ...)` |
| `markAllAsRead`                 | —               | Pre-existing safe | Scoped update on `auth()->user()`         |
| `count`                         | —               | Pre-existing safe |                                            |

### ScheduleController

| Method                          | Binding    | Status | Notes                                                    |
| ------------------------------- | ---------- | ------ | -------------------------------------------------------- |
| `index`                         | —          | Pre-existing safe | Scoped where(user_id)                     |
| `create(Movie)`                 | `movie`    | Pre-existing safe | Movie is global resource                  |
| `store(Movie)`                  | `movie`    | Pre-existing safe | Stamps user_id from `Auth::id()`          |
| `destroy(MovieSchedule)`        | `schedule` | Fixed  | Replaced bespoke `authorizeOwnership()` with `authorize('delete', ...)`; helper removed |
| `ics(MovieSchedule)`            | `schedule` | Fixed  | Same — now uses `authorize('view', ...)` because .ics leaks viewing time + notes |

### WatchPartyController

| Method                                        | Binding    | Status | Notes                                                      |
| --------------------------------------------- | ---------- | ------ | ---------------------------------------------------------- |
| `createForm(Movie)` / `joinForm` / `joinByCode` | `movie`  | Pre-existing safe | Movie / room_code are non-IDOR surfaces       |
| `create`                                      | —          | Pre-existing safe | Stamps `host_id = auth()->id()`             |
| `show(roomCode)`                              | (string)   | Pre-existing safe | Auto-joins caller; capacity check applies   |
| `join(roomCode)` / `leave(roomCode)`          | (string)   | Pre-existing safe | Always operates on auth user's membership   |
| `sync(roomCode)`                              | (string)   | Fixed  | Replaced `if (!isHost) 403` with `Gate::allows('sync', $party)` (JSON-friendly) |
| `chat(roomCode)`                              | (string)   | Fixed  | Replaced manual member check with `Gate::allows('chat', $party)` |
| `end(roomCode)`                               | (string)   | Fixed  | Replaced `if (!isHost) 403` with `Gate::allows('end', $party)`   |

### Profile\\SessionController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `index`                         | —         | Pre-existing safe |                                              |
| `trustDevice(KnownDevice)`      | `device`  | Fixed  | Replaced ad-hoc check with `authorize('update', $device)`   |
| `forgetDevice(KnownDevice)`     | `device`  | Fixed  | Replaced ad-hoc check with `authorize('delete', $device)`   |
| `destroy(sessionId)`            | (string)  | Pre-existing safe | `revoke($sessionId, $userId)` enforces ownership in service |
| `destroyAll`                    | —         | Pre-existing safe | Scoped to current user                       |

### RatingController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `store`                         | —         | Pre-existing safe | `updateOrCreate` keyed on `user_id = auth()->id()` |
| `destroy`                       | —         | Pre-existing safe | Scoped delete on `auth()->id()`              |

### WatchlistController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `index`                         | —         | Pre-existing safe |                                              |
| `toggle`                        | —         | Pre-existing safe | `Watchlist::where('user_id', auth()->id())` query — `movie_id` from request is fine because no other user's row can be touched |

### WatchHistoryController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `updateProgress`                | —         | Pre-existing safe | `updateOrCreate` keyed on `user_id` |
| `getProgress`                   | —         | Pre-existing safe |                                      |

### QuizController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `start(Movie)`                  | `movie`   | Pre-existing safe | Movie is global; quiz questions sanitized   |
| `submit(Movie)`                 | `movie`   | Pre-existing safe | Stamps `user_id` from `request->user()`     |
| `leaderboard(Movie)`            | `movie`   | Pre-existing safe | Aggregate read, no per-user IDOR            |

### YearInReviewController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `show(?year)`                   | —         | Pre-existing safe | Always queries `where(user_id = auth)` |
| `share(int $id)`                | —         | Pre-existing safe | Combined `where(id, user_id)->firstOrFail()` — IDs from another user 404 |

### PaymentController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `checkout(SubscriptionPlan)`    | `plan`    | Pre-existing safe | Plan is a global catalog row — no IDOR    |
| `webhook`                       | —         | Pre-existing safe | Unauthenticated by design; Midtrans signature validates |
| `success`                       | —         | Pre-existing safe |                                              |

### PlaybackController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `config(Movie)`                 | `movie`   | Pre-existing safe | Subscription gate via `hasActiveSubscription()` |
| `manifest(Movie)`               | `movie`   | Pre-existing safe | JWT validation tied to session + movie binding |
| `key(sessionToken, keyId)`      | (strings) | Pre-existing safe | Triple-bound: JWT.session_id == URL && JWT.kid == URL |
| `heartbeat(Movie)`              | `movie`   | Pre-existing safe | JWT + fingerprint TOFU check                |

### HighlightReelController

| Method                          | Binding   | Status | Notes                                                       |
| ------------------------------- | --------- | ------ | ----------------------------------------------------------- |
| `show(Movie)`                   | `movie`   | Pre-existing safe | Movie is global resource                    |
| `download(Movie)`               | `movie`   | Pre-existing safe | Behind `auth` route middleware              |

### Admin\\* controllers

All admin controllers sit inside the `auth + can:admin` route group
(`routes/web.php` line 130). The `admin` Gate (`AuthServiceProvider`) is
true for any staff/super-admin user. No route-model-bound IDOR
opportunities found within those controllers.

## Verification quick-test (manual)

Recommended smoke tests after deploy:

```bash
# As user A (id=2), try to delete user B's (id=3) comment → expect 403
curl -X DELETE -b "user_a_session" /comment/<bid_owned_by_user_b>

# As user A, try to mark user B's notification read → expect 403
curl -X POST -b "user_a_session" /notifications/<nid_owned_by_user_b>/read

# As user A, try to delete user B's schedule → expect 403
curl -X DELETE -b "user_a_session" /my-schedule/<sid_owned_by_user_b>

# As user A, try non-host /watch-party/{room}/sync → expect 403
curl -X POST -b "user_a_session" /watch-party/<other_party>/sync \
     -d 'action=play' -d 'position=0'

# As super-admin, all of the above → expect 200/204 (Gate::before bypass)
```

## Open follow-ups (not blocking)

1. `WatchPartyPolicy::view()` is defined but no controller route currently
   uses route-model binding for `WatchParty` (string `roomCode` is used
   instead). The policy is wired so that future `Route::model('party',
   WatchParty::class)` consumers work without code changes.
2. Subscription cancellation isn't yet exposed as an end-user route.
   `SubscriptionPolicy::cancel()` is reserved for the upcoming
   `PaymentController::cancel()` endpoint.
3. The `EnsureOwnership` middleware is registered as `'owns'` but no
   routes wire it yet — policies cover all current binding cases.
   Reach for `owns:` only when a controller method has no other auth
   logic and you want the simplest possible per-route guard.
