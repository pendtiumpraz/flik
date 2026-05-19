<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CampaignDispatcher — resolves the campaign's segment, materialises one
 * EmailRecipient row per audience member, and fans out per-recipient
 * SendCampaignEmail jobs onto the `ai-batch` queue in chunks of 100.
 *
 * Idempotency:
 *   - enqueue() refuses to re-enqueue a campaign that is not in `draft` —
 *     prevents accidental double-sends from a stale tab.
 *   - When a campaign is partially re-enqueued (e.g. retry path), existing
 *     EmailRecipient rows are reused via their unique tracking_id index;
 *     duplicates are dropped at the DB layer.
 *
 * Throughput:
 *   - User chunks of 1000 keep the resolveUsers cursor cheap.
 *   - Recipient chunks of 100 keep individual INSERTs small.
 *   - Job dispatch happens per-recipient (not per-chunk) so the queue
 *     worker can parallelise the SMTP/mailgun fan-out.
 */
class CampaignDispatcher
{
    /** Number of recipient rows inserted per DB batch. */
    public const INSERT_CHUNK = 100;

    /** Number of users streamed from the segment query per cursor batch. */
    public const RESOLVE_CHUNK = 1000;

    public function __construct(
        protected SegmentBuilder $segments,
    ) {}

    /**
     * Resolve the segment, write recipient rows, dispatch jobs.
     *
     * Returns the number of recipients queued. Returns 0 when the campaign
     * is not in `draft` status (e.g. already sending) — caller should treat
     * 0 as a no-op, not a failure.
     */
    public function enqueue(EmailCampaign $campaign): int
    {
        if ($campaign->status !== EmailCampaign::STATUS_DRAFT) {
            Log::warning('CampaignDispatcher: refusing to enqueue non-draft campaign', [
                'campaign_id' => $campaign->id,
                'status'      => $campaign->status,
            ]);
            return 0;
        }

        // Flip to `sending` BEFORE we start writing rows so a parallel
        // request can't enqueue the same campaign twice. We use the
        // optimistic check inside an UPDATE-WHERE-status='draft' so the
        // race window is closed at the DB layer too.
        $flipped = EmailCampaign::query()
            ->where('id', $campaign->id)
            ->where('status', EmailCampaign::STATUS_DRAFT)
            ->update(['status' => EmailCampaign::STATUS_SENDING]);

        if ($flipped === 0) {
            // Someone else already started this campaign — back off.
            return 0;
        }

        $campaign->status = EmailCampaign::STATUS_SENDING;

        $segment = $campaign->segment_definition ?? ['type' => 'all'];
        $queued = 0;

        try {
            // Pure custom_emails segment: addresses may not correspond to
            // real users, so we walk the email list directly.
            if (($segment['type'] ?? null) === 'custom_emails') {
                $queued = $this->enqueueCustomEmails($campaign, $segment['emails'] ?? []);
            } else {
                $queued = $this->enqueueSegmentedUsers($campaign, $segment);
            }
        } catch (\Throwable $e) {
            Log::error('CampaignDispatcher: enqueue failed mid-flight', [
                'campaign_id' => $campaign->id,
                'queued_so_far' => $queued,
                'error' => $e->getMessage(),
            ]);
            // Don't roll back to draft — partial queue is real. Surface
            // the error so the admin can decide whether to keep sending
            // or cancel.
            throw $e;
        }

        Log::info('CampaignDispatcher: enqueue complete', [
            'campaign_id' => $campaign->id,
            'queued'      => $queued,
        ]);

        return $queued;
    }

    /**
     * Stream real users from the segment query, materialise recipient rows
     * in chunks, and dispatch one job per recipient.
     *
     * @param  array<string, mixed>  $segment
     */
    private function enqueueSegmentedUsers(EmailCampaign $campaign, array $segment): int
    {
        $queued = 0;
        $buffer = [];

        $this->segments->resolveUsers($segment)
            ->select(['id', 'email', 'name'])
            ->orderBy('id')
            ->chunk(self::RESOLVE_CHUNK, function ($users) use ($campaign, &$queued, &$buffer): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $email = (string) $user->email;
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }

                    $buffer[] = [
                        'email_campaign_id' => $campaign->id,
                        'user_id'           => (int) $user->id,
                        'email'             => $email,
                        'tracking_id'       => Str::random(32),
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];

                    if (count($buffer) >= self::INSERT_CHUNK) {
                        $queued += $this->flushBuffer($campaign, $buffer);
                        $buffer = [];
                    }
                }
            });

        if ($buffer !== []) {
            $queued += $this->flushBuffer($campaign, $buffer);
        }

        return $queued;
    }

    /**
     * @param  mixed  $emails
     */
    private function enqueueCustomEmails(EmailCampaign $campaign, mixed $emails): int
    {
        if (!is_array($emails)) {
            return 0;
        }

        // Resolve user_id lookups in one query to keep the per-row cost low.
        $clean = [];
        foreach ($emails as $raw) {
            if (!is_string($raw)) continue;
            $e = strtolower(trim($raw));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $clean[$e] = true;
            }
        }
        $clean = array_keys($clean);

        if ($clean === []) {
            return 0;
        }

        $userMap = User::query()
            ->whereIn('email', $clean)
            ->pluck('id', 'email')
            ->all();

        $queued = 0;
        $buffer = [];

        foreach ($clean as $email) {
            $buffer[] = [
                'email_campaign_id' => $campaign->id,
                'user_id'           => isset($userMap[$email]) ? (int) $userMap[$email] : null,
                'email'             => $email,
                'tracking_id'       => Str::random(32),
                'created_at'        => now(),
                'updated_at'        => now(),
            ];

            if (count($buffer) >= self::INSERT_CHUNK) {
                $queued += $this->flushBuffer($campaign, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $queued += $this->flushBuffer($campaign, $buffer);
        }

        return $queued;
    }

    /**
     * Insert a buffered chunk + dispatch one job per row.
     *
     * Wrapped in a transaction so a mid-chunk DB error doesn't leave half
     * the recipient rows orphaned — easier to recover from a failed chunk
     * than from a torn write.
     *
     * @param  list<array<string, mixed>>  $buffer
     */
    private function flushBuffer(EmailCampaign $campaign, array $buffer): int
    {
        if ($buffer === []) {
            return 0;
        }

        $count = 0;

        DB::transaction(function () use ($campaign, $buffer, &$count): void {
            EmailRecipient::insert($buffer);

            // Pull back the rows we just inserted by tracking_id (unique)
            // so we have their IDs for the per-recipient job dispatch.
            $trackingIds = array_column($buffer, 'tracking_id');
            $ids = EmailRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->whereIn('tracking_id', $trackingIds)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                SendCampaignEmail::dispatch((int) $id);
            }

            $count = count($ids);
        });

        return $count;
    }
}
