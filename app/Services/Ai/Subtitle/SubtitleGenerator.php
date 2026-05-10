<?php

namespace App\Services\Ai\Subtitle;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Generate base subtitle (Indonesia) from movie audio.
 *
 * Pipeline:
 * 1. Locate movie video file (local or S3/Bunny)
 * 2. Extract audio with FFmpeg → temp .mp3
 * 3. Send audio to OpenAI gpt-4o-mini-transcribe (returns segments with timestamps)
 * 4. Format as WebVTT
 * 5. Save to storage + create MovieSubtitle row (language=id, is_auto_generated=true)
 *
 * Cost: ~$0.27 per 90-min film (gpt-4o-mini-transcribe @ $0.003/min).
 *
 * Note: requires either OpenAI provider configured at /admin/ai-settings
 * with whisper-1 OR gpt-4o-mini-transcribe model.
 */
class SubtitleGenerator
{
    public function __construct(
        protected WebVttHelper $vtt
    ) {}

    /**
     * Generate Indonesia subtitle from movie's audio.
     */
    public function generate(Movie $movie, string $sourceLang = 'id'): MovieSubtitle
    {
        // ━━━ 1. Resolve movie video file ━━━
        $videoPath = $this->resolveVideoPath($movie);

        // ━━━ 2. Extract audio to temp file ━━━
        $tempDir = storage_path('app/subtitle-tmp');
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
        $audioPath = $tempDir . '/' . $movie->slug . '.mp3';

        $this->extractAudio($videoPath, $audioPath);

        try {
            // ━━━ 3. Transcribe via OpenAI ━━━
            $transcription = $this->transcribeAudio($audioPath, $sourceLang);

            // ━━━ 4. Build WebVTT from segments ━━━
            $vttContent = $this->vtt->fromWhisperSegments($transcription['segments']);

            // ━━━ 5. Save .vtt + DB record ━━━
            $disk = config('filesystems.default', 'public');
            $filename = sprintf('subtitles/%s/%s.vtt', $movie->slug, $sourceLang);
            Storage::disk($disk)->put($filename, $vttContent);

            $langMeta = LanguageCatalog::get($sourceLang) ?? ['name' => $sourceLang, 'native' => $sourceLang];

            return MovieSubtitle::updateOrCreate(
                [
                    'movie_id' => $movie->id,
                    'language_code' => $sourceLang,
                    'variant' => null,
                ],
                [
                    'label' => $langMeta['native'],
                    'webvtt_path' => $filename,
                    'disk' => $disk,
                    'is_auto_generated' => true,
                    'is_translated' => false,
                    'source_language' => null,
                    'generator_model' => 'gpt-4o-mini-transcribe',
                    'status' => 'ready',
                    'cue_count' => count($transcription['segments']),
                    'duration_seconds' => $transcription['duration'] ?? null,
                    'cost_usd' => $this->estimateCost($transcription['duration'] ?? 5400),
                    'is_default' => true,
                    'is_active' => true,
                ]
            );
        } finally {
            // Cleanup temp audio
            if (file_exists($audioPath)) unlink($audioPath);
        }
    }

    /**
     * Resolve absolute path to movie's video file.
     */
    protected function resolveVideoPath(Movie $movie): string
    {
        if (empty($movie->video_path)) {
            throw new \RuntimeException("Movie {$movie->id} ({$movie->title}) has no video_path set. Upload video first.");
        }

        // Handle local 'public' disk
        if ($movie->video_disk === 'public' || empty($movie->video_disk)) {
            $local = storage_path('app/public/' . $movie->video_path);
            if (file_exists($local)) return $local;
        }

        // For S3/Bunny: download to temp first
        if (in_array($movie->video_disk, ['s3', 'bunny'])) {
            $tempDir = storage_path('app/subtitle-tmp');
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
            $local = $tempDir . '/source-' . $movie->slug . '.mp4';

            $url = Storage::disk($movie->video_disk)->url($movie->video_path);
            file_put_contents($local, fopen($url, 'r'));
            return $local;
        }

        throw new \RuntimeException("Unsupported video_disk: {$movie->video_disk}");
    }

    /**
     * Extract audio (mono 16kHz mp3) — minimal size for transcription.
     */
    protected function extractAudio(string $videoPath, string $audioPath): void
    {
        $ffmpeg = env('FFMPEG_BINARY', 'ffmpeg');

        $process = new Process([
            $ffmpeg,
            '-y', // overwrite
            '-i', $videoPath,
            '-vn', // no video
            '-ac', '1', // mono
            '-ar', '16000', // 16kHz (Whisper-friendly)
            '-b:a', '64k',
            $audioPath,
        ]);

        $process->setTimeout(600); // 10 min for long films
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("FFmpeg audio extraction failed: " . $process->getErrorOutput());
        }
    }

    /**
     * Transcribe audio via OpenAI's transcribe API.
     * Returns ['segments' => [...], 'duration' => float, 'language' => 'id', 'text' => string]
     */
    protected function transcribeAudio(string $audioPath, string $language): array
    {
        $provider = \App\Models\AiProvider::where('provider', 'openai')
            ->where('is_active', true)
            ->whereIn('model', ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'])
            ->orderBy('priority')
            ->first();

        if (!$provider) {
            throw new \RuntimeException(
                'No active OpenAI transcription provider configured. ' .
                'Add one at /admin/ai-settings (provider=openai, model=gpt-4o-mini-transcribe).'
            );
        }

        $whisperLang = LanguageCatalog::toWhisperCode($language);
        $base = rtrim($provider->base_url ?: 'https://api.openai.com/v1', '/');

        $response = Http::timeout(600)
            ->withHeaders(['Authorization' => 'Bearer ' . $provider->api_key])
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post($base . '/audio/transcriptions', [
                'model' => $provider->model,
                'language' => $whisperLang,
                'response_format' => 'verbose_json', // returns segments with timestamps
                'timestamp_granularities[]' => 'segment',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Transcription API failed (' . $response->status() . '): ' . substr($response->body(), 0, 300)
            );
        }

        // Track usage
        $provider->update(['last_used_at' => now()]);

        return $response->json();
    }

    /**
     * Estimate cost: $0.003/min for gpt-4o-mini-transcribe.
     */
    protected function estimateCost(float $seconds): float
    {
        $minutes = $seconds / 60;
        return $minutes * 0.003;
    }
}
