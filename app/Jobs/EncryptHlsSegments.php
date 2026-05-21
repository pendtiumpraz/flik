<?php

namespace App\Jobs;

use App\Models\EncodingJob;
use App\Models\Movie;
use App\Services\Drm\DrmKeyService;
use App\Services\Drm\HlsEncryptor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
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

    /**
     * Encrypt every rendition's HLS segments with a fresh AES-128 content key.
     *
     * One content key per encode — shared across all renditions so the player
     * doesn't need a fresh fetch when switching bitrates. The raw 16-byte key
     * is persisted on the movie row encrypted via Laravel's Crypt facade
     * (lives in `movies.drm_config.content_key_encrypted`). At playback time
     * PlaybackController mints PER-USER DrmSession rows; the per-user
     * session's `content_key` field is hydrated from this stored key.
     *
     * Method signature MUST be HlsEncryptor::encryptSegments($hlsDir, $contentKey, $keyUrl)
     * — see docs/audit/04-drm-playback.md §2.2.
     */
    public function handle(
        HlsEncryptor $encryptor,
        DrmKeyService $keys,
    ): void {
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

            // One AES-128 content key per encode, shared across all renditions.
            $contentKey = $keys->generateContentKey();

            // The key URL baked into the encrypted manifest at FFmpeg-encrypt
            // time is just a static template — at playback the dynamic
            // manifest generator REWRITES this to a per-user, signed URL.
            // FFmpeg requires *some* URL though, so we point at a sentinel
            // that the manifest rewriter will replace before serving.
            $keyUrlTemplate = url('/drm/key/static/'.$movie->slug);

            $total = count($outputs);
            $done = 0;

            foreach ($outputs as $renditionKey => $output) {
                if (empty($output['hls_dir'])) {
                    Log::warning('EncryptHlsSegments: missing hls_dir for rendition', [
                        'movie_id' => $movie->id,
                        'rendition' => $renditionKey,
                    ]);
                    continue;
                }

                $encryptedManifest = $encryptor->encryptSegments(
                    $output['hls_dir'],
                    $contentKey,
                    $keyUrlTemplate,
                );

                // Merge encryption metadata back into the rendition record so
                // UploadToBunny knows what to push.
                $outputs[$renditionKey] = array_merge($output, [
                    'encrypted' => true,
                    'manifest' => $encryptedManifest, // overwrite with encrypted.m3u8 absolute path
                    'encryption' => [
                        'key_url_template' => $keyUrlTemplate,
                    ],
                ]);

                $done++;
                $job->updateProgress((int) min(99, ($done / max(1, $total)) * 100));
            }

            // Persist the enriched outputs map; revert status to completed
            // so the next stage (Upload) sees a clean baseline.
            $job->forceFill([
                'output_paths' => $outputs,
                'status' => EncodingJob::STATUS_COMPLETED,
                'progress_percent' => 100,
            ])->save();

            // Stamp DRM strategy + encrypted content key on the movie. The
            // key is wrapped via Crypt::encryptString so it never sits in
            // plaintext on disk; PlaybackController will Crypt::decryptString
            // it and seed the per-user DrmSession with the same 16 bytes.
            $movie->forceFill([
                'drm_strategy' => 'diy_aes128',
                'drm_config' => [
                    'content_key_encrypted' => Crypt::encryptString($contentKey),
                    'key_url_template' => $keyUrlTemplate,
                ],
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
