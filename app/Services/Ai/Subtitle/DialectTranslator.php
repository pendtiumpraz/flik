<?php

namespace App\Services\Ai\Subtitle;

use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Storage;

/**
 * Translate Indonesian subtitles to local Indonesian dialects (F2 — Dialect Translation).
 *
 * Supported dialects:
 *   - jv  : Bahasa Jawa (Ngoko by default — informal everyday register)
 *   - su  : Bahasa Sunda (Loma — neutral conversational)
 *   - min : Baso Minang (Padang)
 *   - bug : Basa Ugi (Bugis)
 *   - bjn : Bahasa Banjar
 *
 * Workflow mirrors SubtitleTranslator: parse VTT → batch-translate → save new
 * MovieSubtitle row with variant='dialect-{code}'. Uses DeepSeek V4 Flash.
 *
 * Cost estimate: ~$0.50 per film per dialect.
 */
class DialectTranslator
{
    /** Cues per AI call. */
    protected const BATCH_SIZE = 30;

    /**
     * Dialect metadata: code → [BCP-47 lang, native register hint, label].
     * The BCP-47 code reuses the existing LanguageCatalog entry so the row
     * sits alongside any "vanilla" jv/su/min translation but is distinguished
     * by the `variant` column.
     */
    protected const DIALECTS = [
        'jv'  => [
            'language_code' => 'jv',
            'name'          => 'Bahasa Jawa',
            'register'      => 'Bahasa Jawa Ngoko (informal everyday register)',
            'label_suffix'  => 'Jawa (Ngoko)',
        ],
        'su'  => [
            'language_code' => 'su',
            'name'          => 'Bahasa Sunda',
            'register'      => 'Bahasa Sunda Loma (neutral conversational register)',
            'label_suffix'  => 'Sunda (Loma)',
        ],
        'min' => [
            'language_code' => 'min',
            'name'          => 'Baso Minang',
            'register'      => 'Baso Minang (Padang vernacular)',
            'label_suffix'  => 'Minang',
        ],
        'bug' => [
            'language_code' => 'bug',
            'name'          => 'Basa Ugi (Bugis)',
            'register'      => 'Basa Ugi (Bugis everyday speech, Latin script)',
            'label_suffix'  => 'Bugis',
        ],
        'bjn' => [
            'language_code' => 'bjn',
            'name'          => 'Bahasa Banjar',
            'register'      => 'Bahasa Banjar (South Kalimantan vernacular)',
            'label_suffix'  => 'Banjar',
        ],
    ];

    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt
    ) {}

    /**
     * Translate a source (Indonesian) subtitle to one of the supported dialects.
     *
     * @param  MovieSubtitle  $source   Indonesian source subtitle
     * @param  string         $dialect  One of: jv, su, min, bug, bjn
     */
    public function translateDialect(MovieSubtitle $source, string $dialect): MovieSubtitle
    {
        if (!isset(self::DIALECTS[$dialect])) {
            throw new \InvalidArgumentException(
                "Unsupported dialect: {$dialect}. Supported: " . implode(', ', array_keys(self::DIALECTS))
            );
        }

        $meta = self::DIALECTS[$dialect];
        $movie = $source->movie;

        if (!$movie) {
            throw new \RuntimeException("Source subtitle has no movie attached.");
        }

        // Load + parse source VTT
        $sourceVtt = Storage::disk($source->disk)->get($source->webvtt_path);
        if (!$sourceVtt) {
            throw new \RuntimeException("Source subtitle file not found: {$source->webvtt_path}");
        }

        $cues = $this->vtt->parse($sourceVtt);
        if (empty($cues)) {
            throw new \RuntimeException("Source VTT has no cues.");
        }

        // Batch-translate via AI
        $translatedTexts = $this->translateCuesToDialect(
            collect($cues)->pluck('text')->toArray(),
            $meta
        );

        // Re-build VTT with original timing
        $translatedCues = $this->vtt->replaceTexts($cues, $translatedTexts);
        $translatedVtt = $this->vtt->build($translatedCues);

        // Save .vtt file
        $disk = config('filesystems.default', 'public');
        $variant = 'dialect-' . $dialect;
        $filename = sprintf(
            'subtitles/%s/%s-%s.vtt',
            $movie->slug,
            $meta['language_code'],
            $variant
        );
        Storage::disk($disk)->put($filename, $translatedVtt);

        // Persist DB row (idempotent on movie+lang+variant)
        return MovieSubtitle::updateOrCreate(
            [
                'movie_id'      => $movie->id,
                'language_code' => $meta['language_code'],
                'variant'       => $variant,
            ],
            [
                'label'             => $meta['label_suffix'],
                'webvtt_path'       => $filename,
                'disk'              => $disk,
                'is_auto_generated' => false,
                'is_translated'     => true,
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
     * Batch-translate Indonesian cue texts into the target dialect.
     * Output ordering matches input ordering (length-stable).
     *
     * @param  array<int,string>             $texts
     * @param  array<string,string>          $meta   Dialect metadata
     * @return array<int,string>
     */
    protected function translateCuesToDialect(array $texts, array $meta): array
    {
        $batches = array_chunk($texts, self::BATCH_SIZE);
        $allTranslated = [];

        $dialectName = $meta['name'];
        $register = $meta['register'];

        foreach ($batches as $batch) {
            $numbered = [];
            foreach ($batch as $i => $text) {
                $numbered[] = sprintf("[%d] %s", $i + 1, str_replace("\n", ' / ', $text));
            }
            $batchText = implode("\n", $numbered);

            $messages = [
                [
                    'role' => 'system',
                    'content' => "Translate Indonesian subtitle to {$dialectName} ({$register}). " .
                        "Preserve cultural nuance, idioms native to the dialect, and natural spoken register. " .
                        "Output preserves [N] prefix format — one translated line per input line, same numbering. " .
                        "Do NOT translate proper names. Output ONLY the translated lines with prefixes — no explanation, no markdown.",
                ],
                [
                    'role' => 'user',
                    'content' => "Translate these Indonesian subtitles to {$dialectName} (keep [number] prefix):\n\n{$batchText}",
                ],
            ];

            $result = $this->ai->chat($messages, [
                'max_tokens'  => 1500,
                'temperature' => 0.3,
            ]);

            $parsed = $this->parseNumberedResponse($result['content'], count($batch));
            $allTranslated = array_merge($allTranslated, $parsed);
        }

        return $allTranslated;
    }

    /**
     * Parse [N]-prefixed AI response into ordered array, robust to count mismatch.
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
                    $result[$idx] = str_replace(' / ', "\n", $m[2]);
                }
            }
        }

        return $result;
    }

    /**
     * Public list of supported dialect codes (for UI dropdowns / validation).
     *
     * @return array<string,string>  code => display label
     */
    public static function supportedDialects(): array
    {
        $out = [];
        foreach (self::DIALECTS as $code => $meta) {
            $out[$code] = $meta['label_suffix'];
        }
        return $out;
    }
}
