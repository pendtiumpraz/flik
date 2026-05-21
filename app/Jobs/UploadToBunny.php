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

            // Distinguish between Bunny-on and Bunny-off (dev mode). When
            // off, we still flip encoding_status=ready but point at the
            // LOCAL files so the manifest generator can stream them via
            // the /drm/playlist + /drm/segment routes. See audit FIX #2.
            $bunnyOn = $bunny->enabled();

            foreach ($outputs as $renditionKey => $output) {
                if (empty($output['hls_dir'])) {
                    Log::warning('UploadToBunny: missing hls_dir for rendition', [
                        'movie_id' => $movie->id,
                        'rendition' => $renditionKey,
                    ]);
                    continue;
                }

                $remotePrefix = sprintf('movies/%s/hls/%s', $movie->slug, $renditionKey);

                // Use the encrypted manifest (encrypted.m3u8) as the entry-
                // point per rendition when EncryptHlsSegments has run; fall
                // back to the unencrypted playlist.m3u8 for dev environments
                // that skipped encryption.
                $manifestBasename = basename($output['manifest'] ?? 'encrypted.m3u8');

                $uploaded = 0;
                if ($bunnyOn) {
                    $uploaded = $bunny->uploadDirectory(
                        $output['hls_dir'],
                        $remotePrefix,
                    );
                }

                $cdnRenditions[$renditionKey] = array_merge($output, [
                    'cdn_prefix' => $remotePrefix,
                    'cdn_files' => $uploaded,
                    'cdn_manifest' => $remotePrefix.'/'.$manifestBasename,
                ]);

                $renditionIdx++;
                $job->updateProgress(min(95, $renditionIdx * $perRenditionPct));
            }

            // Build / upload the master manifest that ties all renditions together.
            // BunnyStorageService is expected to expose a helper for this; if not,
            // we fall back to picking the highest rendition's manifest as the
            // entrypoint (single-bitrate playback) so the page never goes dark.
            if (method_exists($bunny, 'writeMasterManifest') && $bunnyOn) {
                $masterManifestRemote = $bunny->writeMasterManifest($movie, $cdnRenditions);
            } elseif (!empty($cdnRenditions)) {
                // Default to the highest rendition's manifest as a single-bitrate
                // entrypoint. PlaybackManifestGenerator overrides this anyway
                // (it builds a master at request-time from encoding_renditions).
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

            // RenditionSpec serialises as `video_bitrate` (kbps), not `bitrate`.
            // Convert to bps for HLS `BANDWIDTH=` (player expects bits-per-sec).
            // See audit FIX #2 §2.3 secondary issue.
            $renditionsForMovie = array_values(array_map(static function (array $r): array {
                $spec = $r['spec'] ?? [];
                $bitrateKbps = (int) ($spec['video_bitrate'] ?? 0);
                $audioKbps   = (int) ($spec['audio_bitrate'] ?? 0);
                $totalKbps   = $bitrateKbps + $audioKbps;
                return [
                    'name'     => $spec['name'] ?? null,
                    'height'   => $spec['height'] ?? null,
                    'width'    => $spec['width'] ?? null,
                    'bitrate'  => $totalKbps > 0 ? $totalKbps * 1000 : null,
                    'manifest' => $r['cdn_manifest'] ?? null,
                    'hls_dir'  => $r['hls_dir'] ?? null, // used by /drm/playlist when Bunny off
                ];
            }, $cdnRenditions));

            $movie->forceFill([
                'cdn_disk' => $bunnyOn ? 'bunny' : 'local',
                'hls_manifest_path' => $masterManifestRemote,
                'encoding_status' => 'ready',
                'encoding_renditions' => $renditionsForMovie,
            ])->save();

            Log::info('UploadToBunny completed', [
                'movie_id' => $movie->id,
                'manifest' => $masterManifestRemote,
                'renditions' => array_keys($cdnRenditions),
                'bunny_enabled' => $bunnyOn,
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
