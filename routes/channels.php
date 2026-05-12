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
