<?php

namespace App\Services\Transcoding;

use Symfony\Component\Process\Process;

/**
 * Segments a single MP4 input into HLS .ts chunks + a VOD playlist.
 *
 * Designed to be called once per rendition AFTER FfmpegTranscoder has produced
 * the per-rendition MP4. Stream copies (-c copy) so segmentation is fast and lossless.
 */
class HlsSegmenter
{
    protected string $ffmpegBinary;

    /**
     * Generous timeout — segmenting a 2-hour film via stream-copy is normally
     * a few minutes, but disk I/O can spike on slow storage.
     */
    public const SEGMENT_TIMEOUT = 7200;

    public function __construct(?string $ffmpegBinary = null)
    {
        $this->ffmpegBinary = $ffmpegBinary ?? env('FFMPEG_BINARY', 'ffmpeg');
    }

    /**
     * Run HLS segmentation on a transcoded rendition.
     *
     * Args:
     *   -f hls -hls_time {segmentDuration} -hls_playlist_type vod
     *   -hls_segment_filename "outDir/segment_%03d.ts" outDir/playlist.m3u8
     *
     * @return array<int, string> Absolute paths of the .ts segments produced
     *                            (sorted ascending, playlist itself excluded).
     * @throws \RuntimeException When the input is missing or FFmpeg fails.
     */
    public function segment(string $inputPath, int $segmentDuration, string $outputDir): array
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Segment failed — input file not found: {$inputPath}");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $segmentPattern = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'segment_%03d.ts';
        $playlistPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'playlist.m3u8';

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-i', $inputPath,
            '-c', 'copy',
            '-f', 'hls',
            '-hls_time', (string) max(1, $segmentDuration),
            '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $segmentPattern,
            $playlistPath,
        ]);

        $process->setTimeout(self::SEGMENT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg HLS segmentation failed: ' . trim($process->getErrorOutput())
            );
        }

        // Collect produced segments (sorted by name → chronological order).
        $segments = glob(rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'segment_*.ts') ?: [];
        sort($segments, SORT_STRING);

        return $segments;
    }
}
