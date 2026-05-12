<?php

namespace App\Services\Transcoding;

/**
 * Immutable value object describing a single ABR rendition target
 * (e.g. "720p @ 3 Mbps") used by FfmpegTranscoder & HlsSegmenter.
 *
 * All bitrates are expressed in kbps.
 */
final class RenditionSpec
{
    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly int $height,
        public readonly int $video_bitrate,
        public readonly int $audio_bitrate,
        public readonly int $max_bitrate,
        public readonly int $buffer_size,
        public readonly float $fps,
    ) {}
}
