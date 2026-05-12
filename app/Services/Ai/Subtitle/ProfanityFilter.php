<?php

namespace App\Services\Ai\Subtitle;

use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Storage;

/**
 * Kid-safe profanity filter (F6).
 *
 * Walks every cue in a source subtitle and asks an AI to soften any strong
 * language to a mild kid-safe equivalent in the SAME language. Cues that are
 * already clean are passed through unchanged.
 *
 * The result is saved as a sibling MovieSubtitle row with variant='kid-safe'
 * so admins can offer a "kid-safe audio track"-equivalent for subtitles
 * (typically used by parental-control profiles).
 */
class ProfanityFilter
{
    /** Cues per AI call. */
    protected const BATCH_SIZE = 50;

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt
    ) {}

    /**
     * Produce a kid-safe variant of a source subtitle.
     */
    public function filterToKidSafe(MovieSubtitle $source): MovieSubtitle
    {
        $movie = $source->movie;
        if (!$movie) {
            throw new \RuntimeException("Source subtitle has no movie attached.");
        }

        $sourceVtt = Storage::disk($source->disk)->get($source->webvtt_path);
        if (!$sourceVtt) {
            throw new \RuntimeException("Source subtitle file not found: {$source->webvtt_path}");
        }

        $cues = $this->vtt->parse($sourceVtt);
        if (empty($cues)) {
            throw new \RuntimeException("Source VTT has no cues.");
        }

        $langName = LanguageCatalog::name($source->language_code);

        $cleanedTexts = $this->filterCues(
            collect($cues)->pluck('text')->toArray(),
            $langName
        );

        $cleanedCues = $this->vtt->replaceTexts($cues, $cleanedTexts);
        $cleanedVtt = $this->vtt->build($cleanedCues);

        // Save .vtt file
        $disk = config('filesystems.default', 'public');
        $variant = 'kid-safe';
        $filename = sprintf(
            'subtitles/%s/%s-%s.vtt',
            $movie->slug,
            $source->language_code,
            $variant
        );
        Storage::disk($disk)->put($filename, $cleanedVtt);

        // Persist DB record (idempotent on movie+lang+variant)
        return MovieSubtitle::updateOrCreate(
            [
                'movie_id'      => $movie->id,
                'language_code' => $source->language_code,
                'variant'       => $variant,
            ],
            [
                'label'             => $langName . ' (Kid-safe)',
                'webvtt_path'       => $filename,
                'disk'              => $disk,
                'is_auto_generated' => false,
                'is_translated'     => false,
                'source_language'   => $source->language_code,
                'generator_model'   => 'deepseek-v4-flash',
                'status'            => 'ready',
                'cue_count'         => count($cues),
                'duration_seconds'  => $source->duration_seconds,
                'is_default'        => false,
                'is_active'         => true,
            ]
        );
    }

    /**
     * Send cues through the AI in batches to soften profanity.
     *
     * @param  array<int,string>  $texts     Original cue texts (input order)
     * @param  string             $langName  Human language name (e.g. "Bahasa Indonesia")
     * @return array<int,string>             Cleaned texts (matches input order)
     */
    protected function filterCues(array $texts, string $langName): array
    {
        $batches = array_chunk($texts, self::BATCH_SIZE);
        $allCleaned = [];

        foreach ($batches as $batch) {
            $numbered = [];
            foreach ($batch as $i => $text) {
                $numbered[] = sprintf("[%d] %s", $i + 1, str_replace("\n", ' / ', $text));
            }
            $batchText = implode("\n", $numbered);

            $messages = [
                [
                    'role' => 'system',
                    'content' => "You are a content moderator producing kid-safe subtitles in {$langName}. " .
                        "Replace profanity, slurs, and strong language with mild kid-safe equivalents in the SAME language ({$langName}). " .
                        "Preserve the [N] prefix exactly. Preserve meaning and emotional tone where possible. " .
                        "If a cue is already clean, output it unchanged (still with its [N] prefix). " .
                        "Output ONLY the (modified or unchanged) lines with their prefix — no explanation, no quotes, no markdown.",
                ],
                [
                    'role' => 'user',
                    'content' => "Apply kid-safe filtering to these {$langName} subtitle lines (keep [number] prefix, keep clean lines as-is):\n\n{$batchText}",
                ],
            ];

            $result = $this->ai->chat($messages, [
                'max_tokens'  => 2000,
                'temperature' => 0.2,
            ]);

            // Fall back to original text on any per-line miss so we never
            // silently drop a cue.
            $parsed = $this->parseNumberedResponse($result['content'], $batch);
            $allCleaned = array_merge($allCleaned, $parsed);
        }

        return $allCleaned;
    }

    /**
     * Parse [N]-prefixed AI output. Missing entries fall back to the original
     * input cue so the kid-safe track is never shorter than the source.
     *
     * @param  array<int,string>  $original
     * @return array<int,string>
     */
    protected function parseNumberedResponse(string $response, array $original): array
    {
        $count = count($original);
        $result = $original; // start with originals, overwrite as we parse
        $lines = preg_split('/\n+/', $response);

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\[(\d+)\]\s*(.+)$/u', $line, $m)) {
                $idx = (int) $m[1] - 1;
                if ($idx >= 0 && $idx < $count) {
                    $result[$idx] = str_replace(' / ', "\n", $m[2]);
                }
            }
        }

        return $result;
    }
}
