<?php

namespace App\Services\Ai\Subtitle;

/**
 * WebVTT parser & builder. Pure PHP, no external lib.
 *
 * WebVTT format:
 *   WEBVTT
 *
 *   1
 *   00:00:01.000 --> 00:00:04.000
 *   Hello, world.
 *
 *   2
 *   00:00:05.500 --> 00:00:09.000
 *   This is a subtitle.
 */
class WebVttHelper
{
    /**
     * Parse WebVTT text into structured cues.
     *
     * @return array<int, array{index:int, start:string, end:string, text:string}>
     */
    public function parse(string $vtt): array
    {
        // Normalize line endings
        $vtt = str_replace(["\r\n", "\r"], "\n", $vtt);
        $blocks = preg_split("/\n\n+/", trim($vtt));

        $cues = [];
        $idx = 0;
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block) || stripos($block, 'WEBVTT') === 0) continue;

            $lines = explode("\n", $block);
            $timingLine = null;
            $textLines = [];
            $cueId = null;

            foreach ($lines as $line) {
                if (preg_match('/^\d{2}:\d{2}:\d{2}\.\d{3}\s*-->/', $line)) {
                    $timingLine = $line;
                } elseif ($timingLine === null && !empty(trim($line))) {
                    // Cue ID line (numeric or string before timing)
                    $cueId = trim($line);
                } elseif ($timingLine !== null) {
                    $textLines[] = $line;
                }
            }

            if ($timingLine && !empty($textLines)) {
                [$start, $end] = array_map('trim', explode('-->', $timingLine));
                $cues[] = [
                    'index' => $cueId ?? (++$idx),
                    'start' => $start,
                    'end' => $end,
                    'text' => trim(implode("\n", $textLines)),
                ];
            }
        }

        return $cues;
    }

    /**
     * Build WebVTT from structured cues.
     */
    public function build(array $cues): string
    {
        $output = "WEBVTT\n\n";
        foreach ($cues as $i => $cue) {
            $output .= ($cue['index'] ?? ($i + 1)) . "\n";
            $output .= $cue['start'] . " --> " . $cue['end'] . "\n";
            $output .= $cue['text'] . "\n\n";
        }
        return rtrim($output) . "\n";
    }

    /**
     * Build VTT from Whisper-style segments (with start/end seconds).
     *
     * @param  array  $segments  e.g. [['start'=>1.2, 'end'=>4.5, 'text'=>'Hello'], ...]
     */
    public function fromWhisperSegments(array $segments): string
    {
        $cues = [];
        foreach ($segments as $i => $seg) {
            $cues[] = [
                'index' => $i + 1,
                'start' => $this->secondsToTimestamp($seg['start']),
                'end' => $this->secondsToTimestamp($seg['end']),
                'text' => trim($seg['text']),
            ];
        }
        return $this->build($cues);
    }

    /**
     * Replace text content of cues while preserving timing.
     * Used after translation: pass translated text array (same count as cues).
     *
     * @param  array  $originalCues  Original cue structure
     * @param  array  $translatedTexts  Translated text per cue (same order)
     */
    public function replaceTexts(array $originalCues, array $translatedTexts): array
    {
        $result = [];
        foreach ($originalCues as $i => $cue) {
            $cue['text'] = $translatedTexts[$i] ?? $cue['text'];
            $result[] = $cue;
        }
        return $result;
    }

    /**
     * Convert seconds (float) → "HH:MM:SS.mmm"
     */
    public function secondsToTimestamp(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        $millis = round(($secs - floor($secs)) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, floor($secs), $millis);
    }

    /**
     * Convert "HH:MM:SS.mmm" → seconds (float)
     */
    public function timestampToSeconds(string $timestamp): float
    {
        if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{3})$/', $timestamp, $m)) {
            return 0;
        }
        return $m[1] * 3600 + $m[2] * 60 + $m[3] + ($m[4] / 1000);
    }
}
