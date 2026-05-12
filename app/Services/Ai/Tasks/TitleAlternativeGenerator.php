<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Str;

/**
 * AI-powered alternative title generator for SEO + A/B testing.
 *
 * Use cases:
 *   - Display-title experimentation (test which title drives more click-through).
 *   - SEO landing-page variants (long-tail keyword coverage).
 *   - Localised re-titling (Indonesian-friendly when original is English).
 *
 * Output shape per alternative:
 *   ['title' => string (max 60 chars), 'reasoning' => string]
 */
class TitleAlternativeGenerator
{
    public const MAX_TITLE_CHARS = 60;
    public const DEFAULT_COUNT   = 5;
    public const MAX_COUNT       = 10;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate alternative titles for a movie.
     *
     * @param  Movie  $movie
     * @param  int    $count  How many alternatives to produce (1..10).
     * @return array<int, array{title: string, reasoning: string}>
     */
    public function generate(Movie $movie, int $count = self::DEFAULT_COUNT): array
    {
        $count = max(1, min(self::MAX_COUNT, $count));

        $movie->loadMissing('genres');

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($count)],
                ['role' => 'user',   'content' => $this->buildUserPrompt($movie, $count)],
            ],
            options: [
                'temperature' => 0.9,
                'max_tokens'  => 700,
            ],
        );

        return $this->parseAndClamp($response['content'] ?? '', $movie, $count);
    }

    protected function buildSystemPrompt(int $count): string
    {
        return 'You\'re a senior SEO copywriter for Indonesian streaming platform FLiK. '
            . "Generate exactly {$count} alternative SEO-friendly Indonesian titles for a film. "
            . 'Each alternative must:'
            . ' (1) be under ' . self::MAX_TITLE_CHARS . ' characters,'
            . ' (2) reflect the genre, era, mood, or themes,'
            . ' (3) be searchable by Indonesian audiences,'
            . ' (4) avoid trademarked phrases & misleading claims,'
            . ' (5) NOT simply duplicate the original title.'
            . ' Variations may include: literal translation, mood-driven title,'
            . ' question-form, keyword-rich descriptive, intriguing teaser. '
            . 'Output STRICT JSON array only — no code fences, no commentary. '
            . 'Shape: [{"title": "string", "reasoning": "1-sentence why this works for SEO/CTR"}, ...]';
    }

    protected function buildUserPrompt(Movie $movie, int $count): string
    {
        $genres   = $movie->genres->pluck('name')->take(5)->join(', ');
        $year     = optional($movie->release_date)->format('Y');
        $era      = $year ? $this->eraLabel((int) $year) : null;
        $rating   = $movie->vote_average ? round((float) $movie->vote_average, 1) . '/10' : null;
        $overview = Str::limit((string) ($movie->overview ?? ''), 500);

        $lines = [
            "Buat {$count} judul alternatif SEO-friendly dalam Bahasa Indonesia untuk film:",
            '',
            'Original Title : ' . $movie->title,
            $movie->original_title && $movie->original_title !== $movie->title ? 'Judul Asli     : ' . $movie->original_title : null,
            $year   ? 'Tahun          : ' . $year . ($era ? ' (' . $era . ')' : '') : null,
            $genres ? 'Genre          : ' . $genres : null,
            $rating ? 'Rating         : ' . $rating : null,
            $overview ? 'Sinopsis       : ' . $overview : null,
            '',
            'Aturan output:',
            '- Bahasa Indonesia.',
            '- Setiap judul max ' . self::MAX_TITLE_CHARS . ' karakter.',
            '- Variasikan gaya: literal, mood, pertanyaan, deskriptif kaya keyword, teaser.',
            '- Reasoning singkat (1 kalimat) menjelaskan kenapa cocok untuk SEO/CTR.',
            '- HANYA balas JSON array valid. Tanpa code fence. Tanpa penjelasan tambahan.',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    /**
     * @return array<int, array{title:string, reasoning:string}>
     */
    protected function parseAndClamp(string $raw, Movie $movie, int $count): array
    {
        $items = $this->extractJsonArray($raw);

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $title     = trim((string) ($item['title']     ?? ''));
            $reasoning = trim((string) ($item['reasoning'] ?? ''));

            if ($title === '') continue;

            $out[] = [
                'title'     => $this->clamp($title, self::MAX_TITLE_CHARS),
                'reasoning' => $reasoning !== '' ? $reasoning : 'Variasi judul untuk A/B testing CTR.',
            ];

            if (count($out) >= $count) break;
        }

        // Ensure at least one fallback so the UI never renders an empty grid.
        if (empty($out)) {
            $out[] = [
                'title'     => $this->clamp($movie->title . ' (Versi Indonesia)', self::MAX_TITLE_CHARS),
                'reasoning' => 'Fallback — generator gagal mengurai output AI.',
            ];
        }

        return $out;
    }

    /**
     * Extract a JSON array, tolerating code fences and prose around it.
     *
     * @return array<int, mixed>
     */
    protected function extractJsonArray(string $raw): array
    {
        $clean = trim($raw);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Slice from first [ to last ] if there's prose
        $first = strpos($clean, '[');
        $last  = strrpos($clean, ']');
        if ($first !== false && $last !== false && $last > $first) {
            $clean = substr($clean, $first, $last - $first + 1);
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Loose era label for prompt context — helps the AI tune word choice. */
    protected function eraLabel(int $year): string
    {
        if ($year < 1970) return 'klasik';
        if ($year < 1990) return 'retro';
        if ($year < 2010) return 'modern klasik';
        if ($year < 2020) return 'modern';
        return 'kontemporer';
    }

    /** Multibyte-safe hard clamp. */
    protected function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
