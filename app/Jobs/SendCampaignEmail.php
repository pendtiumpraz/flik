<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\CampaignMailable;
use App\Models\EmailCampaign;
use App\Models\EmailRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SendCampaignEmail — dispatches a single rendered campaign email to one
 * recipient and updates the per-recipient tracking columns.
 *
 * Fanned out per-recipient (not per-batch) by CampaignDispatcher so the
 * queue worker can parallelise SMTP/SES throughput. The mailable itself
 * (CampaignMailable) handles personalization-token replacement, tracking
 * pixel injection, and link rewriting.
 *
 * Queue: `ai-batch` so it shares budget with weekly digests and the
 * nightly recommendation recompute (those are similarly bulky).
 *
 * Failure handling:
 *   - 3 tries with exponential backoff (60s, 5min, 15min).
 *   - On final failure, recipient.failed_at is stamped + campaign.bounce_count
 *     is incremented. The job does NOT re-throw on the final attempt because
 *     the queue worker would otherwise mark the entire campaign dead — we
 *     want one bad recipient to fail in isolation.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $recipientId,
    ) {
        $this->onQueue('ai-batch');
    }

    /**
     * Backoff in seconds between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $recipient = EmailRecipient::with('campaign', 'user')->find($this->recipientId);

        if (!$recipient) {
            Log::info('SendCampaignEmail: recipient vanished', [
                'recipient_id' => $this->recipientId,
            ]);
            return;
        }

        $campaign = $recipient->campaign;

        if (!$campaign) {
            Log::warning('SendCampaignEmail: campaign vanished', [
                'recipient_id' => $this->recipientId,
            ]);
            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_CANCELLED) {
            Log::info('SendCampaignEmail: skipping cancelled campaign', [
                'recipient_id' => $this->recipientId,
                'campaign_id'  => $campaign->id,
            ]);
            return;
        }

        // Idempotency — if this recipient was already sent (e.g. retry after
        // an earlier success that crashed before recording), skip the second
        // send so we don't double-email the user.
        if ($recipient->sent_at !== null) {
            return;
        }

        try {
            Mail::to($recipient->email)->send(new CampaignMailable($campaign, $recipient));

            $recipient->forceFill(['sent_at' => now()])->save();

            // Atomic counter bump — avoid read-modify-write races when many
            // workers complete in parallel.
            EmailCampaign::query()
                ->where('id', $campaign->id)
                ->increment('send_count');
        } catch (\Throwable $e) {
            Log::warning('SendCampaignEmail: send failed', [
                'recipient_id' => $this->recipientId,
                'campaign_id'  => $campaign->id,
                'attempt'      => $this->attempts(),
                'error'        => $e->getMessage(),
            ]);

            // Stamp failure metadata even on intermediate attempts so the
            // admin UI can show "last attempt failed: …" without waiting
            // for the final retry to exhaust.
            $recipient->forceFill([
                'error_reason' => mb_substr($e->getMessage(), 0, 240),
            ])->save();

            // Only count + finalise the failure on the LAST attempt.
            if ($this->attempts() >= $this->tries) {
                $recipient->forceFill([
                    'failed_at' => now(),
                    'bounced_at' => $recipient->bounced_at ?? now(),
                ])->save();

                EmailCampaign::query()
                    ->where('id', $campaign->id)
                    ->increment('bounce_count');

                // Mark the campaign `sent` once every recipient has either
                // succeeded or failed terminally. This is a cheap final-row
                // check, not a full audit — the report page recomputes
                // accurate stats anyway.
                $this->maybeMarkSent($campaign->id);
                return;
            }

            // Let the queue retry; do NOT call $this->fail() because that
            // permanently dead-letters the job.
            throw $e;
        }

        $this->maybeMarkSent($campaign->id);
    }

    /**
     * Flip the campaign to 'sent' once no recipients are still pending.
     */
    private function maybeMarkSent(int $campaignId): void
    {
        $pending = EmailRecipient::query()
            ->where('email_campaign_id', $campaignId)
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->exists();

        if ($pending) {
            return;
        }

        EmailCampaign::query()
            ->where('id', $campaignId)
            ->whereIn('status', [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_QUEUED])
            ->update([
                'status'  => EmailCampaign::STATUS_SENT,
                'sent_at' => DB::raw('COALESCE(sent_at, NOW())'),
            ]);
    }
}
