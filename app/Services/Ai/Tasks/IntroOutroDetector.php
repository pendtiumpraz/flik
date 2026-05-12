<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detect intro / outro / recap markers (in seconds) for a Movie.
 *
 * Multi-strategy pipeline (each strategy fills any holes left by the previous):
 *
 *   1. Subtitle-based — parse the movie's default WebVTT and infer:
 *        - intro_end   = start time of the FIRST substantive dialog cue
 *                        (intro music typically ends just before dialog kicks in)
 *        - outro_start = start time of the LAST substantive dialog cue, on the
 *                        assumption that anything after it is end-credits crawl
 *
 *   2. Heuristic fallback — when subtitle inference is unavailable / unreliable
 *        - intro_start = 0
 *        - intro_end   = 90 seconds (typical intro length)
 *        - outro_start = duration_seconds - 180 (last 3 min usually credits)
 *
 *   3. Optional AI refinement — when an AI provider is configured we send the
 *      first/last 30 cues and ask the model to confirm or tweak the boundaries.
 *      Failures here are non-fatal: we keep the heuristic/subtitle values.
 *
 * The final values are persisted to:
 *   movies.intro_start_seconds
 *   movies.intro_end_seconds
 *   movies.outro_start_seconds
 *
 * `recap_end_seconds` is intentionally NOT touched here — it belongs to
 * {@see RecapDetector} which has its own per-episode signal.
 */
class IntroOutroDetector
{
    /**
     * Cues shorter than this many characters are treated as non-dialog
     * (e.g. "♪ ♪", "[gunshot]", "credits") when looking for the first /
     * last substantive line.
     */
    protected const MIN_DIALOG_CHARS = 8;

    /**
     * Default fallback values when neither subtitle nor duration is available.
     */
    protected const FALLBACK_INTRO_END_SEC = 90.0;

    protected const FALLBACK_OUTRO_TAIL_SEC = 180.0;

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt,
    ) {}

    /**
     * Run detection and persist the result on the Movie model.
     *
     * @return array{intro_start: float, intro_end: ?float, outro_start: ?float, recap_end: ?float}
     */
    public function detect(Movie $movie): array
    {
        $duration = $movie->duration_seconds !== null ? (float) $movie->duration_seconds : null;

        $introStart = 0.0;
        $introEnd = null;
        $outroStart = null;

        // Strategy 1 — subtitle-based inference
        $cues = $this->loadCues($movie);
        if ($cues !== null && $cues !== []) {
            [$introEnd, $outroStart] = $this->inferFromCues($cues);
        }

        // Strategy 2 — heuristic fallbacks for any holes
        if ($introEnd === null) {
            $introEnd = self::FALLBACK_INTRO_END_SEC;
        }
        if ($outroStart === null && $duration !== null && $duration > self::FALLBACK_OUTRO_TAIL_SEC) {
            $outroStart = $duration - self::FALLBACK_OUTRO_TAIL_SEC;
        }

        // Sanity clamp — never let intro spill past outro / past duration
        if ($duration !== null) {
            $introEnd = min($introEnd, $duration);
            if ($outroStart !== null) {
                $outroStart = max(0.0, min($outroStart, $duration));
            }
        }
        if ($outroStart !== null && $introEnd > $outroStart) {
            // Pathological short film — drop intro_end below outro_start.
            $introEnd = max(0.0, $outroStart - 1.0);
        }

        // Strategy 3 — optional AI refinement (non-fatal)
        if ($cues !== null && $cues !== []) {
            $refined = $this->refineWithAi($cues, $introEnd, $outroStart, $duration);
            if ($refined !== null) {
                if (isset($refined['intro_end'])) {
                    $introEnd = $refined['intro_end'];
                }
                if (isset($refined['outro_start'])) {
                    $outroStart = $refined['outro_start'];
                }
            }
        }

        $payload = [
            'intro_start_seconds' => $introStart,
            'intro_end_seconds' => $introEnd,
            'outro_start_seconds' => $outroStart,
        ];

        // Only update columns that exist (defensive — migration may not be run yet
        // in some envs, e.g. unit tests that build a partial schema).
        $movie->forceFill(array_filter(
            $payload,
            static fn ($v, $k) => $v !== null,
            ARRAY_FILTER_USE_BOTH,
        ))->save();

        Log::info('IntroOutroDetector: detection complete', [
            'movie_id' => $movie->id,
            'used_subtitle' => $cues !== null && $cues !== [],
            'intro_start' => $introStart,
            'intro_end' => $introEnd,
            'outro_start' => $outroStart,
        ]);

        return [
            'intro_start' => $introStart,
            'intro_end' => $introEnd,
            'outro_start' => $outroStart,
            'recap_end' => $movie->recap_end_seconds !== null ? (float) $movie->recap_end_seconds : null,
        ];
    }

    /**
     * Load the default subtitle's parsed cues, or null if unavailable.
     *
     * @return array<int, array{index:int|string, start:string, end:string, text:string}>|null
     */
    protected function loadCues(Movie $movie): ?array
    {
        /** @var MovieSubtitle|null $subtitle */
        $subtitle = MovieSubtitle::query()
            ->where('movie_id', $movie->id)
            ->where('status', 'ready')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($subtitle === null) {
            return null;
        }

        try {
            $raw = Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            Log::warning('IntroOutroDetector: failed to read subtitle', [
                'movie_id' => $movie->id,
                'subtitle_id' => $subtitle->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $this->vtt->parse($raw);
    }

    /**
     * Walk the cues and pick the first/last substantive dialog start time.
     *
     * @param  array<int, array{start:string, end:string, text:string}>  $cues
     * @return array{0: ?float, 1: ?float}  [introEndSeconds, outroStartSeconds]
     */
    protected function inferFromCues(array $cues): array
    {
        $first = null;
        $last = null;

        foreach ($cues as $cue) {
            if (! $this->isSubstantiveDialog($cue['text'])) {
                continue;
            }
            $startSec = $this->vtt->timestampToSeconds($cue['start']);
            if ($first === null) {
                $first = $startSec;
            }
            $last = $startSec;
        }

        return [$first, $last];
    }

    /**
     * Heuristic: a cue counts as "real dialog" when it's more than a few
     * characters of non-music, non-bracketed text.
     */
    protected function isSubstantiveDialog(string $text): bool
    {
        $clean = trim($text);
        if ($clean === '') {
            return false;
        }

        // Drop sound-effect / music markers like "♪ Theme song ♪" or "[explosion]".
        $stripped = trim(preg_replace('/[♪♫]+|\[[^\]]*\]|\([^\)]*\)/u', '', $clean) ?? '');
        if ($stripped === '') {
            return false;
        }

        // Single-word "Credits", "End", etc. shouldn't anchor outro_start.
        if (mb_strlen($stripped) < self::MIN_DIALOG_CHARS) {
            return false;
        }

        return true;
    }

    /**
     * Optional AI pass: ask the model to validate / tweak the boundaries
     * given the first and last 30 cues.
     *
     * Returns null if AI is unavailable or the response is unusable; in that
     * case the caller keeps the heuristic values.
     *
     * @param  array<int, array{start:string, end:string, text:string}>  $cues
     * @return array{intro_end?: float, outro_start?: float}|null
     */
    protected function refineWithAi(array $cues, float $introEnd, ?float $outroStart, ?float $duration): ?array
    {
        $sample = $this->sampleEdgeCues($cues, 30);
        if ($sample === '') {
            return null;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You analyse film subtitles to locate the boundary between the OPENING TITLES (intro music + studio cards) and the START OF THE STORY, and the boundary between the LAST LINE OF DIALOG and the END CREDITS. Reply with strict JSON only, no prose, no markdown fences.',
            ],
            [
                'role' => 'user',
                'content' => sprintf(
                    "Movie duration: %s seconds.\nCurrent guess: intro_end=%.2f, outro_start=%s.\n\n"
                    . "Below are the first and last cues. Identify the timestamp (in seconds, decimal) where the story dialog begins and where the closing-credits roll begins.\n\n"
                    . "Respond with strict JSON: {\"intro_end\": number, \"outro_start\": number}. "
                    . "If you cannot determine a value, omit that key. Never invent values outside [0, duration].\n\n"
                    . "Cues:\n%s",
                    $duration !== null ? number_format($duration, 2, '.', '') : 'unknown',
                    $introEnd,
                    $outroStart !== null ? number_format($outroStart, 2, '.', '') : 'unknown',
                    $sample,
                ),
            ],
        ];

        try {
            $response = $this->ai->chat($messages, [
                'max_tokens' => 200,
                'temperature' => 0.1,
            ]);
        } catch (\Throwable $e) {
            // No provider configured, network blip, etc. — drop silently and keep heuristic.
            Log::info('IntroOutroDetector: AI refinement skipped', ['error' => $e->getMessage()]);

            return null;
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = $this->parseJsonObject($content);
        if ($parsed === null) {
            return null;
        }

        $out = [];
        if (isset($parsed['intro_end']) && is_numeric($parsed['intro_end'])) {
            $val = (float) $parsed['intro_end'];
            if ($val >= 0 && ($duration === null || $val <= $duration)) {
                $out['intro_end'] = $val;
            }
        }
        if (isset($parsed['outro_start']) && is_numeric($parsed['outro_start'])) {
            $val = (float) $parsed['outro_start'];
            if ($val >= 0 && ($duration === null || $val <= $duration)) {
                $out['outro_start'] = $val;
            }
        }

        return $out !== [] ? $out : null;
    }

    /**
     * Build a compact "first 30 + last 30" cue sample for the AI prompt.
     *
     * @param  array<int, array{start:string, end:string, text:string}>  $cues
     */
    protected function sampleEdgeCues(array $cues, int $edgeCount): string
    {
        $total = count($cues);
        if ($total === 0) {
            return '';
        }

        $head = array_slice($cues, 0, $edgeCount);
        $tail = $total > $edgeCount ? array_slice($cues, -$edgeCount) : [];

        $lines = [];
        $lines[] = '--- FIRST CUES ---';
        foreach ($head as $cue) {
            $lines[] = sprintf(
                '[%s] %s',
                $cue['start'],
                str_replace("\n", ' ', trim($cue['text'])),
            );
        }
        if ($tail !== []) {
            $lines[] = '--- LAST CUES ---';
            foreach ($tail as $cue) {
                $lines[] = sprintf(
                    '[%s] %s',
                    $cue['start'],
                    str_replace("\n", ' ', trim($cue['text'])),
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Tolerant JSON object extractor — handles code fences and leading prose.
     *
     * @return array<string, mixed>|null
     */
    protected function parseJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = $m[1];
        }

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
