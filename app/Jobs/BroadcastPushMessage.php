<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushMessage;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * BroadcastPushMessage
 * --------------------------------------------------------------------------
 * Background fan-out for a single PushMessage. Mounted on the `default`
 * queue because broadcasts are usually one-shot (admin clicks "Send" and
 * waits seconds, not minutes) — heavy batches can be re-queued to a
 * dedicated `notifications` worker later if volume warrants it.
 *
 * Idempotency: the job no-ops when sent_at is already populated, so a
 * retry after a queue worker crash will not double-send.
 */
class BroadcastPushMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Don't retry — the broadcaster handles per-subscription failures itself. */
    public int $tries = 1;

    /** Generous timeout — large audiences + slow push services. */
    public int $timeout = 600;

    public function __construct(
        public int $pushMessageId,
    ) {
    }

    public function handle(WebPushSender $sender): void
    {
        $message = PushMessage::query()->find($this->pushMessageId);
        if ($message === null) {
            Log::warning('BroadcastPushMessage: message not found', ['id' => $this->pushMessageId]);
            return;
        }

        if ($message->isDelivered()) {
            Log::info('BroadcastPushMessage: already delivered, skipping', ['id' => $message->id]);
            return;
        }

        if (! $sender->enabled()) {
            Log::warning('BroadcastPushMessage: VAPID not configured, aborting', ['id' => $message->id]);
            return;
        }

        $totals = $sender->sendToAll($message);

        Log::info('BroadcastPushMessage: completed', [
            'id'      => $message->id,
            'sent'    => $totals['sent'],
            'success' => $totals['success'],
            'failure' => $totals['failure'],
        ]);
    }
}
