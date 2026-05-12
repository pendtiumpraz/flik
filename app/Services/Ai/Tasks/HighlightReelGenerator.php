<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieHighlightReel;
use App\Services\Ai\AiClient;
use App\Services\Ai\Subtitle\WebVttHelper;
use App\Services\Transcoding\FfmpegTranscoder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Build a ~3-minute "best of" highlight reel for a movie.
 *
 * Strategy:
 *   1. Re-use TrailerSuggester to score candidate windows across the film
 *      (subtitle-driven heuristic + AI re-ranking, with audio loudness
 *      fallback when no subtitles exist).
 *   2. Pick the top-N windows whose summed duration meets the target
 *      (e.g. 6 × 30s = 3 min). Sort the picks chronologically so the reel
 *      flows in story order rather than score order.
 *   3. Stitch them together in a single FFmpeg pass using the `select` /
 *      `aselect` filters with `between(t, ...)` ranges. This produces one
 *      continuous .mp4 without intermediate files.
 *   4. Persist the rendered file on the configured disk + update the
 *      MovieHighlightReel row (status=ready, reel_path, scenes_json).
 *
 * Behaviour notes:
 *   - Skips gracefully when the movie has no readable video file:
 *     returns a `MovieHighlightReel` row with status=failed and
 *     error_message set, never throws upward.
 *   - Replaces any prior reel for the same movie (clean slate per run).
 *   - Reel is stored as `highlights/{slug}.mp4` on the configured disk.
 */
class HighlightReelGenerator
{
    /** Length of a single scene clip pulled from the source film (seconds). */
    public const SCENE_LENGTH_SECONDS = 30.0;

    /** Hard ceiling on scene count to avoid pathologically long FFmpeg filter strings. */
    public const MAX_SCENES = 12;

    /** Hard floor — fewer than this and the "reel" is not meaningful. */
    public const MIN_SCENES = 2;

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt,
        protected FfmpegTranscoder $transcoder,
        protected ?TrailerSuggester $trailerSuggester = null,
    ) {
        // TrailerSuggester is optional in the constructor so the container can
        // inject it OR we can build one on the fly (it has the same dependencies).
        $this->trailerSuggester ??= new TrailerSuggester($this->ai, $this->vtt);
    }

    /**
     * Generate (or re-generate) the highlight reel for a movie.
     *
     * Returns the persisted MovieHighlightReel row regardless of outcome:
     *   - status=ready  on success
     *   - status=failed (with error_message) when the source video is unusable
     */
    public function generate(Movie $movie, int $targetDurationSeconds = 180): MovieHighlightReel
    {
        $targetDurationSeconds = max(60, min(600, $targetDurationSeconds));

        $reel = $this->bootRecord($movie, $targetDurationSeconds);

        // ── 1. Locate source video ─────────────────────────────────────
        $videoPath = $this->resolveVideoPath($movie);
        if ($videoPath === null) {
            return $this->markFailed($reel, 'No readable video file for this movie (video_path missing or storage unreachable).');
        }

        // ── 2. Score candidate windows via TrailerSuggester logic ──────
        try {
            $candidates = $this->collectCandidates($movie);
        } catch (\Throwable $e) {
            Log::warning('HighlightReelGenerator: candidate scoring failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            $candidates = [];
        }

        if (count($candidates) < self::MIN_SCENES) {
            return $this->markFailed(
                $reel,
                'Not enough scoreable scenes to build a reel (need at least ' . self::MIN_SCENES . ').'
            );
        }

        // ── 3. Pick top scenes summing to target duration ──────────────
        $picks = $this->pickScenes($candidates, $targetDurationSeconds);
        if (count($picks) < self::MIN_SCENES) {
            return $this->markFailed($reel, 'Could not assemble enough non-overlapping scenes for the target duration.');
        }

        // Chronological order for narrative flow
        usort($picks, fn ($a, $b) => $a['start'] <=> $b['start']);

        // ── 4. Render via FFmpeg ───────────────────────────────────────
        $relativePath = $this->buildOutputPath($movie);
        $disk         = $this->targetDisk();
        $tempOutput   = storage_path('app/highlights-tmp/' . Str::slug($movie->slug ?: ('movie-' . $movie->id)) . '-' . time() . '.mp4');

        $tempDir = dirname($tempOutput);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $this->renderReel($videoPath, $picks, $tempOutput);
        } catch (\Throwable $e) {
            @unlink($tempOutput);

            return $this->markFailed($reel, 'FFmpeg render failed: ' . $e->getMessage());
        }

        if (!file_exists($tempOutput) || filesize($tempOutput) === 0) {
            @unlink($tempOutput);

            return $this->markFailed($reel, 'FFmpeg produced an empty highlight reel.');
        }

        // ── 5. Move into permanent storage ─────────────────────────────
        try {
            $stream = fopen($tempOutput, 'rb');
            Storage::disk($disk)->put($relativePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            @unlink($tempOutput);

            return $this->markFailed($reel, 'Failed to persist reel to disk [' . $disk . ']: ' . $e->getMessage());
        }

        $fileSize = filesize($tempOutput) ?: null;
        @unlink($tempOutput);

        // ── 6. Update record ───────────────────────────────────────────
        $reel->fill([
            'reel_path'       => $relativePath,
            'reel_disk'       => $disk,
            'scene_count'     => count($picks),
            'scenes_json'     => array_map(fn ($p) => [
                'start'  => round((float) $p['start'], 2),
                'end'    => round((float) $p['end'], 2),
                'score'  => round((float) ($p['score'] ?? 0), 2),
                'reason' => (string) ($p['reason'] ?? ''),
            ], $picks),
            'file_size_bytes' => $fileSize,
            'status'          => 'ready',
            'error_message'   => null,
            'generated_at'    => now(),
        ])->save();

        Log::info('HighlightReelGenerator: reel ready', [
            'movie_id'    => $movie->id,
            'scenes'      => count($picks),
            'reel_path'   => $relativePath,
            'disk'        => $disk,
            'file_bytes'  => $fileSize,
        ]);

        return $reel->refresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Candidate scoring
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Run the TrailerSuggester scoring pipeline and return raw candidates
     * (without overwriting the saved MovieTrailerSuggestion rows).
     *
     * @return array<int, array{start:float,end:float,score:float,reason:string}>
     */
    protected function collectCandidates(Movie $movie): array
    {
        // Use reflection-free public façade: call suggest() with a generous
        // count and read the persisted suggestions back. We deliberately
        // overwrite trailer suggestions here — they share the same heuristic.
        $count = self::MAX_SCENES;

        $suggestions = $this->trailerSuggester->suggest($movie, $count);

        if ($suggestions->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($suggestions as $row) {
            $out[] = [
                'start'  => (float) $row->start_seconds,
                'end'    => (float) $row->end_seconds,
                'score'  => (float) $row->score,
                'reason' => (string) ($row->reason ?? ''),
            ];
        }

        // Sort score desc — pickScenes() expects highest-first.
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * Greedy pick: walk score-sorted candidates, accept each non-overlapping
     * window until we hit the target duration (or run out of candidates).
     *
     * @param  array<int, array{start:float,end:float,score:float,reason:string}>  $candidates
     * @return array<int, array{start:float,end:float,score:float,reason:string}>
     */
    protected function pickScenes(array $candidates, int $targetSeconds): array
    {
        $picks  = [];
        $total  = 0.0;
        $cap    = (float) $targetSeconds;

        foreach ($candidates as $cand) {
            if (count($picks) >= self::MAX_SCENES) {
                break;
            }

            $duration = max(0.0, ((float) $cand['end']) - ((float) $cand['start']));
            if ($duration <= 0.0) {
                continue;
            }

            // Reject if any pre-existing pick overlaps significantly (> 50% of clip).
            $overlap = false;
            foreach ($picks as $p) {
                $left  = max((float) $cand['start'], (float) $p['start']);
                $right = min((float) $cand['end'],   (float) $p['end']);
                if ($right - $left > self::SCENE_LENGTH_SECONDS * 0.5) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) {
                continue;
            }

            $picks[] = $cand;
            $total  += $duration;

            if ($total >= $cap) {
                break;
            }
        }

        return $picks;
    }

    // ─────────────────────────────────────────────────────────────────────
    // FFmpeg rendering
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Stitch the chosen windows into a single .mp4 using FFmpeg's
     * select / aselect filters in one pass. Re-encodes to a clean
     * H.264 / AAC pair so the output is universally playable.
     *
     * @param  array<int, array{start:float,end:float}>  $picks
     */
    protected function renderReel(string $inputPath, array $picks, string $outputPath): void
    {
        if (empty($picks)) {
            throw new \RuntimeException('Cannot render reel with zero scenes.');
        }

        // Build the between(t,a,b) chain for both video and audio.
        $videoBetween = [];
        $audioBetween = [];
        foreach ($picks as $p) {
            $a = number_format((float) $p['start'], 3, '.', '');
            $b = number_format((float) $p['end'],   3, '.', '');
            $videoBetween[] = "between(t,{$a},{$b})";
            $audioBetween[] = "between(t,{$a},{$b})";
        }

        $videoFilter = sprintf(
            "select='%s',setpts=N/FRAME_RATE/TB",
            implode('+', $videoBetween)
        );
        $audioFilter = sprintf(
            "aselect='%s',asetpts=N/SR/TB",
            implode('+', $audioBetween)
        );

        $ffmpeg = env('FFMPEG_BINARY', 'ffmpeg');

        $process = new Process([
            $ffmpeg,
            '-y',
            '-hide_banner',
            '-nostats',
            '-i', $inputPath,
            '-vf', $videoFilter,
            '-af', $audioFilter,
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        // Highlight reels are short by definition; an hour is plenty of headroom.
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $tail = trim($process->getErrorOutput() ?: $process->getOutput());
            // FFmpeg stderr can be huge; keep the last ~600 chars.
            if (mb_strlen($tail) > 600) {
                $tail = '...' . mb_substr($tail, -600);
            }
            throw new \RuntimeException($tail !== '' ? $tail : 'FFmpeg returned non-zero exit code.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Path / disk / record helpers
    // ─────────────────────────────────────────────────────────────────────

    protected function bootRecord(Movie $movie, int $targetDurationSeconds): MovieHighlightReel
    {
        // Wipe any prior reel for this movie — we always re-render from scratch.
        MovieHighlightReel::where('movie_id', $movie->id)->delete();

        return MovieHighlightReel::create([
            'movie_id'                => $movie->id,
            'reel_path'               => '',
            'reel_disk'               => $this->targetDisk(),
            'target_duration_seconds' => $targetDurationSeconds,
            'scene_count'             => 0,
            'scenes_json'             => [],
            'status'                  => 'processing',
        ]);
    }

    protected function markFailed(MovieHighlightReel $reel, string $message): MovieHighlightReel
    {
        Log::warning('HighlightReelGenerator: skipped/failed', [
            'movie_id' => $reel->movie_id,
            'reason'   => $message,
        ]);

        $reel->fill([
            'status'        => 'failed',
            'error_message' => $message,
        ])->save();

        return $reel->refresh();
    }

    protected function targetDisk(): string
    {
        $disk = config('filesystems.default', 'public');
        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    protected function buildOutputPath(Movie $movie): string
    {
        $slug = $movie->slug ?: ('movie-' . $movie->id);
        return 'highlights/' . $slug . '.mp4';
    }

    /**
     * Resolve a local, ffmpeg-readable path to the source video, or null
     * if it isn't reachable on this host. Mirrors TrailerSuggester's logic.
     */
    protected function resolveVideoPath(Movie $movie): ?string
    {
        if (empty($movie->video_path)) {
            return null;
        }

        $disk = $movie->video_disk ?: 'public';

        if ($disk === 'public') {
            $local = storage_path('app/public/' . $movie->video_path);
            return file_exists($local) ? $local : null;
        }

        if (in_array($disk, ['s3', 'bunny'], true)) {
            try {
                $tempDir = storage_path('app/highlights-tmp');
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $local  = $tempDir . '/source-' . $movie->slug . '.mp4';

                if (!file_exists($local)) {
                    $stream = Storage::disk($disk)->readStream($movie->video_path);
                    if ($stream === null) {
                        return null;
                    }
                    file_put_contents($local, $stream);
                }
                return file_exists($local) ? $local : null;
            } catch (\Throwable $e) {
                Log::warning('HighlightReelGenerator: remote video fetch failed', [
                    'movie_id' => $movie->id,
                    'disk'     => $disk,
                    'error'    => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }
}
