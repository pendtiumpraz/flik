<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Models\MovieTrailerSuggestion;
use App\Services\Ai\AiClient;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Suggest the top-N most "trailer-worthy" 30-second windows in a film.
 *
 * Strategy:
 *   Approach 1 — subtitle-driven (PREFERRED when a default subtitle exists):
 *     Analyse cue text density, exclamation/emotion markers and dramatic keywords
 *     to surface dialog-heavy or emotionally-charged windows.
 *   Approach 2 — audio loudness (fallback):
 *     Walk the file in 30s windows, sample LUFS via FFmpeg loudnorm, then keep the
 *     top-N high-energy spans.
 *
 * For every shortlisted candidate the LLM is invited (best-effort) to refine the
 * 1-10 score using whatever subtitle context we have.
 *
 * Behaviour notes:
 *   - Skips gracefully when no video file is readable / FFmpeg is missing.
 *   - Never throws if the AI scoring call fails — heuristic score is kept.
 *   - Replaces previous suggestions for the same movie (clean slate per run).
 */
class TrailerSuggester
{
    /** Default trailer window length (seconds). */
    public const WINDOW_SECONDS = 30.0;

    /** Step when scanning the audio file (seconds). */
    public const AUDIO_STEP_SECONDS = 30.0;

    /** Dramatic-cue regex fragments used by the subtitle heuristic. */
    protected const EMOTION_KEYWORDS = [
        // English
        'love', 'die', 'kill', 'run', 'help', 'never', 'always', 'promise',
        'please', 'why', 'how', 'no', 'yes', 'god', 'stop', 'go', 'now',
        'fight', 'war', 'hero', 'father', 'mother', 'son', 'daughter',
        // Indonesian
        'cinta', 'mati', 'bunuh', 'lari', 'tolong', 'jangan', 'kenapa',
        'mengapa', 'janji', 'tidak', 'iya', 'tuhan', 'berhenti', 'ayo',
        'perang', 'pahlawan', 'ayah', 'ibu', 'anak',
    ];

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt,
    ) {}

    /**
     * Build (and persist) the top-N trailer suggestions for this movie.
     *
     * @return Collection<int, MovieTrailerSuggestion>
     */
    public function suggest(Movie $movie, int $count = 3): Collection
    {
        $count = max(1, $count);

        $subtitle = $this->defaultSubtitle($movie);
        $subtitleCues = $subtitle ? $this->loadCues($subtitle) : [];

        // ── Approach 1: subtitle-driven ──────────────────────────
        $candidates = [];
        if (!empty($subtitleCues)) {
            $candidates = $this->scoreFromSubtitles($subtitleCues);
        }

        // ── Approach 2: audio loudness fallback ─────────────────
        if (empty($candidates)) {
            try {
                $candidates = $this->scoreFromAudio($movie);
            } catch (\Throwable $e) {
                Log::warning('TrailerSuggester audio fallback skipped', [
                    'movie_id' => $movie->id,
                    'error'    => $e->getMessage(),
                ]);
                $candidates = [];
            }
        }

        if (empty($candidates)) {
            Log::info('TrailerSuggester produced no candidates', ['movie_id' => $movie->id]);
            return collect();
        }

        // Sort by score desc, take more than we need so the AI re-rank can shuffle.
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $shortlist = array_slice($candidates, 0, max($count * 2, $count));

        // Refine via AI when subtitle text is available for the window.
        $shortlist = array_map(
            fn (array $c) => $this->refineWithAi($c, $subtitleCues),
            $shortlist,
        );

        usort($shortlist, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($shortlist, 0, $count);

        return $this->persist($movie, $top);
    }

    // ─────────────────────────────────────────────────────────────
    // Approach 1: subtitle-driven scoring
    // ─────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{start:float,end:float,text:string}>  $cues
     * @return array<int, array{start:float,end:float,score:float,reason:string,audio_intensity:?float,text:string}>
     */
    protected function scoreFromSubtitles(array $cues): array
    {
        if (empty($cues)) {
            return [];
        }

        // Determine timeline span from the last cue.
        $maxEnd = 0.0;
        foreach ($cues as $cue) {
            if ($cue['end'] > $maxEnd) {
                $maxEnd = $cue['end'];
            }
        }
        if ($maxEnd <= 0) {
            return [];
        }

        $window = self::WINDOW_SECONDS;
        $step   = $window / 2; // overlap windows for finer granularity
        $candidates = [];

        for ($start = 0.0; $start + $window <= $maxEnd; $start += $step) {
            $end = $start + $window;
            $bucket = $this->cuesInRange($cues, $start, $end);

            if (empty($bucket)) {
                continue;
            }

            $text = trim(implode(' ', array_column($bucket, 'text')));
            if ($text === '') {
                continue;
            }

            $score = $this->heuristicScore($text, count($bucket));
            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'start'           => $start,
                'end'             => $end,
                'score'           => $score,
                'reason'          => 'Dialog-rich window (' . count($bucket) . ' cues, dramatic markers detected)',
                'audio_intensity' => null,
                'text'            => $text,
            ];
        }

        return $this->dedupeOverlapping($candidates);
    }

    /**
     * Lightweight 0-10 heuristic blending: cue density, exclamation/question
     * marks, emotion vocabulary, and text length.
     */
    protected function heuristicScore(string $text, int $cueCount): float
    {
        $lower = mb_strtolower($text);

        $exclaim   = substr_count($text, '!');
        $question  = substr_count($text, '?');
        $ellipsis  = substr_count($text, '...');
        $emotion   = 0;
        foreach (self::EMOTION_KEYWORDS as $kw) {
            // word-boundary-ish match; falls back to substring for non-Latin scripts
            $emotion += substr_count(' ' . $lower . ' ', ' ' . $kw . ' ');
        }

        $densityScore  = min(3.0, $cueCount * 0.4);            // up to 3 pts for cue density
        $emotionScore  = min(3.0, $emotion * 0.6);             // up to 3 pts for dramatic words
        $punctScore    = min(2.0, ($exclaim + $question) * 0.4); // up to 2 pts for !/?
        $lengthScore   = min(1.5, mb_strlen($text) / 200);     // up to 1.5 pts
        $ellipsisScore = min(0.5, $ellipsis * 0.25);           // tiny bump for tension

        $score = $densityScore + $emotionScore + $punctScore + $lengthScore + $ellipsisScore;

        return round(min(10.0, $score), 2);
    }

    /**
     * @param  array<int, array{start:float,end:float,text:string}>  $cues
     * @return array<int, array{start:float,end:float,text:string}>
     */
    protected function cuesInRange(array $cues, float $start, float $end): array
    {
        $out = [];
        foreach ($cues as $cue) {
            if ($cue['end'] < $start) continue;
            if ($cue['start'] > $end) break;
            $out[] = $cue;
        }
        return $out;
    }

    /**
     * Remove windows that overlap heavily, keeping the higher-scored one.
     *
     * @param  array<int, array<string,mixed>>  $candidates
     * @return array<int, array<string,mixed>>
     */
    protected function dedupeOverlapping(array $candidates): array
    {
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $kept = [];
        foreach ($candidates as $cand) {
            $overlap = false;
            foreach ($kept as $k) {
                $left  = max($cand['start'], $k['start']);
                $right = min($cand['end'], $k['end']);
                if ($right - $left > self::WINDOW_SECONDS * 0.5) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $kept[] = $cand;
            }
        }
        return $kept;
    }

    // ─────────────────────────────────────────────────────────────
    // Approach 2: audio loudness fallback
    // ─────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{start:float,end:float,score:float,reason:string,audio_intensity:?float,text:string}>
     */
    protected function scoreFromAudio(Movie $movie): array
    {
        $videoPath = $this->resolveVideoPath($movie);
        if ($videoPath === null) {
            return [];
        }

        $duration = $this->probeDuration($videoPath);
        if ($duration === null || $duration < self::WINDOW_SECONDS) {
            return [];
        }

        $window = self::WINDOW_SECONDS;
        $step   = self::AUDIO_STEP_SECONDS;
        $candidates = [];

        for ($start = 0.0; $start + $window <= $duration; $start += $step) {
            $intensity = $this->measureLoudness($videoPath, $start, $window);
            if ($intensity === null) {
                continue;
            }

            // Map intensity (0-10 scale) directly to score; clamp.
            $score = round(max(0.0, min(10.0, $intensity)), 2);

            $candidates[] = [
                'start'           => $start,
                'end'             => $start + $window,
                'score'           => $score,
                'reason'          => sprintf('High-energy audio window (intensity %.2f/10)', $intensity),
                'audio_intensity' => $score,
                'text'            => '',
            ];
        }

        return $this->dedupeOverlapping($candidates);
    }

    /**
     * Probe overall duration via ffprobe; null if unavailable.
     */
    protected function probeDuration(string $videoPath): ?float
    {
        $ffprobe = env('FFPROBE_BINARY', 'ffprobe');
        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $duration = (float) trim($process->getOutput());
        return $duration > 0 ? $duration : null;
    }

    /**
     * Run FFmpeg loudnorm on a single window. Returns a 0-10 intensity proxy.
     *
     * Higher input integrated loudness (LUFS) → louder window. Typical film
     * dialogue sits around -23 LUFS, action scenes can hit -16..-12 LUFS.
     */
    protected function measureLoudness(string $videoPath, float $start, float $window): ?float
    {
        $ffmpeg = env('FFMPEG_BINARY', 'ffmpeg');
        $process = new Process([
            $ffmpeg,
            '-hide_banner',
            '-nostats',
            '-ss', (string) $start,
            '-t', (string) $window,
            '-i', $videoPath,
            '-vn',
            '-af', 'loudnorm=print_format=summary',
            '-f', 'null',
            // Platform-agnostic null sink (FFmpeg understands "-" with -f null).
            '-',
        ]);
        $process->setTimeout(120);
        $process->run();

        // loudnorm prints summary to stderr regardless of exit code on some builds.
        $output = $process->getErrorOutput() . "\n" . $process->getOutput();
        if ($output === '') {
            return null;
        }

        if (!preg_match('/Input Integrated:\s*(-?\d+(?:\.\d+)?)\s*LUFS/i', $output, $m)) {
            return null;
        }
        $lufs = (float) $m[1];

        // Map [-40 .. -10] LUFS → [0 .. 10] intensity (clamped).
        $intensity = (($lufs + 40.0) / 30.0) * 10.0;
        return max(0.0, min(10.0, $intensity));
    }

    /**
     * Resolve a local path to the movie video, or null if unreachable.
     * Re-uses the same conventions as SubtitleGenerator (public disk / S3 / Bunny).
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
                $tempDir = storage_path('app/trailer-tmp');
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $local  = $tempDir . '/source-' . $movie->slug . '.mp4';
                $stream = Storage::disk($disk)->readStream($movie->video_path);
                if ($stream === null) {
                    return null;
                }
                file_put_contents($local, $stream);
                return file_exists($local) ? $local : null;
            } catch (\Throwable $e) {
                Log::warning('TrailerSuggester remote video fetch failed', [
                    'movie_id' => $movie->id,
                    'disk'     => $disk,
                    'error'    => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // AI re-ranking
    // ─────────────────────────────────────────────────────────────

    /**
     * Ask the LLM to refine the score for a candidate when we have subtitle text.
     * Failures are swallowed — we keep the heuristic score.
     *
     * @param  array<string,mixed>  $candidate
     * @param  array<int, array{start:float,end:float,text:string}>  $allCues
     * @return array<string,mixed>
     */
    protected function refineWithAi(array $candidate, array $allCues): array
    {
        $text = $candidate['text'] ?? '';
        if ($text === '' && !empty($allCues)) {
            $text = trim(implode(' ', array_column(
                $this->cuesInRange($allCues, $candidate['start'], $candidate['end']),
                'text'
            )));
        }

        if ($text === '') {
            return $candidate;
        }

        try {
            $response = $this->ai->chat([
                [
                    'role'    => 'system',
                    'content' => 'You rate film scenes for trailer-worthiness. ' .
                                 'Reply with ONE JSON object: {"score": <1-10 float>, "reason": "<short>"}. ' .
                                 'No prose, no markdown.',
                ],
                [
                    'role'    => 'user',
                    'content' => "Scene dialog (about 30 seconds):\n\n" .
                                 mb_substr($text, 0, 1200) .
                                 "\n\nHow trailer-worthy is this scene?",
                ],
            ], [
                'max_tokens'  => 120,
                'temperature' => 0.2,
            ]);

            $parsed = $this->parseScoreJson($response['content'] ?? '');
            if ($parsed !== null) {
                $candidate['score']  = round(max(1.0, min(10.0, $parsed['score'])), 2);
                $candidate['reason'] = $parsed['reason'] ?: $candidate['reason'];
            }
        } catch (\Throwable $e) {
            Log::info('TrailerSuggester AI refine skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        return $candidate;
    }

    /**
     * Extract {"score": x, "reason": "..."} from a possibly-noisy LLM reply.
     *
     * @return array{score:float, reason:string}|null
     */
    protected function parseScoreJson(string $raw): ?array
    {
        if ($raw === '') return null;

        // Grab the first {...} block.
        if (!preg_match('/\{.*\}/s', $raw, $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded) || !isset($decoded['score'])) {
            return null;
        }
        return [
            'score'  => (float) $decoded['score'],
            'reason' => (string) ($decoded['reason'] ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Persistence helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Load (or use, if already loaded) the default subtitle for this movie.
     */
    protected function defaultSubtitle(Movie $movie): ?MovieSubtitle
    {
        return MovieSubtitle::query()
            ->where('movie_id', $movie->id)
            ->where('is_active', true)
            ->where('status', 'ready')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int, array{start:float,end:float,text:string}>
     */
    protected function loadCues(MovieSubtitle $subtitle): array
    {
        try {
            $vtt = Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            Log::warning('TrailerSuggester could not read subtitle VTT', [
                'subtitle_id' => $subtitle->id,
                'error'       => $e->getMessage(),
            ]);
            return [];
        }

        if (!is_string($vtt) || $vtt === '') {
            return [];
        }

        $parsed = $this->vtt->parse($vtt);
        $cues = [];
        foreach ($parsed as $cue) {
            $cues[] = [
                'start' => $this->vtt->timestampToSeconds($cue['start']),
                'end'   => $this->vtt->timestampToSeconds($cue['end']),
                'text'  => $cue['text'],
            ];
        }
        return $cues;
    }

    /**
     * Persist top candidates, replacing any prior suggestions for the movie.
     *
     * @param  array<int, array<string,mixed>>  $top
     * @return Collection<int, MovieTrailerSuggestion>
     */
    protected function persist(Movie $movie, array $top): Collection
    {
        MovieTrailerSuggestion::where('movie_id', $movie->id)->delete();

        $saved = collect();
        foreach ($top as $cand) {
            $saved->push(MovieTrailerSuggestion::create([
                'movie_id'         => $movie->id,
                'start_seconds'    => (float) $cand['start'],
                'end_seconds'      => (float) $cand['end'],
                'duration_seconds' => round(((float) $cand['end']) - ((float) $cand['start']), 2),
                'score'            => $cand['score'],
                'reason'           => $cand['reason'] ?? null,
                'audio_intensity'  => $cand['audio_intensity'] ?? null,
                'is_selected'      => false,
            ]));
        }
        return $saved;
    }
}
