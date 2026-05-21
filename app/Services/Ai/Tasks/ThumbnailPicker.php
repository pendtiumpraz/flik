<?php

namespace App\Services\Ai\Tasks;

use App\Exceptions\SsrfException;
use App\Models\AiProvider;
use App\Models\Movie;
use App\Services\Ai\UsageTracker;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Vision-AI thumbnail picker.
 *
 * Pipeline:
 *   1. Extract N evenly-spaced keyframes from the movie's video via FFmpeg.
 *   2. Send each frame to Gemini Flash-Lite (vision) and ask for a 1-10 score
 *      on "visual quality + emotional impact + cinematography".
 *   3. Return the local file path of the highest-scoring frame.
 *
 * Requires a Google Gemini provider configured at /admin/ai-settings.
 * Degrades gracefully (logs + returns null) when no Gemini provider is available
 * or when FFmpeg / the video file is missing.
 */
class ThumbnailPicker
{
    /**
     * Default subdirectory under storage/app where keyframes are written.
     */
    protected const TEMP_SUBDIR = 'thumbnail-tmp';

    public function __construct(
        protected ?UsageTracker $tracker = null,
    ) {
        // Container auto-wires UsageTracker; the nullable default keeps the
        // service constructable in tests / artisan tinker without container.
        $this->tracker = $tracker ?? app(UsageTracker::class);
    }

    /**
     * Extract N evenly-spaced keyframes from the movie's video.
     *
     * @return string[] Absolute local paths to the extracted JPEG frames.
     */
    public function extractKeyframes(Movie $movie, int $count = 10): array
    {
        if ($count < 1) {
            return [];
        }

        $videoPath = $this->resolveVideoPath($movie);
        if ($videoPath === null) {
            return [];
        }

        $duration = $this->probeDuration($videoPath);
        if ($duration === null || $duration <= 0) {
            Log::warning('ThumbnailPicker: could not determine video duration', [
                'movie_id' => $movie->id,
                'video' => $videoPath,
            ]);
            return [];
        }

        $tempDir = $this->ensureTempDir($movie);
        $pattern = $tempDir . DIRECTORY_SEPARATOR . 'thumb_%03d.jpg';

        // fps = count / duration → roughly evenly-spaced frames across the timeline.
        // Cast to a rational string to keep FFmpeg happy with very long durations.
        $fps = sprintf('%d/%d', max(1, $count), max(1, (int) round($duration)));

        $ffmpeg = env('FFMPEG_BINARY', 'ffmpeg');

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i', $videoPath,
            '-vf', sprintf('fps=%s,scale=320:180', $fps),
            '-frames:v', (string) $count,
            '-q:v', '3',
            $pattern,
        ]);

        $process->setTimeout(600);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('ThumbnailPicker: FFmpeg process threw', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$process->isSuccessful()) {
            Log::error('ThumbnailPicker: FFmpeg keyframe extraction failed', [
                'movie_id' => $movie->id,
                'stderr' => $process->getErrorOutput(),
            ]);
            return [];
        }

        $frames = glob($tempDir . DIRECTORY_SEPARATOR . 'thumb_*.jpg') ?: [];
        sort($frames);

        return $frames;
    }

    /**
     * Extract keyframes, score each via Gemini vision, return the best.
     *
     * @return string|null Absolute path to the best frame, or null on graceful failure.
     */
    public function pickBest(Movie $movie, int $count = 10): ?string
    {
        $provider = $this->pickGeminiProvider();
        if ($provider === null) {
            Log::info('ThumbnailPicker: no Gemini provider configured, skipping', [
                'movie_id' => $movie->id,
            ]);
            return null;
        }

        $frames = $this->extractKeyframes($movie, $count);
        if (empty($frames)) {
            Log::info('ThumbnailPicker: no keyframes extracted, skipping', [
                'movie_id' => $movie->id,
            ]);
            return null;
        }

        $bestPath = null;
        $bestScore = -INF;

        foreach ($frames as $framePath) {
            $score = $this->scoreFrame($provider, $framePath, $movie);

            if ($score === null) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $framePath;
            }
        }

        if ($bestPath === null) {
            Log::warning('ThumbnailPicker: all frames failed to score, skipping', [
                'movie_id' => $movie->id,
                'frames' => count($frames),
            ]);
            return null;
        }

        Log::info('ThumbnailPicker: best frame selected', [
            'movie_id' => $movie->id,
            'best_path' => $bestPath,
            'best_score' => $bestScore,
            'frames_scored' => count($frames),
        ]);

        return $bestPath;
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Score a single frame 1-10 using Gemini Flash-Lite vision.
     * Returns null on failure (caller skips this frame).
     */
    protected function scoreFrame(AiProvider $provider, string $framePath, Movie $movie): ?float
    {
        if (!is_file($framePath)) {
            return null;
        }

        $bytes = @file_get_contents($framePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $base = rtrim($provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $endpoint = $base . '/models/' . $provider->model . ':generateContent?key=' . $provider->api_key;

        $prompt = "You are a cinematic still curator for an OTT streaming platform.\n"
            . "Score this video keyframe from 1 to 10 on the combined criteria of:\n"
            . "  • visual quality (focus, exposure, composition)\n"
            . "  • emotional impact\n"
            . "  • cinematography (framing, lighting, color)\n\n"
            . "Movie title: " . ($movie->title ?? 'unknown') . "\n\n"
            . "Respond with ONLY a single number between 1 and 10 (decimals allowed). No explanation.";

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data' => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 16,
            ],
        ];

        $startedAt = microtime(true);

        try {
            (new SsrfGuard())->assertUrlAllowed($endpoint);

            $response = Http::timeout(45)
                ->connectTimeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https'], 'strict' => true],
                ])
                ->post($endpoint, $payload);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (!$response->successful()) {
                Log::warning('ThumbnailPicker: Gemini scoring failed', [
                    'movie_id' => $movie->id,
                    'frame' => basename($framePath),
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);

                // FIX #7 — log the failed call so /admin/ai-usage sees
                // ThumbnailPicker spend (even on errors). Best-effort.
                $this->trackUsage(
                    provider: $provider,
                    movie: $movie,
                    inTokens: 0,
                    outTokens: 0,
                    latencyMs: $latencyMs,
                    success: false,
                    error: 'HTTP ' . $response->status() . ' ' . mb_substr($response->body(), 0, 200),
                );

                return null;
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Token usage — Gemini returns these in `usageMetadata`. Fall back
            // to a deterministic estimate (image input ≈ 258 tokens for the
            // standard Gemini Flash vision tier; output is the 1-16 token
            // reply). This keeps cost accounting honest even when the upstream
            // shape changes.
            $inTokens  = (int) ($data['usageMetadata']['promptTokenCount'] ?? 258);
            $outTokens = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? max(1, (int) round(mb_strlen($text) / 4)));

            $this->trackUsage(
                provider: $provider,
                movie: $movie,
                inTokens: $inTokens,
                outTokens: $outTokens,
                latencyMs: $latencyMs,
                success: true,
            );

            return $this->parseScore($text);
        } catch (SsrfException $e) {
            Log::warning('ThumbnailPicker: SSRF guard blocked Gemini endpoint', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            $this->trackUsage(
                provider: $provider,
                movie: $movie,
                inTokens: 0,
                outTokens: 0,
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
                success: false,
                error: 'SSRF blocked: ' . $e->getMessage(),
            );
            return null;
        } catch (\Throwable $e) {
            Log::warning('ThumbnailPicker: Gemini exception while scoring frame', [
                'movie_id' => $movie->id,
                'frame' => basename($framePath),
                'error' => $e->getMessage(),
            ]);
            $this->trackUsage(
                provider: $provider,
                movie: $movie,
                inTokens: 0,
                outTokens: 0,
                latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
                success: false,
                error: $e->getMessage(),
            );
            return null;
        }
    }

    /**
     * Best-effort UsageTracker write. Never throws — a logging failure must
     * not stop thumbnail picking. Wraps the underlying track() call in its
     * own try/catch on top of UsageTracker's internal guard.
     */
    protected function trackUsage(
        AiProvider $provider,
        Movie $movie,
        int $inTokens,
        int $outTokens,
        int $latencyMs,
        bool $success,
        ?string $error = null,
    ): void {
        try {
            $this->tracker?->track(
                provider: $provider,
                taskType: 'vision.thumbnail_pick',
                inTokens: $inTokens,
                outTokens: $outTokens,
                latencyMs: $latencyMs,
                success: $success,
                error: $error,
                subject: $movie,
            );
        } catch (\Throwable $e) {
            Log::warning('ThumbnailPicker: usage tracker write failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract a numeric score from the model's reply. Returns null on parse failure.
     */
    protected function parseScore(string $text): ?float
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $text, $m)) {
            $score = (float) $m[0];
            // Clamp into the documented 1-10 range so misbehaving responses
            // can't poison the comparison.
            return max(0.0, min(10.0, $score));
        }

        return null;
    }

    /**
     * Find an active Gemini provider. Prefers Flash-Lite, falls back to any
     * active Google provider (sorted by priority).
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
     * Returns null (with a log entry) when the file cannot be located.
     */
    protected function resolveVideoPath(Movie $movie): ?string
    {
        if (empty($movie->video_path)) {
            Log::info('ThumbnailPicker: movie has no video_path', [
                'movie_id' => $movie->id,
            ]);
            return null;
        }

        $disk = $movie->video_disk ?: 'public';

        if ($disk === 'public') {
            $local = storage_path('app/public/' . $movie->video_path);
            if (is_file($local)) {
                return $local;
            }
            Log::warning('ThumbnailPicker: local video file missing', [
                'movie_id' => $movie->id,
                'expected' => $local,
            ]);
            return null;
        }

        // Remote disks (s3, bunny, azure, alibaba): download to a temp file.
        try {
            $tempDir = $this->ensureTempDir($movie);
            $local = $tempDir . DIRECTORY_SEPARATOR . 'source-' . $movie->slug . '.mp4';

            if (is_file($local) && filesize($local) > 0) {
                return $local;
            }

            $stream = Storage::disk($disk)->readStream($movie->video_path);
            if (!$stream) {
                Log::warning('ThumbnailPicker: could not open remote video stream', [
                    'movie_id' => $movie->id,
                    'disk' => $disk,
                ]);
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
            Log::warning('ThumbnailPicker: failed to fetch remote video', [
                'movie_id' => $movie->id,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Probe the duration of the video in seconds via ffprobe.
     * Returns null on failure.
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
            Log::warning('ThumbnailPicker: ffprobe threw', [
                'video' => $videoPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $out = trim($process->getOutput());
        if ($out === '' || !is_numeric($out)) {
            return null;
        }

        return (float) $out;
    }

    /**
     * Ensure (and return) the per-movie temp directory for keyframes.
     */
    protected function ensureTempDir(Movie $movie): string
    {
        $dir = storage_path('app/' . self::TEMP_SUBDIR . '/' . ($movie->slug ?: 'movie-' . $movie->id));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
