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
 * Stage 3 (final) of the distribution pipeline: ship every encrypted segment
 * + manifest from the local job work dir up to Bunny CDN, then flip the
 * movie's `encoding_status` to ready and stamp manifest paths.
 *
 * Higher tries (3) than the transcoding/encryption stages because network
 * transfers to a remote storage endpoint are the most flap-prone step in
 * the pipeline — and re-uploading is cheap relative to re-encoding.
 */
class UploadToBunny implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var int Seconds. 1h covers a multi-rendition push over a slow link. */
    public $timeout = 3600;

    public function __construct(
        public int $movieId,
    ) {
        $this->onQueue('transcoding');
    }

    public function handle(\App\Services\Storage\BunnyStorageService $bunny): void
    {
        $movie = Movie::find($this->movieId);

        if (!$movie) {
            Log::warning('UploadToBunny: movie disappeared', [
                'movie_id' => $this->movieId,
            ]);
            return;
        }

        $job = EncodingJob::query()
            ->where('movie_id', $movie->id)
            ->where('status', EncodingJob::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if (!$job || empty($job->output_paths)) {
            throw new \RuntimeException(
                "UploadToBunny: no completed encoding job with outputs found for movie {$movie->id}."
            );
        }

        $job->forceFill([
            'status' => EncodingJob::STATUS_UPLOADING,
            'progress_percent' => 0,
        ])->save();

        try {
            $outputs = $job->output_paths;
            $renditionCount = max(1, count($outputs));
            $perRenditionPct = (int) floor(90 / $renditionCount);
            $renditionIdx = 0;

            $cdnRenditions = [];
            $masterManifestRemote = null;

            foreach ($outputs as $renditionKey => $output) {
                if (empty($output['hls_dir'])) {
                    Log::warning('UploadToBunny: missing hls_dir for rendition', [
                        'movie_id' => $movie->id,
                        'rendition' => $renditionKey,
                    ]);
                    continue;
                }

                $remotePrefix = sprintf('movies/%s/hls/%s', $movie->slug, $renditionKey);

                $uploaded = $bunny->uploadDirectory(
                    localDir: $output['hls_dir'],
                    remotePrefix: $remotePrefix,
                );

                $cdnRenditions[$renditionKey] = array_merge($output, [
                    'cdn_prefix' => $remotePrefix,
                    'cdn_files' => $uploaded,
                    'cdn_manifest' => $remotePrefix.'/'.basename($output['manifest'] ?? 'index.m3u8'),
                ]);

                $renditionIdx++;
                $job->updateProgress(min(95, $renditionIdx * $perRenditionPct));
            }

            // Build / upload the master manifest that ties all renditions together.
            // BunnyStorageService is expected to expose a helper for this; if not,
            // we fall back to picking the highest rendition's manifest as the
            // entrypoint (single-bitrate playback) so the page never goes dark.
            if (method_exists($bunny, 'writeMasterManifest')) {
                $masterManifestRemote = $bunny->writeMasterManifest($movie, $cdnRenditions);
            } elseif (!empty($cdnRenditions)) {
                $first = reset($cdnRenditions);
                $masterManifestRemote = $first['cdn_manifest'] ?? null;
            }

            // Persist final job + movie state in one go.
            $job->forceFill([
                'output_paths' => $cdnRenditions,
                'status' => EncodingJob::STATUS_COMPLETED,
                'progress_percent' => 100,
                'completed_at' => now(),
            ])->save();

            $movie->forceFill([
                'cdn_disk' => 'bunny',
                'hls_manifest_path' => $masterManifestRemote,
                'encoding_status' => 'ready',
                'encoding_renditions' => array_values(array_map(static function ($r) {
                    return [
                        'height' => $r['spec']['height'] ?? null,
                        'bitrate' => $r['spec']['bitrate'] ?? null,
                        'manifest' => $r['cdn_manifest'] ?? null,
                    ];
                }, $cdnRenditions)),
            ])->save();

            Log::info('UploadToBunny completed', [
                'movie_id' => $movie->id,
                'manifest' => $masterManifestRemote,
                'renditions' => array_keys($cdnRenditions),
            ]);
        } catch (Throwable $e) {
            $job->markFailed('Upload failed: '.$e->getMessage());
            $movie->forceFill(['encoding_status' => 'failed'])->save();
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('UploadToBunny job permanently failed', [
            'movie_id' => $this->movieId,
            'error' => $e->getMessage(),
        ]);
    }
}
