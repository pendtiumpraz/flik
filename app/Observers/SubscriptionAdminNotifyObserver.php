<?php

declare(strict_types=1);

namespace App\Observers;

use App\Listeners\Admin\SubscriptionCancelledListener;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SubscriptionAdminNotifyObserver
 * --------------------------------------------------------------------------
 * Watches the Subscription model for status transitions into 'cancelled'
 * and fans the event out to {@see SubscriptionCancelledListener} so the
 * admin bell can ping the finance team.
 *
 * We use a dedicated observer (not the model's $dispatchesEvents) because
 * we need to compare $original to $current in-flight — only emit when the
 * status TRANSITIONS to 'cancelled', not just on every save where it
 * happens to ALREADY be 'cancelled'.
 *
 * Registered in AppServiceProvider::boot() via Subscription::observe().
 */
class SubscriptionAdminNotifyObserver
{
    public function updated(Subscription $subscription): void
    {
        try {
            // Only fire when the status field actually changed AND the new
            // value is 'cancelled'. `wasChanged()` returns true after the
            // save when the in-memory model's value differs from the value
            // previously persisted (i.e. a genuine transition, not a no-op).
            if (!$subscription->wasChanged('status')) {
                return;
            }
            if ($subscription->status !== 'cancelled') {
                return;
            }

            // Queue the notif rather than running inline — the listener
            // implements ShouldQueue so dispatching it goes to the `default`
            // queue with $tries=2 (failure isolation requirement).
            SubscriptionCancelledListener::dispatch($subscription);
        } catch (Throwable $e) {
            // Observer failures must never break the originating save.
            Log::warning('SubscriptionAdminNotifyObserver failed', [
                'subscription_id' => $subscription->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
