<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see Subscription}.
 *
 * Per-user payment records. Reading another user's billing rows leaks
 * PII (Midtrans order_id + amounts) — keep strictly owner-only. Admins
 * with finance access still see them via Gate::before bypass.
 *
 * Note: actual payment writes happen through the Midtrans webhook,
 * which is unauthenticated; that endpoint validates by signature and
 * order_id, not by Laravel auth, so no policy guard there.
 */
class SubscriptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return (int) $subscription->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * End-users cannot mutate their own subscription record directly
     * (Midtrans webhook drives state). Admin override via Gate::before.
     */
    public function update(User $user, Subscription $subscription): bool
    {
        return false;
    }

    /**
     * Self-cancel a subscription. Admins may also cancel via override.
     */
    public function cancel(User $user, Subscription $subscription): bool
    {
        return (int) $subscription->user_id === (int) $user->id;
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
