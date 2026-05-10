<?php

namespace App\Services\Ai\Subtitle;

use App\Services\Ai\AiClient;

/**
 * Add/remove Arabic harakat (tashkeel diacritics) from text.
 *
 * Harakat (التشكيل) = vowel marks like fatha, damma, kasra, sukun, shadda.
 * Standard Arabic newspapers/web text usually has NO harakat (readers infer).
 * Religious texts (Quran), poetry, and learning material use FULL harakat.
 *
 * Removal: pure regex strip (Unicode range U+064B–U+0652 + U+0670 + U+0640)
 * Addition: needs AI (DeepSeek V4 Flash) — Arabic ML knowledge required
 */
class ArabicHarakatService
{
    public function __construct(
        protected AiClient $ai
    ) {}

    /**
     * Strip all harakat (tashkeel) from Arabic text.
     * Pure regex, no AI call. Reversible with addHarakat().
     */
    public function removeHarakat(string $text): string
    {
        // Unicode ranges to strip:
        // U+064B → ً (fathatan)
        // U+064C → ٌ (dammatan)
        // U+064D → ٍ (kasratan)
        // U+064E → َ (fatha)
        // U+064F → ُ (damma)
        // U+0650 → ِ (kasra)
        // U+0651 → ّ (shadda)
        // U+0652 → ْ (sukun)
        // U+0670 → ٰ (superscript alef)
        // U+0640 → ـ (tatweel — kashida, decorative elongation)
        return preg_replace(
            '/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u',
            '',
            $text
        );
    }

    /**
     * Add full harakat (tashkeel) to Arabic text via AI.
     * Useful for learners, religious content, classical Arabic display.
     *
     * @param  string  $text  Arabic text without (or partially with) harakat
     * @return string         Same text with full tashkeel applied
     */
    public function addHarakat(string $text): string
    {
        if (empty(trim($text))) return $text;

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an Arabic linguistics expert specializing in tashkeel (harakat) diacritization. Your sole task: add FULL tashkeel/harakat (fatha, damma, kasra, sukun, shadda, tanwin) to Arabic text. Output ONLY the diacritized Arabic text — no English, no explanation, no quotes, no markdown. Preserve all punctuation, line breaks, and non-Arabic content (numbers, English words) as-is.',
            ],
            [
                'role' => 'user',
                'content' => "Add full harakat to this Arabic text:\n\n{$text}",
            ],
        ];

        $result = $this->ai->chat($messages, [
            'max_tokens' => mb_strlen($text) * 3, // diacritized version is ~2-3x longer in chars
            'temperature' => 0.1, // low — we want consistent linguistic output
        ]);

        return trim($result['content']);
    }

    /**
     * Quick check: does text already have harakat?
     */
    public function hasHarakat(string $text): bool
    {
        return (bool) preg_match('/[\x{064B}-\x{0652}\x{0670}]/u', $text);
    }

    /**
     * Estimate diacritization cost for given text (using DeepSeek V4 Flash pricing).
     * Returns USD estimate.
     */
    public function estimateCost(string $text): float
    {
        // Rough: 1 token ≈ 2-3 Arabic chars. Output ~3x input due to diacritics.
        $inputTokens = mb_strlen($text) / 2.5;
        $outputTokens = $inputTokens * 3;

        // DeepSeek V4 Flash: $0.14/$0.28 per MTok
        return ($inputTokens * 0.14 + $outputTokens * 0.28) / 1_000_000;
    }
}
