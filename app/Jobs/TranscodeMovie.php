<?php

namespace App\Jobs;

use App\Models\EncodingJob;
use App\Models\Movie;
use App\Services\Transcoding\TranscodingPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage 1 of the distribution pipeline: transcode the master mp4 into the
 * full ABR ladder + per-rendition HLS segments (still un-encrypted, still
 * sitting on local disk).
 *
 * On success, chains into EncryptHlsSegments → UploadToBunny.
 *
 * - Routed to the dedicated `transcoding` queue so it doesn't compete with
 *   short-lived AI tasks on the realtime queue.
 * - tries=2 because a redo of an hour-long ffmpeg encode is expensive; we
 *   want one retry for transient I/O glitches but not endless thrashing.
 * - timeout=7200 (2h) covers most feature-length films at 1080p; longer
 *   masters should be split or use a higher-tier worker.
 */
class TranscodeMovie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 2;

    /** @var int Seconds before forced kill. 2 hours covers ≤90-min films at 1080p. */
    public $timeout = 7200;

    public function __construct(
        public int $movieId,
    ) {
        $this->onQueue('transcoding');
    }

    public function handle(TranscodingPipeline $pipeline): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('TranscodeMovie: movie disappeared before processing', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        // Mark the movie as actively processing so the catalog UI can render
        // a "preparing" badge instead of a broken play button.
        if ($movie->encoding_status !== 'processing') {
            $movie->forceFill(['encoding_status' => 'processing'])->save();
        }

        // Reuse an existing queued/in-flight job row where possible so a
        // retry doesn't accumulate orphan rows. Only rows still in queued
        // (or transcoding for retry) are reused; failed/completed get a new row.
        $job = EncodingJob::query()
            ->where('movie_id', $movie->id)
            ->whereIn('status', [
                EncodingJob::STATUS_QUEUED,
                EncodingJob::STATUS_TRANSCODING,
            ])
            ->latest('id')
            ->first();

        if (!$job) {
            // EncodingJob uses $guarded = ['*'] (mass-assignment audit,
            // 2026-05-13). Pipeline is a trusted internal writer.
            $job = EncodingJob::forceCreate([
                'movie_id' => $movie->id,
                'status' => EncodingJob::STATUS_QUEUED,
                'progress_percent' => 0,
            ]);
        }

        $pipeline->run($movie, $job);

        // On success, hand off to encryption stage. We dispatch a fresh job
        // (not chained via Bus::chain) so each stage manages its own retries
        // independently — re-encrypting doesn't require re-transcoding.
        EncryptHlsSegments::dispatch($movie->id);
    }

    /**
     * Final-failure hook (after all retries exhausted).
     */
    public function failed(Throwable $e): void
    {
        Log::error('TranscodeMovie job permanently failed', [
            'movie_id' => $this->movieId,
            'error' => $e->getMessage(),
        ]);

        // Best-effort: flip the movie's high-level status so the admin sees
        // it instead of having to dig into encoding_jobs.
        $movie = Movie::find($this->movieId);
        if ($movie) {
            $movie->forceFill(['encoding_status' => 'failed'])->save();
        }
    }
}
