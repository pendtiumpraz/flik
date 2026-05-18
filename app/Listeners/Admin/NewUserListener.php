<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NewUserListener
 * --------------------------------------------------------------------------
 * Pings the admin bell whenever a fresh User account is registered (any
 * registration channel: email signup, OAuth bootstrap, seeders that fire
 * the Registered event). Audience is admin + super_admin because user
 * growth is a top-of-funnel metric finance/moderators do not need pinged
 * about on every signup.
 */
class NewUserListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 2;

    public string $queue = 'default';

    public function handle(Registered $event): void
    {
        try {
            $user = $event->user;

            if (!$user instanceof User) {
                return;
            }

            $email = (string) ($user->email ?? 'unknown');
            $name = (string) ($user->name ?? $email);

            $this->notify(
                category: 'user.registered',
                title: "New user: {$email}",
                message: "{$name} just signed up.",
                meta: [
                    'user_id' => $user->id,
                    'email' => $email,
                    'name' => $name,
                ],
                severity: 'info',
                audience: ['admin', 'super_admin'],
                actionUrl: $this->safeRoute('admin.users.index'),
            );
        } catch (Throwable $e) {
            $this->swallow($e, ['user_id' => $event->user->id ?? null]);
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

    private function safeRoute(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (Throwable) {
            return null;
        }
    }

    private function swallow(Throwable $e, array $ctx = []): void
    {
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')
            ->warning('NewUserListener failed', $ctx + ['error' => $e->getMessage()]);
    }
}
