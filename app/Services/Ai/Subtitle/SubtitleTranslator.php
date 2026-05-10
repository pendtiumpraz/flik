<?php

namespace App\Services\Ai\Subtitle;

use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Translate a movie subtitle to a target language.
 *
 * Workflow:
 * 1. Load source MovieSubtitle (e.g., Indonesia)
 * 2. Parse VTT into cues (preserve timing)
 * 3. Batch-translate cue texts via DeepSeek V4 Flash (cheap, fast)
 * 4. Re-build VTT with translated text + original timing
 * 5. (Arabic with harakat) optional second pass to add tashkeel
 * 6. Save as new MovieSubtitle row + .vtt file
 *
 * Cost estimate: ~$0.50 per film per target language (DeepSeek V4 Flash).
 */
class SubtitleTranslator
{
    /**
     * Batch size for translation API calls (cues per API call).
     * Larger = fewer calls but risk losing precision.
     */
    protected const BATCH_SIZE = 30;

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt,
        protected ArabicHarakatService $harakat
    ) {}

    /**
     * Translate a source subtitle to target language.
     * Returns the new MovieSubtitle record.
     *
     * @param  MovieSubtitle  $source       Source subtitle to translate from
     * @param  string         $targetLang   BCP-47 target language code (e.g. 'en', 'ar-x-harakat')
     */
    public function translate(MovieSubtitle $source, string $targetLang): MovieSubtitle
    {
        if (!LanguageCatalog::exists($targetLang)) {
            throw new \InvalidArgumentException("Unknown target language: {$targetLang}");
        }

        $movie = $source->movie;
        $sourceLangCode = $source->language_code;
        $sourceLangName = LanguageCatalog::name($sourceLangCode);
        $targetMeta = LanguageCatalog::get($targetLang);
        $targetLangName = $targetMeta['name'];

        // Load source VTT
        $sourceVtt = Storage::disk($source->disk)->get($source->webvtt_path);
        if (!$sourceVtt) {
            throw new \RuntimeException("Source subtitle file not found: {$source->webvtt_path}");
        }

        $cues = $this->vtt->parse($sourceVtt);
        if (empty($cues)) {
            throw new \RuntimeException("Source VTT has no cues");
        }

        // ━━━ Determine target Arabic variant (with/without harakat) ━━━
        $needsHarakat = isset($targetMeta['variant']) && $targetMeta['variant'] === 'harakat-on';
        $effectiveTarget = $needsHarakat ? 'ar' : $targetLang; // translate to plain Arabic first

        // ━━━ Batch-translate cue texts ━━━
        $translatedTexts = $this->translateCues(
            collect($cues)->pluck('text')->toArray(),
            $sourceLangName,
            LanguageCatalog::name($effectiveTarget)
        );

        // ━━━ Optional: add harakat (post-process) ━━━
        if ($needsHarakat) {
            // Apply harakat per-cue. Cheap call (~$0.01/cue with DeepSeek V4 Flash).
            $translatedTexts = array_map(
                fn ($text) => $this->harakat->addHarakat($text),
                $translatedTexts
            );
        }

        // ━━━ Re-build VTT with translated text + original timing ━━━
        $translatedCues = $this->vtt->replaceTexts($cues, $translatedTexts);
        $translatedVtt = $this->vtt->build($translatedCues);

        // ━━━ Save .vtt file ━━━
        $disk = config('filesystems.default', 'public');
        $variant = $targetMeta['variant'] ?? null;
        $filename = sprintf('subtitles/%s/%s%s.vtt',
            $movie->slug,
            $targetLang,
            $variant ? '-' . $variant : ''
        );
        Storage::disk($disk)->put($filename, $translatedVtt);

        // ━━━ DB record (idempotent — replace if same lang+variant exists) ━━━
        $subtitle = MovieSubtitle::updateOrCreate(
            [
                'movie_id' => $movie->id,
                'language_code' => $targetLang,
                'variant' => $variant,
            ],
            [
                'label' => $targetMeta['native'] ?? $targetLangName,
                'webvtt_path' => $filename,
                'disk' => $disk,
                'is_auto_generated' => false,
                'is_translated' => true,
                'source_language' => $sourceLangCode,
                'generator_model' => 'deepseek-v4-flash',
                'status' => 'ready',
                'cue_count' => count($cues),
                'duration_seconds' => $source->duration_seconds,
                'is_default' => false,
                'is_active' => true,
            ]
        );

        return $subtitle;
    }

    /**
     * Batch-translate an array of subtitle text lines.
     * Preserves order — output count = input count.
     */
    protected function translateCues(array $texts, string $sourceName, string $targetName): array
    {
        $batches = array_chunk($texts, self::BATCH_SIZE);
        $allTranslated = [];

        foreach ($batches as $batchIdx => $batch) {
            // Number each cue so AI can map back
            $numbered = [];
            foreach ($batch as $i => $text) {
                $numbered[] = sprintf("[%d] %s", $i + 1, str_replace("\n", ' / ', $text));
            }
            $batchText = implode("\n", $numbered);

            $messages = [
                [
                    'role' => 'system',
                    'content' => "You are a professional film subtitle translator from {$sourceName} to {$targetName}. Translate each numbered subtitle line PRESERVING the [number] prefix. Keep translation natural for spoken dialogue (not literal). Output ONLY the translated lines with prefixes — no explanation, no quotes, no markdown.",
                ],
                [
                    'role' => 'user',
                    'content' => "Translate these {$sourceName} subtitles to {$targetName} (keep [number] prefix):\n\n{$batchText}",
                ],
            ];

            $result = $this->ai->chat($messages, [
                'max_tokens' => 1500,
                'temperature' => 0.3,
            ]);

            $parsed = $this->parseNumberedResponse($result['content'], count($batch));
            $allTranslated = array_merge($allTranslated, $parsed);
        }

        return $allTranslated;
    }

    /**
     * Parse AI response with [N] prefix back into ordered array.
     * Robust to AI mismatching count.
     */
    protected function parseNumberedResponse(string $response, int $expectedCount): array
    {
        $result = array_fill(0, $expectedCount, '');
        $lines = preg_split('/\n+/', $response);

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\[(\d+)\]\s*(.+)$/u', $line, $m)) {
                $idx = (int) $m[1] - 1;
                if ($idx >= 0 && $idx < $expectedCount) {
                    // Restore line breaks if AI used " / " separator
                    $result[$idx] = str_replace(' / ', "\n", $m[2]);
                }
            }
        }

        return $result;
    }
}
