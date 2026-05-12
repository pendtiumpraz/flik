<?php

namespace App\Services\Transcoding;

use Symfony\Component\Process\Process;

/**
 * Thin OO wrapper around the `ffmpeg` / `ffprobe` binaries.
 *
 * Mirrors the Process pattern used by App\Services\Ai\Subtitle\SubtitleGenerator:
 *   - Binary paths come from FFMPEG_BINARY / FFPROBE_BINARY env vars.
 *   - All shell-outs use Symfony Process with explicit arg arrays (no shell escaping bugs).
 *   - Failures throw RuntimeException with the FFmpeg stderr tail.
 */
class FfmpegTranscoder
{
    protected string $ffmpegBinary;
    protected string $ffprobeBinary;

    /**
     * Process timeout for full transcodes — 2 hours covers feature-length films
     * on modest hardware.
     */
    public const TRANSCODE_TIMEOUT = 7200;

    /**
     * Process timeout for probes / keyframe extracts — short by design.
     */
    public const PROBE_TIMEOUT = 60;

    public function __construct(?string $ffmpegBinary = null, ?string $ffprobeBinary = null)
    {
        $this->ffmpegBinary = $ffmpegBinary ?? env('FFMPEG_BINARY', 'ffmpeg');
        $this->ffprobeBinary = $ffprobeBinary ?? env('FFPROBE_BINARY', 'ffprobe');
    }

    /**
     * Probe an input file and return a structured MediaInfo VO.
     *
     * Runs: ffprobe -v quiet -print_format json -show_format -show_streams INPUT
     *
     * @throws \RuntimeException When the input is missing, the binary fails,
     *                           or the JSON cannot be parsed.
     */
    public function probe(string $inputPath): MediaInfo
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Probe failed — input file not found: {$inputPath}");
        }

        $process = new Process([
            $this->ffprobeBinary,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $inputPath,
        ]);

        $process->setTimeout(self::PROBE_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'ffprobe failed: ' . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return MediaInfo::fromFfprobe($process->getOutput());
    }

    /**
     * Transcode an input file into a single rendition (single-bitrate MP4).
     *
     * Idempotent: returns true immediately if $outputPath already exists.
     *
     * Args used:
     *   -c:v libx264 -preset medium -crf 23
     *   -b:v {video_bitrate}k -maxrate {max_bitrate}k -bufsize {buffer_size}k
     *   -vf scale={width}:{height}
     *   -c:a aac -b:a {audio_bitrate}k
     *   -movflags +faststart
     *
     * @return bool True on success (or if output already exists).
     * @throws \RuntimeException When the input file is missing or FFmpeg fails.
     */
    public function transcode(string $inputPath, RenditionSpec $spec, string $outputPath): bool
    {
        if (file_exists($outputPath)) {
            return true;
        }

        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Transcode failed — input file not found: {$inputPath}");
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-i', $inputPath,
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '23',
            '-b:v', $spec->video_bitrate . 'k',
            '-maxrate', $spec->max_bitrate . 'k',
            '-bufsize', $spec->buffer_size . 'k',
            '-vf', sprintf('scale=%d:%d', $spec->width, $spec->height),
            '-c:a', 'aac',
            '-b:a', $spec->audio_bitrate . 'k',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        $process->setTimeout(self::TRANSCODE_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "FFmpeg transcode failed for rendition {$spec->name}: " .
                trim($process->getErrorOutput())
            );
        }

        return true;
    }

    /**
     * Extract a single keyframe (JPEG) at the given timestamp.
     *
     * Useful for thumbnail / sprite-sheet generation later in the pipeline.
     *
     * @throws \RuntimeException When the input file is missing or FFmpeg fails.
     */
    public function extractKeyframe(string $inputPath, float $second, string $outputPath): bool
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Keyframe failed — input file not found: {$inputPath}");
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // -ss BEFORE -i = fast keyframe seek (less accurate but sufficient for thumbnails)
        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-ss', (string) max(0.0, $second),
            '-i', $inputPath,
            '-frames:v', '1',
            '-q:v', '2',
            $outputPath,
        ]);

        $process->setTimeout(self::PROBE_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg keyframe extraction failed: ' . trim($process->getErrorOutput())
            );
        }

        return true;
    }
}
