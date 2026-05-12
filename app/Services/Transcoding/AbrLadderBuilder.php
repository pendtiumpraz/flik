<?php

namespace App\Services\Transcoding;

/**
 * Builds the Adaptive Bitrate (ABR) rendition ladder for a given source.
 *
 * Strategy:
 *   - Always include 360p + 480p (mobile / slow connections).
 *   - Conditionally add 720p / 1080p / 4K only if the source is at least that tall
 *     (never up-scale — wastes bandwidth without quality gain).
 *   - Audio is a constant 128 kbps AAC across the ladder.
 *   - Width is derived from height using the source's aspect ratio, then snapped
 *     to an even integer (libx264 requires divisible-by-2 dimensions).
 *   - max_bitrate / bufsize follow common HLS heuristics (1.5x / 2x of target).
 */
class AbrLadderBuilder
{
    /**
     * Standard rungs. Order = ascending quality.
     * Keys: target height. Values: [name, video_bitrate (kbps), min_source_height].
     */
    private const RUNGS = [
        360  => ['name' => '360p',  'video_bitrate' => 800,   'min_source_height' => 0],
        480  => ['name' => '480p',  'video_bitrate' => 1400,  'min_source_height' => 0],
        720  => ['name' => '720p',  'video_bitrate' => 3000,  'min_source_height' => 720],
        1080 => ['name' => '1080p', 'video_bitrate' => 5000,  'min_source_height' => 1080],
        2160 => ['name' => '4K',    'video_bitrate' => 15000, 'min_source_height' => 2160],
    ];

    private const AUDIO_BITRATE_KBPS = 128;

    /**
     * Build the ladder for a given source.
     *
     * @return array<int, RenditionSpec> Ascending by quality.
     */
    public function build(MediaInfo $source): array
    {
        // Fall back to 16:9 if the probe couldn't determine a sane aspect.
        $aspect = $source->aspect_ratio > 0 ? $source->aspect_ratio : (16 / 9);

        // Carry the source's fps through the ladder (or default to 30 if unknown).
        $fps = $source->fps > 0 ? $source->fps : 30.0;

        // Use the cleaner of (width/height) vs the value we got — guards against
        // tiny rounding noise on display_aspect_ratio.
        if ($source->width > 0 && $source->height > 0) {
            $aspect = $source->width / $source->height;
        }

        $ladder = [];

        foreach (self::RUNGS as $height => $rung) {
            if ($source->height < $rung['min_source_height']) {
                continue;
            }

            $width = (int) round($height * $aspect);
            // libx264 requires even dimensions.
            if ($width % 2 !== 0) {
                $width++;
            }

            $videoBitrate = $rung['video_bitrate'];

            $ladder[] = new RenditionSpec(
                name: $rung['name'],
                width: $width,
                height: $height,
                video_bitrate: $videoBitrate,
                audio_bitrate: self::AUDIO_BITRATE_KBPS,
                // Common HLS heuristic: peak ~1.5× target, buffer ~2× target.
                max_bitrate: (int) round($videoBitrate * 1.5),
                buffer_size: $videoBitrate * 2,
                fps: $fps,
            );
        }

        return $ladder;
    }
}
