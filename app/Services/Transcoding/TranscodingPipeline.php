<?php

namespace App\Services\Transcoding;

use App\Models\EncodingJob;
use App\Models\Movie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orchestrates the full local-side transcoding stage for a movie:
 *
 *   probe → build ABR ladder → transcode each rendition → segment to HLS
 *
 * This service is intentionally local-only: encryption (HlsEncryptor) and
 * upload to CDN (BunnyStorageService) are downstream queue jobs in the
 * chain (TranscodeMovie → EncryptHlsSegments → UploadToBunny). Keeping
 * those phases as separate jobs lets us retry network-bound work without
 * re-running the multi-hour ffmpeg encode.
 *
 * Peer collaborators (FfmpegTranscoder, AbrLadderBuilder, HlsSegmenter) are
 * expected to be created by sibling agents and resolved via DI; we deliberately
 * type them with their fully-qualified names rather than imports so this file
 * doesn't blow up the linter while the peer classes land.
 */
class TranscodingPipeline
{
    /**
     * Local working directory for intermediate (pre-upload) artifacts.
     *
     * Each job gets a per-job subdir so concurrent jobs can't trample each
     * other's outputs. The directory is NOT cleaned up here — UploadToBunny
     * is responsible for tidying after a successful CDN push.
     */
    protected string $workRoot;

    public function __construct(
        protected \App\Services\Transcoding\FfmpegTranscoder $ffmpeg,
        protected \App\Services\Transcoding\AbrLadderBuilder $ladderBuilder,
        protected \App\Services\Transcoding\HlsSegmenter $segmenter,
    ) {
        $this->workRoot = storage_path('app/transcoding');
    }

    /**
     * Run the full pipeline for one movie/job.
     *
     * On success: job is marked completed with {rendition => {transcoded, hls_dir, manifest}} paths.
     * On failure: job is marked failed; exception is swallowed (logged), since
     * the caller is a queue worker that will check job status to decide retries.
     */
    public function run(Movie $movie, EncodingJob $job): void
    {
        $job->markStarted();

        try {
            // ━━━ 1. Resolve master file → local path ffmpeg can probe ━━━
            $masterPath = $this->resolveMasterPath($movie);

            // ━━━ 2. Probe → MediaInfo (width/height/duration/bitrate) ━━━
            $mediaInfo = $this->ffmpeg->probe($masterPath);
            $job->updateProgress(5);

            // ━━━ 3. Build ABR ladder from source resolution ━━━
            $ladder = $this->ladderBuilder->build($mediaInfo);

            if (empty($ladder)) {
                throw new \RuntimeException(
                    "Empty ABR ladder for movie {$movie->id} ({$movie->slug}). "
                    ."Source may be too low-resolution or probe failed."
                );
            }

            // Persist requested specs so the admin UI can show "what we tried"
            // even before any rendition completes. EncodingJob uses
            // $guarded = ['*'] (mass-assignment audit, 2026-05-13).
            //
            // RenditionSpec is a readonly VO; serialise to array for JSON
            // storage so the admin UI / next-stage job can read it back.
            // See docs/audit/04-drm-playback.md §2.1.
            $ladderSerialised = array_map(
                static fn (RenditionSpec $spec): array => [
                    'name' => $spec->name,
                    'width' => $spec->width,
                    'height' => $spec->height,
                    'video_bitrate' => $spec->video_bitrate,
                    'audio_bitrate' => $spec->audio_bitrate,
                    'max_bitrate' => $spec->max_bitrate,
                    'buffer_size' => $spec->buffer_size,
                    'fps' => $spec->fps,
                ],
                $ladder,
            );
            $job->forceFill(['rendition_specs' => $ladderSerialised])->save();

            $jobWorkDir = $this->ensureWorkDir($job);
            $outputs = [];

            // Reserve 5% for probe + ladder, 75% for transcode, 20% for segment.
            $totalRenditions = count($ladder);
            $perRenditionBudget = (int) floor(75 / max(1, $totalRenditions));

            // ━━━ 4. Transcode each rendition → local file ━━━
            // FfmpegTranscoder::transcode($inputPath, RenditionSpec $spec, $outputPath)
            // is the actual signature — positional args only, no onProgress hook
            // (progress UI ticks once per rendition). See audit §2.1.
            foreach ($ladder as $idx => $rendition) {
                /** @var RenditionSpec $rendition */
                $renditionKey = $rendition->name !== ''
                    ? $rendition->name
                    : sprintf('%dp', $rendition->height);

                $transcodedPath = sprintf(
                    '%s/%s.mp4',
                    $jobWorkDir,
                    $renditionKey,
                );

                $this->ffmpeg->transcode(
                    $masterPath,
                    $rendition,
                    $transcodedPath,
                );

                $outputs[$renditionKey] = [
                    'spec' => $ladderSerialised[$idx],
                    'transcoded_path' => $transcodedPath,
                ];

                // Per-rendition progress: ffmpeg has no streaming hook, so
                // we tick once per rendition completion.
                $base = 5 + (($idx + 1) * $perRenditionBudget);
                $job->updateProgress(min(80, $base));
            }

            $job->updateProgress(80);

            // ━━━ 5. Segment each transcoded rendition → HLS dir ━━━
            // HlsSegmenter::segment($inputPath, int $segmentDuration, $outputDir)
            // returns array<int,string> of .ts paths — NOT a manifest path.
            // The actual manifest is always at $outputDir/playlist.m3u8 (the
            // segmenter writes that filename internally). See audit §2.1.
            $perSegmentBudget = (int) floor(20 / max(1, $totalRenditions));
            $segmentCounter = 0;

            foreach ($outputs as $renditionKey => $output) {
                $hlsDir = sprintf('%s/hls/%s', $jobWorkDir, $renditionKey);

                $segmentPaths = $this->segmenter->segment(
                    $output['transcoded_path'],
                    6, // segment duration (seconds) — matches HlsEncryptor's hls_time.
                    $hlsDir,
                );

                $outputs[$renditionKey]['hls_dir'] = $hlsDir;
                $outputs[$renditionKey]['manifest'] = $hlsDir . DIRECTORY_SEPARATOR . 'playlist.m3u8';
                $outputs[$renditionKey]['segments'] = $segmentPaths;

                // Bump global progress after each rendition is segmented (O(n) counter,
                // not O(n²) array_search like the original — audit §4.8).
                $segmentCounter++;
                $job->updateProgress(min(99, 80 + ($perSegmentBudget * $segmentCounter)));
            }

            // ━━━ 6. Persist outputs + mark completed ━━━
            $job->markCompleted($outputs);

            Log::info('TranscodingPipeline completed', [
                'movie_id' => $movie->id,
                'job_id' => $job->id,
                'renditions' => array_keys($outputs),
            ]);
        } catch (Throwable $e) {
            Log::error('TranscodingPipeline failed', [
                'movie_id' => $movie->id,
                'job_id' => $job->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $job->markFailed($e->getMessage());

            // Re-throw so the dispatching queue job can also record failure
            // and decide on retry vs. final failure based on $tries.
            throw $e;
        }
    }

    /**
     * Resolve master file (local OR remote disk) to a local filesystem path
     * ffmpeg can read directly. Remote files are pulled into the work dir.
     */
    protected function resolveMasterPath(Movie $movie): string
    {
        if (empty($movie->master_file_path)) {
            throw new \RuntimeException(
                "Movie {$movie->id} ({$movie->title}) has no master_file_path set."
            );
        }

        $disk = $movie->master_file_disk ?: 'public';

        // Local disks: stream straight from storage path.
        if (in_array($disk, ['public', 'local'], true)) {
            $local = storage_path('app/'.($disk === 'public' ? 'public/' : '').$movie->master_file_path);
            if (file_exists($local)) {
                return $local;
            }
            throw new \RuntimeException("Master file not found locally: {$local}");
        }

        // Remote disks: pull a copy into the work area for ffmpeg.
        if (!Storage::disk($disk)->exists($movie->master_file_path)) {
            throw new \RuntimeException(
                "Master file missing on disk [{$disk}]: {$movie->master_file_path}"
            );
        }

        $tempDir = $this->workRoot.'/sources';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $localCopy = $tempDir.'/'.$movie->slug.'-'.basename($movie->master_file_path);

        // Stream copy to keep memory bounded for huge masters.
        $remote = Storage::disk($disk)->readStream($movie->master_file_path);
        $local = fopen($localCopy, 'wb');
        if ($remote === false || $local === false) {
            throw new \RuntimeException("Failed to open streams for master file copy.");
        }

        try {
            stream_copy_to_stream($remote, $local);
        } finally {
            if (is_resource($remote)) {
                fclose($remote);
            }
            if (is_resource($local)) {
                fclose($local);
            }
        }

        return $localCopy;
    }

    /**
     * Ensure a per-job working directory exists and return its absolute path.
     */
    protected function ensureWorkDir(EncodingJob $job): string
    {
        $dir = sprintf('%s/jobs/%d', $this->workRoot, $job->id);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
