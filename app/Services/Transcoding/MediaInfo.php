<?php

namespace App\Services\Transcoding;

/**
 * Immutable value object describing a source media file's
 * technical characteristics, as discovered via `ffprobe`.
 *
 * Built from the JSON output of:
 *   ffprobe -v quiet -print_format json -show_format -show_streams INPUT
 */
final class MediaInfo
{
    public function __construct(
        public readonly float $duration,
        public readonly int $width,
        public readonly int $height,
        public readonly float $fps,
        public readonly ?string $video_codec,
        public readonly ?string $audio_codec,
        public readonly int $bitrate,
        public readonly float $aspect_ratio,
    ) {}

    /**
     * Parse `ffprobe` JSON output into a MediaInfo instance.
     *
     * @throws \RuntimeException When required fields are missing or malformed.
     */
    public static function fromFfprobe(string $jsonOutput): self
    {
        $data = json_decode($jsonOutput, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid ffprobe JSON output: ' . substr($jsonOutput, 0, 200));
        }

        $streams = $data['streams'] ?? [];
        $format = $data['format'] ?? [];

        $videoStream = null;
        $audioStream = null;
        foreach ($streams as $stream) {
            $type = $stream['codec_type'] ?? null;
            if ($type === 'video' && $videoStream === null) {
                $videoStream = $stream;
            } elseif ($type === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        if ($videoStream === null) {
            throw new \RuntimeException('No video stream found in ffprobe output.');
        }

        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        // Duration: prefer format.duration (whole-container), fall back to stream.
        $duration = (float) ($format['duration'] ?? $videoStream['duration'] ?? 0.0);

        // Bitrate in kbps: ffprobe reports bps, convert.
        $bitrateBps = (int) ($format['bit_rate'] ?? $videoStream['bit_rate'] ?? 0);
        $bitrateKbps = (int) round($bitrateBps / 1000);

        // FPS: r_frame_rate is "num/den" (e.g. "30000/1001").
        $fps = self::parseRate($videoStream['r_frame_rate'] ?? $videoStream['avg_frame_rate'] ?? '0/1');

        // Aspect ratio: width/height (fall back to display_aspect_ratio if present).
        $aspect = 0.0;
        if ($height > 0) {
            $aspect = $width / $height;
        }
        if ($aspect <= 0.0 && !empty($videoStream['display_aspect_ratio'])) {
            $aspect = self::parseRate($videoStream['display_aspect_ratio'], ':');
        }

        return new self(
            duration: $duration,
            width: $width,
            height: $height,
            fps: $fps,
            video_codec: $videoStream['codec_name'] ?? null,
            audio_codec: $audioStream['codec_name'] ?? null,
            bitrate: $bitrateKbps,
            aspect_ratio: $aspect,
        );
    }

    /**
     * Parse a "num/den" or "num:den" ratio string into float.
     */
    private static function parseRate(string $value, string $separator = '/'): float
    {
        if ($value === '' || $value === '0/0') {
            return 0.0;
        }

        if (!str_contains($value, $separator)) {
            return (float) $value;
        }

        [$num, $den] = array_pad(explode($separator, $value, 2), 2, '1');
        $num = (float) $num;
        $den = (float) $den;

        return $den > 0.0 ? $num / $den : 0.0;
    }
}
