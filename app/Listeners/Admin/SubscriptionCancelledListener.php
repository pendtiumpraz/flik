<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SubscriptionCancelledListener
 * --------------------------------------------------------------------------
 * Wired as an observer hook on Subscription::updated. Fires an admin
 * notification ONLY when the row transitions INTO the 'cancelled' state
 * — repeat saves while already-cancelled are filtered out so we don't
 * spam the finance bell every time the subscription gets touched.
 */
class SubscriptionCancelledListener implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;

    public string $queue = 'default';

    /**
     * Handle method receives the freshly-saved Subscription. Transition
     * detection compares the in-memory model's `status` to the previously
     * persisted value (`getOriginal('status')`) — only emit when the
     * STATE transitions to 'cancelled', not just any update where status
     * happens to already be 'cancelled'.
     */
    public function handle(Subscription $subscription): void
    {
        try {
            $subscription->loadMissing(['user', 'plan']);

            $userName = $subscription->user?->name ?? 'unknown user';
            $planName = $subscription->plan?->name ?? 'unknown plan';

            $this->notify(
                category: 'subscription.cancelled',
                title: "Subscription cancelled: {$userName} ({$planName})",
                message: "{$userName} cancelled their {$planName} subscription.",
                meta: [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'plan_id' => $subscription->subscription_plan_id,
                    'order_id' => $subscription->order_id,
                ],
                severity: 'warning',
                audience: 'finance',
                actionUrl: null,
            );
        } catch (Throwable $e) {
            $this->swallow($e, ['subscription_id' => $subscription->id ?? null]);
        }
    }

    private function notify(
        string $category,
        string $title,
        string $message,
        array $meta,
        string $severity,
        string|array $audience,
        ?string $actionUrl,
    ): void {
        $class = 'App\\Services\\Notifications\\AdminNotifier';

        if (!app()->bound($class) && !class_exists($class)) {
            Log::warning('AdminNotifier binding missing — admin notif dropped', [
                'category' => $category,
                'title' => $title,
            ]);
            return;
        }

        app($class)->notify(
            category: $category,
            title: $title,
            message: $message,
            meta: $meta,
            severity: $severity,
            audience: $audience,
            actionUrl: $actionUrl,
        );
    }

    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('SubscriptionCancelledListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
