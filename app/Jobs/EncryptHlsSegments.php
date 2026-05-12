<?php

namespace App\Jobs;

use App\Models\EncodingJob;
use App\Models\Movie;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage 2 of the distribution pipeline: AES-128 encrypt each rendition's
 * HLS segments in place, then hand off to UploadToBunny.
 *
 * Operates on the latest *completed* EncodingJob for the movie — so this
 * job MUST run after TranscodeMovie has finished and persisted output_paths.
 *
 * The actual crypto/key-management is delegated to HlsEncryptor (built by a
 * peer agent); this job is a thin queue wrapper.
 */
class EncryptHlsSegments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 2;

    /** @var int Seconds. 1h is generous for AES-128 over a few hundred TS segments. */
    public $timeout = 3600;

    public function __construct(
        public int $movieId,
    ) {
        $this->onQueue('transcoding');
    }

    public function handle(\App\Services\Drm\HlsEncryptor $encryptor): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('EncryptHlsSegments: movie disappeared', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        // Find the most recent completed transcode job — that's the one
        // whose output_paths reference the un-encrypted HLS dirs.
        $job = EncodingJob::query()
            ->where('movie_id', $movie->id)
            ->where('status', EncodingJob::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if (!$job || empty($job->output_paths)) {
            throw new \RuntimeException(
                "EncryptHlsSegments: no completed encoding job with outputs found for movie {$movie->id}."
            );
        }

        // Reflect the new stage on the job row so admin UI shows progress.
        $job->forceFill([
            'status' => EncodingJob::STATUS_ENCRYPTING,
            'progress_percent' => 0,
        ])->save();

        try {
            $outputs = $job->output_paths;

            foreach ($outputs as $renditionKey => $output) {
                if (empty($output['hls_dir'])) {
                    Log::warning('EncryptHlsSegments: missing hls_dir for rendition', [
                        'movie_id' => $movie->id,
                        'rendition' => $renditionKey,
                    ]);
                    continue;
                }

                $result = $encryptor->encrypt(
                    movie: $movie,
                    hlsDir: $output['hls_dir'],
                    renditionKey: $renditionKey,
                );

                // Merge encryption metadata back into the rendition record so
                // UploadToBunny knows what to push and the player knows where
                // to fetch keys from.
                $outputs[$renditionKey] = array_merge($output, [
                    'encrypted' => true,
                    'encryption' => $result,
                ]);
            }

            // Persist the enriched outputs map; revert status to completed
            // so the next stage (Upload) sees a clean baseline.
            $job->forceFill([
                'output_paths' => $outputs,
                'status' => EncodingJob::STATUS_COMPLETED,
                'progress_percent' => 100,
            ])->save();

            UploadToBunny::dispatch($movie->id);
        } catch (Throwable $e) {
            $job->markFailed('Encryption failed: '.$e->getMessage());
            $movie->forceFill(['encoding_status' => 'failed'])->save();
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('EncryptHlsSegments job permanently failed', [
            'movie_id' => $this->movieId,
            'error' => $e->getMessage(),
        ]);
    }
}
