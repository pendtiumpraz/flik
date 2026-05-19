<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\TranslationCache;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Translate free-form text on demand, with a persistent cache (DB) so we
 * never pay for the same translation twice.
 *
 * Used by:
 *   - Movie::synopsisForLocale() → translates movie.overview on the fly when
 *     the viewer's locale differs from the catalog's source locale.
 *   - Anywhere a controller needs to project Indonesian copy into the
 *     viewer's UI locale without a pre-translated column on the model.
 *
 * Behaviour follows the AI-Task contract documented in CLAUDE.md:
 *   - Returns a string (never null) — on failure it returns the source text
 *     verbatim so the page keeps rendering.
 *   - Errors are logged via Log::warning/error, never thrown.
 *   - The AiClient call is tagged with $taskType "translate.<source>.<target>"
 *     so /admin/ai-usage can break down cost by locale pair.
 */
class TextTranslator
{
    /**
     * Strict-instruction system prompt. We deliberately ask for ONLY the
     * translation back (no preamble) so we can store the response verbatim
     * without post-processing.
     */
    protected const SYSTEM_PROMPT_TEMPLATE = 'You are a professional translator specialising in Indonesian cinema. '
        .'Translate the user\'s text from %SOURCE% to %TARGET%, preserving tone, register, and the '
        .'Indonesian movie context (preserve film titles, character names, and culturally specific terms '
        .'when there is no good equivalent). Output ONLY the translated text — no preamble, no quotes, '
        .'no markdown, no commentary. Keep punctuation and line breaks aligned with the source.';

    /**
     * Hard ceiling on source length per call — translating an entire novel
     * in one shot blows token budgets. Callers that need longer text should
     * chunk by paragraph.
     */
    protected const MAX_SOURCE_CHARS = 4000;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Translate $text from $sourceLocale into $targetLocale.
     *
     * Cache key is (target_locale, sha256(trim(source))) — two requests with
     * identical source + target collapse to a single DB row + a single AI call.
     *
     * @param  string  $text  Source text. May be empty (returns "" without hitting AI).
     * @param  string  $targetLocale  BCP-47 primary tag (e.g. "en", "ar").
     * @param  string  $sourceLocale  Defaults to "id" — the catalog source language.
     */
    public function translate(string $text, string $targetLocale, string $sourceLocale = 'id'): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        // No-op when source and target are the same — return verbatim to
        // avoid both DB write AND AI cost.
        if ($sourceLocale === $targetLocale) {
            return $text;
        }

        // Guard against absurdly long inputs (would blow token budget + bill).
        if (mb_strlen($trimmed) > self::MAX_SOURCE_CHARS) {
            $trimmed = mb_substr($trimmed, 0, self::MAX_SOURCE_CHARS);
        }

        $hash = TranslationCache::hashSource($trimmed);

        try {
            $cached = TranslationCache::query()
                ->where('target_locale', $targetLocale)
                ->where('source_hash', $hash)
                ->first();

            if ($cached !== null) {
                // Bump LRU timestamp so the eviction sweep keeps hot entries.
                // Using a raw update() avoids the model events overhead.
                TranslationCache::query()
                    ->whereKey($cached->id)
                    ->update(['last_used_at' => now()]);

                return (string) $cached->translation;
            }
        } catch (Throwable $e) {
            // DB read failure is non-fatal — fall through to a live AI call.
            // The page still gets translated; just no cache hit this round.
            Log::warning('TextTranslator: cache lookup failed', [
                'error' => $e->getMessage(),
                'target' => $targetLocale,
            ]);
        }

        // Live AI call. Wrap in try/catch so any provider hiccup
        // (network, 429, malformed response) degrades to the source text
        // instead of breaking the page.
        try {
            $system = strtr(self::SYSTEM_PROMPT_TEMPLATE, [
                '%SOURCE%' => $this->localeLabel($sourceLocale),
                '%TARGET%' => $this->localeLabel($targetLocale),
            ]);

            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $trimmed],
                ],
                [
                    'max_tokens' => 1200,
                    'temperature' => 0.2, // low — translation is not creative writing
                ],
                'translate.'.$sourceLocale.'.'.$targetLocale,
            );

            $translation = trim((string) ($response['content'] ?? ''));
            if ($translation === '') {
                Log::warning('TextTranslator: empty translation from AI', [
                    'target' => $targetLocale,
                    'preview' => Str::limit($trimmed, 80),
                ]);

                return $text; // graceful fallback
            }

            // Persist for the next call. Wrap in try/catch — a duplicate-key
            // race (two requests translating the same string simultaneously)
            // must NOT throw out to the caller.
            try {
                TranslationCache::query()->updateOrCreate(
                    [
                        'target_locale' => $targetLocale,
                        'source_hash' => $hash,
                    ],
                    [
                        'source_locale' => $sourceLocale,
                        'source_text' => $trimmed,
                        'translation' => $translation,
                        'provider' => (string) ($response['provider'] ?? 'unknown'),
                        'created_at' => now(),
                        'last_used_at' => now(),
                    ],
                );
            } catch (Throwable $e) {
                Log::warning('TextTranslator: cache write failed', [
                    'error' => $e->getMessage(),
                    'target' => $targetLocale,
                ]);
            }

            return $translation;
        } catch (Throwable $e) {
            Log::error('TextTranslator: AI call failed', [
                'error' => $e->getMessage(),
                'target' => $targetLocale,
                'preview' => Str::limit($trimmed, 80),
            ]);

            // Last-ditch fallback — return source so the page still renders.
            return $text;
        }
    }

    /**
     * Map a BCP-47 primary tag to a human-readable label the AI understands.
     * Unknown codes fall through verbatim — modern models handle "uk", "vi",
     * "th" etc. just fine, this map only exists to disambiguate the common
     * ones and keep prompts deterministic.
     */
    protected function localeLabel(string $code): string
    {
        return match (strtolower($code)) {
            'id' => 'Indonesian (Bahasa Indonesia)',
            'en' => 'English',
            'ar' => 'Arabic (Modern Standard Arabic)',
            'ms' => 'Malay',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh', 'zh-cn' => 'Simplified Chinese',
            'zh-tw' => 'Traditional Chinese',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            default => $code,
        };
    }
}
