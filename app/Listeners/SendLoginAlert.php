<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\NewDeviceLogin;
use App\Models\Notification;
use App\Models\User;
use App\Services\Security\LoginAlertService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SendLoginAlert
 * --------------------------------------------------------------------------
 * Subscribes to Illuminate\Auth\Events\Login and runs the new-device /
 * new-country detection.
 *
 * Side effects (only when LoginAlertService says `should_alert`):
 *   - Insert an in-app `notifications` row (the bell icon picks it up).
 *   - Queue a `NewDeviceLogin` mailable on the default queue (the
 *     mailable implements ShouldQueue, so Mail::to(...)->send(...)
 *     auto-queues; we use `send` not `queue` to keep call-site simple).
 *
 * The listener itself runs synchronously inside the auth flow because the
 * fingerprint/country lookups are cheap (one indexed select, optionally
 * one cached HTTP call). Only the email is deferred to the queue.
 *
 * Failure isolation: every step is wrapped in try/catch. We must NEVER
 * let a security-alert hiccup block a successful login.
 */
class SendLoginAlert
{
    public function __construct(
        private readonly LoginAlertService $alertService,
    ) {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // No request context → CLI/queue/test login. Nothing to fingerprint.
        if (! app()->bound('request')) {
            return;
        }

        $request = request();

        try {
            $result = $this->alertService->recordAndCheck($user, $request);
        } catch (Throwable $e) {
            Log::warning('SendLoginAlert: recordAndCheck failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        if (! ($result['should_alert'] ?? false)) {
            return;
        }

        $device = $result['device'];

        $this->createNotification($user, $device, (bool) $result['is_new_device'], (bool) $result['is_new_country']);
        $this->queueEmail($user, $device, (bool) $result['is_new_device'], (bool) $result['is_new_country']);
    }

    private function createNotification(
        User $user,
        \App\Models\KnownDevice $device,
        bool $isNewDevice,
        bool $isNewCountry,
    ): void {
        try {
            $title = match (true) {
                $isNewDevice && $isNewCountry => 'New device & new location signed in',
                $isNewDevice                  => 'New device signed in to your account',
                $isNewCountry                 => 'Sign-in from a new location',
                default                       => 'Security activity on your account',
            };

            $location = $device->country ? " ({$device->country})" : '';
            $message  = "Sign-in from {$device->display_name} at {$device->ip}{$location}. If this wasn't you, review your sessions.";

            Notification::create([
                'user_id'    => $user->id,
                'type'       => 'security',
                'title'      => $title,
                'message'    => $message,
                'action_url' => '/profile/sessions',
            ]);
        } catch (Throwable $e) {
            Log::warning('SendLoginAlert: notification insert failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function queueEmail(
        User $user,
        \App\Models\KnownDevice $device,
        bool $isNewDevice,
        bool $isNewCountry,
    ): void {
        $email = (string) ($user->email ?? '');
        if ($email === '') {
            return;
        }

        try {
            // NewDeviceLogin implements ShouldQueue, so this dispatches
            // onto the default queue rather than blocking the request.
            Mail::to($email)->send(new NewDeviceLogin(
                user: $user,
                device: $device,
                isNewDevice: $isNewDevice,
                isNewCountry: $isNewCountry,
                countryName: $device->country,
                loginAt: now()->toDayDateTimeString(),
            ));
        } catch (Throwable $e) {
            Log::warning('SendLoginAlert: mail dispatch failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
