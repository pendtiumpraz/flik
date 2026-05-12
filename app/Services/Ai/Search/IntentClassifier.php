<?php

namespace App\Services\Ai\Search;

use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Universal search intent classifier.
 *
 * Given a free-form query (Indonesian or English), asks the AI to bucket it into one of:
 *   - title     (a specific film name, e.g. "Inception")
 *   - actor     (a person — actor / actress)
 *   - director  (a person — director)
 *   - vibe      (mood, style, decade, era — "film 70an seperti The Godfather")
 *   - year      (a single year or decade — "2010", "90an", "2020")
 *   - genre     (one of the catalog genres — "horror", "comedy", "drama")
 *
 * Returns an array of shape:
 *   ['intent' => string, 'normalized_query' => string, 'confidence' => float]
 *
 * Caches per-query for 1 hour (case-insensitive trim) to avoid hammering the
 * provider on repeat keystrokes from the smart bar.
 *
 * Degrades gracefully — when the AI is unavailable, falls back to a keyword
 * heuristic that picks the most likely intent. Never throws.
 */
class IntentClassifier
{
    /**
     * Cache TTL in seconds (1 hour).
     */
    protected const CACHE_TTL = 3600;

    /**
     * Valid intent buckets — anything outside this list is coerced to 'title'.
     */
    protected const VALID_INTENTS = ['title', 'actor', 'director', 'vibe', 'year', 'genre'];

    public function __construct(protected AiClient $ai)
    {
    }

    /**
     * Classify a query's intent.
     *
     * @return array{intent: string, normalized_query: string, confidence: float}
     */
    public function classify(string $query): array
    {
        $clean = trim($query);
        if ($clean === '') {
            return [
                'intent' => 'title',
                'normalized_query' => '',
                'confidence' => 0.0,
            ];
        }

        $cacheKey = 'intent:' . md5(mb_strtolower($clean));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($clean) {
            return $this->classifyFresh($clean);
        });
    }

    /**
     * Uncached classification path. Tries AI first, falls back to heuristic on any failure.
     *
     * @return array{intent: string, normalized_query: string, confidence: float}
     */
    protected function classifyFresh(string $query): array
    {
        $system = "You classify movie-search queries into ONE intent bucket.\n"
            . "Categories (pick exactly one):\n"
            . "  - title    : a specific film name (e.g. \"Inception\", \"Pengabdi Setan\")\n"
            . "  - actor    : an actor / actress name (e.g. \"Reza Rahadian\", \"Tom Hanks\")\n"
            . "  - director : a director name (e.g. \"Joko Anwar\", \"Christopher Nolan\")\n"
            . "  - vibe     : mood / style / era phrasing (e.g. \"film 70an seperti The Godfather\", \"slow-burn noir\")\n"
            . "  - year     : a single year or decade only (e.g. \"2010\", \"90an\", \"2020s\")\n"
            . "  - genre    : a genre name (e.g. \"horror\", \"komedi\", \"drama\")\n\n"
            . "Also produce a cleaned 'normalized_query' (trim, drop filler words like \"film\", \"tonton\", \"cari\", correct obvious casing).\n"
            . "And a 'confidence' between 0.0 and 1.0.\n\n"
            . "Output WAJIB strict JSON without markdown fences, exactly this shape:\n"
            . '{"intent":"title","normalized_query":"Inception","confidence":0.95}' . "\n"
            . "Support Indonesian + English input.";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $query],
                ],
                options: [
                    'max_tokens' => 120,
                    'temperature' => 0.1,
                ],
                taskType: 'search.intent_classify',
            );

            $parsed = $this->parseJson((string) ($response['content'] ?? ''));
            if ($parsed !== null) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            Log::warning('IntentClassifier: AI call failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->heuristic($query);
    }

    /**
     * Parse the model's JSON response into a normalized shape.
     *
     * @return array{intent: string, normalized_query: string, confidence: float}|null
     */
    protected function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $intent = is_string($decoded['intent'] ?? null) ? mb_strtolower(trim($decoded['intent'])) : 'title';
        if (!in_array($intent, self::VALID_INTENTS, true)) {
            $intent = 'title';
        }

        $normalized = is_string($decoded['normalized_query'] ?? null)
            ? trim($decoded['normalized_query'])
            : '';

        $confidenceRaw = $decoded['confidence'] ?? 0.5;
        $confidence = is_numeric($confidenceRaw) ? (float) $confidenceRaw : 0.5;
        $confidence = max(0.0, min(1.0, $confidence));

        return [
            'intent' => $intent,
            'normalized_query' => $normalized,
            'confidence' => $confidence,
        ];
    }

    /**
     * Cheap keyword-based fallback when AI is unavailable.
     *
     * @return array{intent: string, normalized_query: string, confidence: float}
     */
    protected function heuristic(string $query): array
    {
        $needle = mb_strtolower($query);
        $normalized = $this->stripFiller($query);

        // Year / decade
        if (preg_match('/^\s*(19|20)?\d{2}(?:\s*-?(?:an|s|en))?\s*$/u', $needle)) {
            return ['intent' => 'year', 'normalized_query' => trim($normalized), 'confidence' => 0.7];
        }

        // Vibe markers — "seperti X", "like X", "kayak X", explicit decade hints, mood adjectives
        $vibeMarkers = [
            'seperti', 'kayak', 'mirip', 'like', 'vibe', 'mood',
            'noir', 'slow-burn', 'slow burn', 'epic', 'cozy',
            '70an', '80an', '90an', '2000an', '2010an', '2020an',
            '70s', '80s', '90s',
        ];
        foreach ($vibeMarkers as $marker) {
            if (str_contains($needle, $marker)) {
                return ['intent' => 'vibe', 'normalized_query' => trim($normalized), 'confidence' => 0.65];
            }
        }

        // Genre keywords (matches DecadeStyleSearchService heuristic set)
        $genreKeywords = [
            'horror', 'horor', 'romance', 'romantis', 'comedy', 'komedi', 'lucu',
            'drama', 'action', 'aksi', 'laga', 'thriller', 'crime', 'kriminal',
            'sci-fi', 'fantasy', 'fantasi', 'animation', 'animasi', 'kartun',
            'documentary', 'dokumenter',
        ];
        foreach ($genreKeywords as $g) {
            if ($needle === $g || preg_match('/\b' . preg_quote($g, '/') . '\b/u', $needle)) {
                return ['intent' => 'genre', 'normalized_query' => trim($normalized), 'confidence' => 0.6];
            }
        }

        // Director / actor markers
        if (preg_match('/\b(directed by|sutradara|director|dirigir)\b/iu', $needle)) {
            return ['intent' => 'director', 'normalized_query' => trim($normalized), 'confidence' => 0.6];
        }
        if (preg_match('/\b(starring|aktor|aktris|actor|actress|pemain)\b/iu', $needle)) {
            return ['intent' => 'actor', 'normalized_query' => trim($normalized), 'confidence' => 0.6];
        }

        // Looks-like-a-person-name: 2-3 capitalised tokens, no other markers
        if (preg_match('/^([A-Z][a-zà-ÿ]+(?:\s+[A-Z][a-zà-ÿ]+){1,2})$/u', trim($query))) {
            return ['intent' => 'actor', 'normalized_query' => trim($query), 'confidence' => 0.5];
        }

        // Default — assume it's a title
        return ['intent' => 'title', 'normalized_query' => trim($normalized), 'confidence' => 0.4];
    }

    /**
     * Strip Indonesian / English filler words for the normalized form.
     */
    protected function stripFiller(string $query): string
    {
        $filler = [
            'film', 'movie', 'tonton', 'nonton', 'cari', 'carikan',
            'tolong', 'mau', 'pengen', 'pingin', 'rekom', 'rekomendasi',
            'rekomenin', 'judul', 'kasih',
        ];
        $tokens = preg_split('/\s+/', trim($query));
        if (!is_array($tokens)) {
            return $query;
        }
        $kept = array_filter(
            $tokens,
            fn ($t) => !in_array(mb_strtolower($t), $filler, true),
        );
        return implode(' ', $kept);
    }
}
