<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PaymentEventListener
 * --------------------------------------------------------------------------
 * NOT a traditional event listener — wired directly from
 * PaymentController::webhook() because Midtrans doesn't dispatch Laravel
 * events of its own, and we want this code path under our control (the
 * webhook payload is server-trusted, signature-verified, and we don't
 * want to invent a synthetic event just to fire and consume it).
 *
 * Implements ShouldQueue + queueing traits so the
 * `dispatchToQueue($context)` helper can defer the work onto the
 * `default` queue when the caller doesn't want to do it inline. The
 * webhook handler uses the queued path so a notification glitch can
 * never delay Midtrans' acknowledgement (Midtrans retries aggressively
 * on slow callbacks).
 */
class PaymentEventListener implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 2;

    public string $queue = 'default';

    /**
     * Dispatch shape — invoked as a queued job via:
     *   PaymentEventListener::dispatch($context)
     * (uses the Queueable trait's static dispatcher).
     *
     * @param  array{kind:string,subscription_id?:int,status?:string,fraud_status?:string,raw?:array<string,mixed>}  $context
     */
    public function handle(array $context): void
    {
        try {
            $kind = (string) ($context['kind'] ?? '');
            $subscriptionId = $context['subscription_id'] ?? null;

            if ($kind === '' || $subscriptionId === null) {
                return;
            }

            $subscription = Subscription::query()->with(['user', 'plan'])->find($subscriptionId);

            if ($subscription === null) {
                Log::warning('PaymentEventListener: subscription disappeared before notif', [
                    'subscription_id' => $subscriptionId,
                ]);
                return;
            }

            $userName = $subscription->user?->name ?? 'unknown user';
            $planName = $subscription->plan?->name ?? 'unknown plan';
            $amount = (int) ($subscription->amount ?? 0);
            $amountIdr = 'Rp ' . number_format($amount, 0, ',', '.');

            $meta = [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'order_id' => $subscription->order_id,
                'amount' => $amount,
                'status' => $context['status'] ?? null,
                'fraud_status' => $context['fraud_status'] ?? null,
            ];

            switch ($kind) {
                case 'success':
                    $this->notify(
                        category: 'payment.success',
                        title: "Payment received: {$userName}",
                        message: "{$userName} subscribed to {$planName} — {$amountIdr}",
                        meta: $meta,
                        severity: 'info',
                        audience: 'finance',
                        actionUrl: null,
                    );
                    break;

                case 'failed':
                    $failureMessage = (string) ($context['failure_message']
                        ?? $context['status']
                        ?? 'payment failed');
                    $this->notify(
                        category: 'payment.failed',
                        title: "Payment failed: {$userName} ({$planName})",
                        message: "Failure for {$userName} ({$planName}): {$failureMessage}",
                        meta: $meta + ['failure_message' => $failureMessage],
                        severity: 'warning',
                        audience: 'finance',
                        actionUrl: null,
                    );
                    break;

                case 'chargeback':
                    $this->notify(
                        category: 'payment.chargeback',
                        title: "CHARGEBACK: {$userName} ({$planName})",
                        message: "Chargeback raised for {$userName} on {$planName} ({$amountIdr}).",
                        meta: $meta,
                        severity: 'critical',
                        audience: ['finance', 'super_admin'],
                        actionUrl: null,
                    );
                    break;

                default:
                    Log::warning('PaymentEventListener: unknown kind', ['kind' => $kind]);
                    return;
            }
        } catch (Throwable $e) {
            $this->swallow($e, ['subscription_id' => $context['subscription_id'] ?? null]);
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
            ->warning('PaymentEventListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
