<?php

namespace App\Services\Drm;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Forensic watermarking helper.
 *
 * Two flavours:
 *
 *  - Burn-in (visible): an FFmpeg `drawtext` overlay that prints the
 *    user identifier (e.g. user-id + ISO timestamp) in a corner of the
 *    video. Cheap, but obviously visible to the viewer. Useful as a
 *    deterrent on screener / preview content.
 *
 *  - Invisible (steganographic): a placeholder. Real forensic-grade
 *    invisible watermarking requires a commercial library such as
 *    NexGuard, ContentArmor or Verimatrix VideoMark — there is no
 *    open-source implementation that is robust against
 *    re-encoding/cropping/screen-capture attacks. We expose the API
 *    so callers can light it up later without changing call sites.
 */
class ForensicWatermarker
{
    /**
     * Path to the ffmpeg binary. Override via env `FFMPEG_PATH` if needed.
     */
    protected string $ffmpegBinary;

    public function __construct(?string $ffmpegBinary = null)
    {
        $this->ffmpegBinary = $ffmpegBinary
            ?? (string) env('FFMPEG_PATH', 'ffmpeg');
    }

    /**
     * Burn a visible text watermark into the top-left corner.
     *
     * The watermark is the user identifier (caller decides format —
     * typically `"user-{id} {iso8601}"`). Rendered at 30% opacity so
     * it's traceable but not maximally distracting.
     *
     * Returns true on a successful FFmpeg exit (code 0), false otherwise.
     * On failure the underlying error is logged via Laravel's logger;
     * we deliberately do NOT throw because watermarking is typically
     * called from a queue worker that should keep going.
     */
    public function addBurnInWatermark(string $videoPath, string $userIdentifier, string $outputPath): bool
    {
        // FFmpeg drawtext is sensitive to special chars — escape them.
        $safeText = $this->escapeDrawText($userIdentifier);

        $videoFilter = sprintf(
            "drawtext=text='%s':fontcolor=white@0.3:fontsize=18:x=10:y=10",
            $safeText
        );

        $process = new Process([
            $this->ffmpegBinary,
            '-y',                  // overwrite output
            '-i', $videoPath,
            '-vf', $videoFilter,
            '-codec:a', 'copy',    // don't re-encode audio
            $outputPath,
        ]);

        $process->setTimeout(null); // long videos OK

        try {
            $process->mustRun();

            return true;
        } catch (ProcessFailedException $e) {
            Log::warning('ForensicWatermarker: burn-in watermark failed', [
                'video'       => $videoPath,
                'output'      => $outputPath,
                'identifier'  => $userIdentifier,
                'exit_code'   => $process->getExitCode(),
                'stderr_tail' => substr((string) $process->getErrorOutput(), -2000),
            ]);

            return false;
        }
    }

    /**
     * Invisible (steganographic) forensic watermark.
     *
     * Intentionally NOT implemented — robust invisible watermarking
     * requires a licensed commercial library (NexGuard, ContentArmor,
     * Verimatrix VideoMark, etc.). Returns false so callers can branch
     * on the result without crashing. Logged once per call so misuse
     * surfaces in the dashboard.
     *
     * @param  string  $videoPath        source video
     * @param  string  $userIdentifier   payload to embed (typically user_id)
     * @param  string  $outputPath       where the watermarked file would go
     */
    public function addInvisibleWatermark(string $videoPath, string $userIdentifier, string $outputPath): bool
    {
        Log::info('ForensicWatermarker: addInvisibleWatermark called but not implemented', [
            'video'      => $videoPath,
            'output'     => $outputPath,
            'identifier' => $userIdentifier,
            'note'       => 'Not implemented — use commercial NexGuard for invisible forensic watermarking',
        ]);

        return false;
    }

    /**
     * Escape characters that FFmpeg's drawtext filter treats as special.
     * See: https://ffmpeg.org/ffmpeg-filters.html#drawtext-1
     */
    protected function escapeDrawText(string $text): string
    {
        // Order matters: backslash first.
        $replacements = [
            '\\' => '\\\\',
            ':'  => '\\:',
            "'"  => "\\'",
            '%'  => '\\%',
        ];

        return strtr($text, $replacements);
    }
}
