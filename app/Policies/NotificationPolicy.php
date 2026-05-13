<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see Notification}.
 *
 * Notifications are strictly per-user. Marking another user's
 * notification as read would silently dismiss their alerts — classic
 * IDOR. All abilities check user_id ownership.
 */
class NotificationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        // Notifications are produced by the system, not by users.
        return false;
    }

    /**
     * Marking-as-read counts as an update. Owner-only.
     */
    public function update(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    /** Custom ability used by NotificationController::markAsRead. */
    public function markAsRead(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function delete(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }
}
