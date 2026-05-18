<?php

declare(strict_types=1);

namespace App\Observers;

use App\Listeners\Admin\EncodingJobUpdatedListener;
use App\Models\EncodingJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EncodingJobAdminNotifyObserver
 * --------------------------------------------------------------------------
 * Detects EncodingJob status transitions into the terminal states
 * (completed / failed) and queues an admin bell notification via
 * {@see EncodingJobUpdatedListener}.
 *
 * Intermediate transitions (queued → transcoding → encrypting → uploading)
 * are intentionally ignored — the encoding dashboard polls those at a
 * higher cadence than a bell notification could reasonably absorb.
 *
 * Registered in AppServiceProvider::boot() via EncodingJob::observe().
 */
class EncodingJobAdminNotifyObserver
{
    public function updated(EncodingJob $job): void
    {
        try {
            // Only emit on a genuine state TRANSITION. wasChanged('status')
            // returns true after a save when the in-memory value differs
            // from what was previously persisted — so re-saving an already-
            // completed job (e.g. backfilling output_paths) does NOT re-emit.
            if (!$job->wasChanged('status')) {
                return;
            }

            if (!in_array($job->status, [
                EncodingJob::STATUS_COMPLETED,
                EncodingJob::STATUS_FAILED,
            ], true)) {
                return;
            }

            EncodingJobUpdatedListener::dispatch($job);
        } catch (Throwable $e) {
            Log::warning('EncodingJobAdminNotifyObserver failed', [
                'encoding_job_id' => $job->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
