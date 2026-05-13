<?php

namespace App\Services\Ai\Tasks;

use App\Exceptions\SsrfException;
use App\Models\AiProvider;
use App\Models\Movie;
use App\Models\MovieCinematography;
use App\Services\Ai\AiClient;
use App\Services\Security\SsrfGuard;
use App\Services\Transcoding\FfmpegTranscoder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Cinematography / colour analyser.
 *
 * Pipeline:
 *   1. Resolve the movie's local video file (download to temp if remote disk).
 *   2. Sample N evenly-spaced keyframes via FfmpegTranscoder::extractKeyframe.
 *   3. For each frame, ask Gemini vision to describe dominant colours, lighting,
 *      composition and mood as strict JSON.
 *   4. Aggregate the per-frame analyses (mode-vote on style strings, weighted
 *      colour palette) and ask the default text AI to write a 150-word
 *      Indonesian narrative summary.
 *   5. Persist as a MovieCinematography row (one per movie, updateOrCreate).
 *
 * Skips gracefully (returns a placeholder record) when:
 *   - Movie has no video_path
 *   - The video file cannot be located on the configured disk
 *   - No Gemini provider is configured at /admin/ai-settings
 *   - FFmpeg / ffprobe fails
 */
class CinematographyAnalyzer
{
    /**
     * Storage subdirectory (under storage/app) where sample keyframes live.
     */
    protected const KEYFRAME_SUBDIR = 'cinematography';

    /**
     * Evenly-spaced sampling points across the film (skipping titles + credits).
     */
    protected const TIMESTAMP_RATIOS = [0.15, 0.30, 0.45, 0.60, 0.75, 0.90];

    public function __construct(
        protected AiClient $ai,
        protected FfmpegTranscoder $ffmpeg,
    ) {}

    /**
     * Analyse the given movie's cinematography.
     *
     * @param  Movie  $movie
     * @param  int    $sampleCount  Number of keyframes to extract (1-12 sensible).
     * @return MovieCinematography  Always returns a row (may be a placeholder).
     */
    public function analyze(Movie $movie, int $sampleCount = 6): MovieCinematography
    {
        $sampleCount = max(1, min(12, $sampleCount));

        // ━━━ 1. Resolve video file ━━━
        $videoPath = $this->resolveVideoPath($movie);
        if ($videoPath === null) {
            Log::info('CinematographyAnalyzer: no local video, writing placeholder', [
                'movie_id' => $movie->id,
            ]);
            return $this->writePlaceholder($movie);
        }

        // ━━━ 2. Probe duration & extract keyframes ━━━
        $duration = $this->probeDuration($videoPath);
        if ($duration === null || $duration <= 0) {
            Log::warning('CinematographyAnalyzer: could not probe duration', [
                'movie_id' => $movie->id,
                'video'    => $videoPath,
            ]);
            return $this->writePlaceholder($movie);
        }

        $framePaths = $this->extractKeyframes($movie, $videoPath, $duration, $sampleCount);
        if (empty($framePaths)) {
            Log::warning('CinematographyAnalyzer: no keyframes extracted', [
                'movie_id' => $movie->id,
            ]);
            return $this->writePlaceholder($movie);
        }

        // ━━━ 3. Pick a Gemini vision provider ━━━
        $visionProvider = $this->pickGeminiProvider();
        if ($visionProvider === null) {
            Log::info('CinematographyAnalyzer: no Gemini provider configured, writing placeholder', [
                'movie_id' => $movie->id,
            ]);
            return $this->writePlaceholder($movie, $framePaths);
        }

        // ━━━ 4. Vision pass per frame ━━━
        $frameAnalyses = [];
        foreach ($framePaths as $framePath) {
            $analysis = $this->analyzeFrame($visionProvider, $framePath, $movie);
            if ($analysis !== null) {
                $frameAnalyses[] = $analysis;
            }
        }

        if (empty($frameAnalyses)) {
            Log::warning('CinematographyAnalyzer: all frames failed vision pass', [
                'movie_id' => $movie->id,
                'frames'   => count($framePaths),
            ]);
            return $this->writePlaceholder($movie, $framePaths);
        }

        // ━━━ 5. Aggregate ━━━
        $aggregate = $this->aggregateAnalyses($frameAnalyses);

        // ━━━ 6. Narrative synthesis (text AI) ━━━
        $narrative = $this->synthesiseNarrative($movie, $aggregate);

        // ━━━ 7. Persist ━━━
        $relativeFramePaths = $this->relativisePaths($framePaths);

        return MovieCinematography::updateOrCreate(
            ['movie_id' => $movie->id],
            [
                'color_palette'          => $aggregate['color_palette'],
                'lighting_style'         => $aggregate['lighting_style'],
                'composition_style'      => $aggregate['composition_style'],
                'mood_descriptors'       => $aggregate['mood_descriptors'],
                'narrative_summary'      => $narrative,
                'sample_keyframes_paths' => $relativeFramePaths,
                'generated_at'           => now(),
            ]
        );
    }

    // ─── Internals ──────────────────────────────────────────────────

    /**
     * Extract N evenly-spaced keyframes using FfmpegTranscoder.
     *
     * @return string[]  Absolute paths to the saved JPEG files.
     */
    protected function extractKeyframes(Movie $movie, string $videoPath, float $duration, int $count): array
    {
        $ratios = $this->sampleRatios($count);
        $dir    = $this->ensureKeyframeDir($movie);
        $paths  = [];

        foreach ($ratios as $i => $ratio) {
            $second = $duration * $ratio;
            $out    = $dir . DIRECTORY_SEPARATOR . sprintf('frame_%d.jpg', $i + 1);

            try {
                $this->ffmpeg->extractKeyframe($videoPath, $second, $out);

                if (is_file($out) && filesize($out) > 0) {
                    $paths[] = $out;
                }
            } catch (\Throwable $e) {
                Log::warning('CinematographyAnalyzer: keyframe extraction failed', [
                    'movie_id' => $movie->id,
                    'second'   => $second,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $paths;
    }

    /**
     * Build the sampling-ratio list for $count frames. Reuses
     * the canonical 6-stop ladder when possible, otherwise spreads
     * frames evenly across [0.1, 0.9].
     *
     * @return float[]
     */
    protected function sampleRatios(int $count): array
    {
        if ($count === count(self::TIMESTAMP_RATIOS)) {
            return self::TIMESTAMP_RATIOS;
        }

        if ($count === 1) {
            return [0.5];
        }

        $start = 0.1;
        $end   = 0.9;
        $step  = ($end - $start) / max(1, $count - 1);

        $ratios = [];
        for ($i = 0; $i < $count; $i++) {
            $ratios[] = $start + $step * $i;
        }
        return $ratios;
    }

    /**
     * Run a Gemini vision call on a single frame, expecting strict JSON.
     *
     * @return array{
     *   palette: array<int, array{hex:string, weight:float}>,
     *   lighting:string,
     *   composition:string,
     *   moods: array<int,string>
     * }|null
     */
    protected function analyzeFrame(AiProvider $provider, string $framePath, Movie $movie): ?array
    {
        if (!is_file($framePath)) {
            return null;
        }

        $bytes = @file_get_contents($framePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $base     = rtrim($provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $endpoint = $base . '/models/' . $provider->model . ':generateContent?key=' . $provider->api_key;

        $prompt = "You are a cinematography analyst. Examine this single film keyframe and return STRICT JSON only — no markdown, no prose.\n\n"
            . "Schema:\n"
            . '{"palette":[{"hex":"#RRGGBB","weight":0.0-1.0}, ... up to 5 entries totalling ~1.0],'
            . '"lighting":"high-key|low-key|natural|chiaroscuro|silhouette|backlit|neon|other",'
            . '"composition":"rule-of-thirds|symmetric|centered|leading-lines|dutch-angle|close-up|wide|other",'
            . '"moods":["short","english","mood","tags"]}' . "\n\n"
            . 'Movie title: ' . ($movie->title ?? 'unknown') . "\n"
            . 'Return ONLY valid JSON.';

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data'      => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature'     => 0.2,
                'maxOutputTokens' => 400,
            ],
        ];

        try {
            (new SsrfGuard())->assertUrlAllowed($endpoint);

            $response = Http::timeout(60)
                ->connectTimeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https'], 'strict' => true],
                ])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('CinematographyAnalyzer: Gemini call failed', [
                    'movie_id' => $movie->id,
                    'frame'    => basename($framePath),
                    'status'   => $response->status(),
                    'body'     => substr($response->body(), 0, 200),
                ]);
                return null;
            }

            $provider->update(['last_used_at' => now()]);

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return $this->parseFrameJson((string) $text);
        } catch (SsrfException $e) {
            Log::warning('CinematographyAnalyzer: SSRF guard blocked Gemini endpoint', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('CinematographyAnalyzer: Gemini exception', [
                'movie_id' => $movie->id,
                'frame'    => basename($framePath),
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Tolerantly parse the Gemini JSON reply for one frame.
     *
     * @return array{
     *   palette: array<int, array{hex:string, weight:float}>,
     *   lighting:string,
     *   composition:string,
     *   moods: array<int,string>
     * }|null
     */
    protected function parseFrameJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Strip markdown fences if present.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Carve out the first balanced-ish JSON object.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $palette = [];
        foreach ((array) ($decoded['palette'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $hex = isset($entry['hex']) && is_string($entry['hex']) ? $this->normaliseHex($entry['hex']) : null;
            if ($hex === null) {
                continue;
            }
            $weight = isset($entry['weight']) && is_numeric($entry['weight']) ? (float) $entry['weight'] : 0.0;
            $palette[] = ['hex' => $hex, 'weight' => max(0.0, min(1.0, $weight))];
            if (count($palette) >= 5) {
                break;
            }
        }

        $moods = [];
        foreach ((array) ($decoded['moods'] ?? []) as $m) {
            if (is_string($m) && trim($m) !== '') {
                $moods[] = mb_strtolower(trim($m));
            }
        }

        return [
            'palette'     => $palette,
            'lighting'    => is_string($decoded['lighting'] ?? null)
                ? mb_strtolower(trim($decoded['lighting']))
                : '',
            'composition' => is_string($decoded['composition'] ?? null)
                ? mb_strtolower(trim($decoded['composition']))
                : '',
            'moods'       => array_values(array_unique($moods)),
        ];
    }

    /**
     * Aggregate per-frame analyses into a single film-level summary.
     *
     * @param  array<int, array{palette:array, lighting:string, composition:string, moods:array<int,string>}>  $analyses
     * @return array{
     *   color_palette: array<int,array{hex:string,weight:float}>,
     *   lighting_style:?string,
     *   composition_style:?string,
     *   mood_descriptors: array<int,string>
     * }
     */
    protected function aggregateAnalyses(array $analyses): array
    {
        // ── Colour palette: sum weights per hex, normalise to 1.0, keep top 6 ──
        $hexWeights = [];
        foreach ($analyses as $a) {
            foreach ($a['palette'] as $p) {
                $hex = $p['hex'];
                $hexWeights[$hex] = ($hexWeights[$hex] ?? 0.0) + $p['weight'];
            }
        }
        arsort($hexWeights, SORT_NUMERIC);
        $hexWeights = array_slice($hexWeights, 0, 6, true);
        $total      = array_sum($hexWeights) ?: 1.0;
        $palette    = [];
        foreach ($hexWeights as $hex => $w) {
            $palette[] = ['hex' => $hex, 'weight' => round($w / $total, 4)];
        }

        // ── Lighting & composition: mode-vote ──
        $lighting    = $this->modeOf(array_filter(array_column($analyses, 'lighting')));
        $composition = $this->modeOf(array_filter(array_column($analyses, 'composition')));

        // ── Moods: tally across frames, keep top 6 ──
        $moodCounts = [];
        foreach ($analyses as $a) {
            foreach ($a['moods'] as $m) {
                $moodCounts[$m] = ($moodCounts[$m] ?? 0) + 1;
            }
        }
        arsort($moodCounts, SORT_NUMERIC);
        $moods = array_slice(array_keys($moodCounts), 0, 6);

        return [
            'color_palette'     => $palette,
            'lighting_style'    => $lighting !== '' ? $lighting : null,
            'composition_style' => $composition !== '' ? $composition : null,
            'mood_descriptors'  => $moods,
        ];
    }

    /**
     * Return the most-frequent string in $items (empty string on empty input).
     *
     * @param  array<int,string>  $items
     */
    protected function modeOf(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $counts = array_count_values($items);
        arsort($counts, SORT_NUMERIC);
        return (string) array_key_first($counts);
    }

    /**
     * Ask the default text AI to write a 150-word Indonesian narrative summary
     * synthesising the aggregated analysis.
     */
    protected function synthesiseNarrative(Movie $movie, array $aggregate): string
    {
        $paletteText = collect($aggregate['color_palette'])
            ->map(fn ($p) => sprintf('%s (%.0f%%)', $p['hex'], $p['weight'] * 100))
            ->implode(', ');
        $moodsText = implode(', ', $aggregate['mood_descriptors']);

        $userPrompt = sprintf(
            "Film: %s (%s)\n" .
            "Palet warna dominan: %s\n" .
            "Gaya pencahayaan: %s\n" .
            "Komposisi dominan: %s\n" .
            "Mood deskriptor: %s\n\n" .
            'Tulis ringkasan sinematografi film ini dalam Bahasa Indonesia (~150 kata). ' .
            'Fokus pada bagaimana warna, pencahayaan, dan komposisi membentuk mood film. ' .
            'Bahasa naratif, tidak bullet, satu paragraf. Jangan menyebutkan angka persen atau kode hex.',
            $movie->title ?? 'Untitled',
            $movie->release_date?->format('Y') ?? '—',
            $paletteText ?: 'tidak terdeteksi',
            $aggregate['lighting_style'] ?: 'tidak terdeteksi',
            $aggregate['composition_style'] ?: 'tidak terdeteksi',
            $moodsText ?: 'tidak terdeteksi',
        );

        try {
            $response = $this->ai->chat(
                [
                    [
                        'role'    => 'system',
                        'content' => 'Anda adalah kritikus sinematografi profesional yang menulis dalam Bahasa Indonesia. '
                            . 'Tulis ringkasan analitis, sensual, dan padat — sekitar 150 kata, satu paragraf, tanpa heading, tanpa bullet, tanpa markdown.',
                    ],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 600,
                    'temperature' => 0.55,
                ],
                taskType: 'cinematography.narrative',
                subject: $movie,
            );

            $text = trim((string) ($response['content'] ?? ''));
            return $text !== '' ? $text : 'Analisis sinematografi belum tersedia.';
        } catch (\Throwable $e) {
            Log::warning('CinematographyAnalyzer: narrative synthesis failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return 'Analisis sinematografi belum tersedia.';
        }
    }

    /**
     * Pick an active Gemini provider (prefers Flash-Lite, falls back to any
     * Google provider). Mirrors ThumbnailPicker::pickGeminiProvider().
     */
    protected function pickGeminiProvider(): ?AiProvider
    {
        $flashLite = AiProvider::where('provider', 'google')
            ->where('is_active', true)
            ->where('model', 'like', '%flash-lite%')
            ->orderBy('priority')
            ->first();

        if ($flashLite) {
            return $flashLite;
        }

        return AiProvider::where('provider', 'google')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
    }

    /**
     * Resolve an absolute local path to the movie's video file.
     * Returns null when the file cannot be located.
     */
    protected function resolveVideoPath(Movie $movie): ?string
    {
        if (empty($movie->video_path)) {
            return null;
        }

        $disk = $movie->video_disk ?: 'public';

        if ($disk === 'public') {
            $local = storage_path('app/public/' . $movie->video_path);
            return is_file($local) ? $local : null;
        }

        // Remote disks — download to a temp file (same dir as keyframes for cleanup).
        try {
            $tempDir = $this->ensureKeyframeDir($movie);
            $local   = $tempDir . DIRECTORY_SEPARATOR . 'source.mp4';

            if (is_file($local) && filesize($local) > 0) {
                return $local;
            }

            $stream = Storage::disk($disk)->readStream($movie->video_path);
            if (!$stream) {
                return null;
            }

            $out = fopen($local, 'wb');
            if (!$out) {
                @fclose($stream);
                return null;
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            @fclose($stream);

            return $local;
        } catch (\Throwable $e) {
            Log::warning('CinematographyAnalyzer: failed to fetch remote video', [
                'movie_id' => $movie->id,
                'disk'     => $disk,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Probe video duration in seconds via ffprobe. Returns null on failure.
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
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $out = trim($process->getOutput());
        return is_numeric($out) ? (float) $out : null;
    }

    /**
     * Ensure (and return) the per-movie keyframe directory.
     */
    protected function ensureKeyframeDir(Movie $movie): string
    {
        $slug = $movie->slug ?: ('movie-' . $movie->id);
        $dir  = storage_path('app/' . self::KEYFRAME_SUBDIR . '/' . $slug);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Convert absolute keyframe paths into storage-relative paths
     * (e.g. "cinematography/{slug}/frame_1.jpg") for safe persistence.
     *
     * @param  string[]  $absolute
     * @return string[]
     */
    protected function relativisePaths(array $absolute): array
    {
        $base = rtrim(storage_path('app'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $out  = [];

        foreach ($absolute as $abs) {
            if (str_starts_with($abs, $base)) {
                $rel = substr($abs, strlen($base));
                $out[] = str_replace('\\', '/', $rel);
            } else {
                $out[] = str_replace('\\', '/', $abs);
            }
        }

        return $out;
    }

    /**
     * Normalise a hex colour to #RRGGBB (uppercase) or null on bad input.
     */
    protected function normaliseHex(string $hex): ?string
    {
        $h = trim($hex);
        if ($h === '') {
            return null;
        }

        if ($h[0] !== '#') {
            $h = '#' . $h;
        }

        // Expand #rgb → #rrggbb.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $h, $m)) {
            $h = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        if (!preg_match('/^#[0-9a-f]{6}$/i', $h)) {
            return null;
        }

        return '#' . strtoupper(substr($h, 1));
    }

    /**
     * Write a placeholder row so the UI can render an empty state without
     * the analyser being re-run on every page load.
     *
     * @param  string[]  $framePaths  Optional sampled frames to retain.
     */
    protected function writePlaceholder(Movie $movie, array $framePaths = []): MovieCinematography
    {
        return MovieCinematography::updateOrCreate(
            ['movie_id' => $movie->id],
            [
                'color_palette'          => [],
                'lighting_style'         => null,
                'composition_style'      => null,
                'mood_descriptors'       => [],
                'narrative_summary'      => null,
                'sample_keyframes_paths' => $this->relativisePaths($framePaths),
                'generated_at'           => now(),
            ]
        );
    }
}
