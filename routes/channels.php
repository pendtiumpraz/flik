<?php

use App\Broadcasting\WatchPartyChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Watch Party presence channel — auth callback verifies the user is the
// host or an active member of the room before allowing Pusher subscription.
Broadcast::channel('watch-party.{roomCode}', [WatchPartyChannel::class, 'join']);

/*
|--------------------------------------------------------------------------
| Admin Notification Channels (peer NOTIF #1 contract)
|--------------------------------------------------------------------------
|
| Two channel shapes back the realtime admin alert system:
|
|   1. `admin-notifications.{role}`  — fired for notifications whose
|      audience targets a specific role (e.g. 'finance', 'moderator').
|      Authorised iff the user holds that role OR is a super-admin.
|      Super-admins implicitly receive ALL role-targeted channels so the
|      "god mode" account never has to subscribe N times.
|
|   2. `admin-notifications.all-admins` — fired for `audience='all_admins'`
|      notifications. Any staff user (super_admin, admin, content_*,
|      customer_support, moderator, finance) is allowed in.
|
| The `all-admins` callback is registered FIRST so its more-specific name
| wins over the wildcard `{role}` route on the Pusher auth lookup.
*/

// All-admins wildcard channel — every staffer subscribes here.
Broadcast::channel('admin-notifications.all-admins', function ($user) {
    // `isStaff()` is the authoritative gate; fall back to a literal role
    // list for fresh installs where the role pivot is not yet seeded.
    if (method_exists($user, 'isStaff') && $user->isStaff()) {
        return true;
    }

    return $user->hasRole([
        'super_admin', 'admin', 'content_manager', 'content_editor',
        'customer_support', 'moderator', 'finance',
    ]);
});

// Per-role channel — `{role}` is a free-form slug bound at subscribe time.
Broadcast::channel('admin-notifications.{role}', function ($user, $role) {
    // Super-admins receive everything.
    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return true;
    }

    return $user->hasRole($role);
});
