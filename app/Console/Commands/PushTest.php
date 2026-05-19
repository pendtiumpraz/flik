<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Console\Command;

/**
 * flik:push:test {user_id}
 * --------------------------------------------------------------------------
 * Sends a sample notification to every active push subscription belonging
 * to the given user. Handy for verifying:
 *   - VAPID env is loaded
 *   - The browser actually accepted the subscription
 *   - The service-worker push listener fires correctly
 */
class PushTest extends Command
{
    protected $signature = 'flik:push:test {user_id : The ID of the user whose devices to ping}';

    protected $description = 'Send a test Web Push notification to a single user.';

    public function handle(WebPushSender $sender): int
    {
        if (! $sender->enabled()) {
            $this->error('VAPID is not configured. Run `php artisan flik:push:generate-vapid-keys` first.');
            return self::FAILURE;
        }

        $userId = (int) $this->argument('user_id');
        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error("User #{$userId} not found.");
            return self::FAILURE;
        }

        $subs = PushSubscription::query()
            ->where('user_id', $user->id)
            ->healthy()
            ->get();

        if ($subs->isEmpty()) {
            $this->warn("User #{$user->id} ({$user->email}) has no active push subscriptions.");
            return self::SUCCESS;
        }

        $payload = [
            'title'      => 'FLiK test notification',
            'body'       => 'Hello ' . $user->name . '! If you can read this, Web Push is working.',
            'icon'       => '/img/flik-logo.png',
            'badge'      => '/img/flik-logo.png',
            'tag'        => 'flik-test',
            'action_url' => url('/'),
            'data'       => [
                'message_id' => 0,
                'action_url' => url('/'),
            ],
        ];

        $ok = 0;
        $fail = 0;

        foreach ($subs as $sub) {
            $this->line(sprintf('  → sending to subscription #%d (%s)…', $sub->id, $sub->device_type ?? 'unknown'));
            $result = $sender->send($sub, $payload);

            if ($result['success']) {
                $ok++;
                $sub->markDelivered();
                $this->info("    ✓ delivered (HTTP {$result['status']})");
            } else {
                $fail++;
                if (in_array($result['status'], [404, 410], true)) {
                    $sub->delete();
                    $this->warn("    ✗ subscription revoked by push service — pruned");
                } else {
                    $sub->markFailed();
                    $this->warn("    ✗ failed: {$result['reason']}");
                }
            }
        }

        $this->line('');
        $this->info("Done. {$ok} delivered, {$fail} failed.");

        return self::SUCCESS;
    }
}
