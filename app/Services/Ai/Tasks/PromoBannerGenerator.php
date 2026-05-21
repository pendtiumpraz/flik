<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Str;

/**
 * AI-powered generator for promo banner copy (Indonesian).
 *
 * Returns headline + subheadline + CTA button text for a given Movie,
 * tuned to a tone-of-voice (cinematic / casual / urgent / celebratory).
 *
 * Output shape: ['headline' => string, 'subheadline' => string, 'cta_text' => string].
 * Length constraints are enforced via prompt + post-truncation safety net.
 */
class PromoBannerGenerator
{
    public const TONES = ['cinematic', 'casual', 'urgent', 'celebratory'];

    public const MAX_HEADLINE = 60;
    public const MAX_SUBHEADLINE = 100;
    public const MAX_CTA = 20;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate banner copy for a movie.
     *
     * @param  Movie   $movie
     * @param  string  $tone   One of self::TONES (defaults to 'cinematic')
     * @return array{headline: string, subheadline: string, cta_text: string}
     */
    public function generate(Movie $movie, string $tone = 'cinematic'): array
    {
        $tone = in_array($tone, self::TONES, true) ? $tone : 'cinematic';

        $systemPrompt = 'You\'re a senior copywriter for Indonesian streaming platform FLiK. '
            . 'Write promo banner copy in Indonesian. Output strict JSON: '
            . '{"headline": "max 60 chars", "subheadline": "max 100 chars", "cta_text": "max 20 chars button text"}.';

        $userPrompt = $this->buildUserPrompt($movie, $tone);

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            options: [
                'temperature' => 0.85,
                'max_tokens'  => 300,
            ],
            taskType: 'marketing.banner_' . $tone,
            subject: $movie,
        );

        return $this->parseAndClamp($response['content'] ?? '', $movie, $tone);
    }

    /**
     * Build the user prompt — injects movie metadata + tone instructions.
     */
    protected function buildUserPrompt(Movie $movie, string $tone): string
    {
        $movie->loadMissing('genres');

        $genres  = $movie->genres->pluck('name')->take(4)->join(', ');
        $year    = optional($movie->release_date)->format('Y');
        $rating  = $movie->vote_average ? round((float) $movie->vote_average, 1) . '/10' : null;
        $overview = Str::limit((string) ($movie->overview ?? ''), 400);

        $toneGuide = match ($tone) {
            'casual'       => 'Tone: kasual, akrab, ngobrol santai (boleh pakai "kamu", "yuk"). Hindari kata bombastis.',
            'urgent'       => 'Tone: urgent & FOMO — buat penonton merasa rugi kalau melewatkan. Pakai kata seperti "sekarang", "jangan sampai ketinggalan", "terbatas".',
            'celebratory'  => 'Tone: meriah, perayaan, exclamation friendly. Cocok untuk premiere / rilisan baru / award winner.',
            default        => 'Tone: cinematic, sinematik megah, evocative. Pakai bahasa puitis tapi tetap padat dan tajam.',
        };

        $lines = [
            'Buat copy promo banner untuk film berikut:',
            '',
            'Judul       : ' . $movie->title,
            $movie->original_title && $movie->original_title !== $movie->title ? 'Judul Asli  : ' . $movie->original_title : null,
            $year   ? 'Tahun       : ' . $year   : null,
            $genres ? 'Genre       : ' . $genres : null,
            $rating ? 'Rating      : ' . $rating : null,
            $overview ? 'Sinopsis    : ' . $overview : null,
            '',
            $toneGuide,
            '',
            'Aturan output:',
            '- Bahasa Indonesia.',
            '- Headline maksimal ' . self::MAX_HEADLINE . ' karakter.',
            '- Subheadline maksimal ' . self::MAX_SUBHEADLINE . ' karakter, melengkapi headline (bukan mengulang).',
            '- CTA maksimal ' . self::MAX_CTA . ' karakter, action-oriented (contoh: "Nonton Sekarang", "Streaming Now").',
            '- HANYA balas dengan JSON valid. Tanpa code fence. Tanpa penjelasan tambahan.',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    /**
     * Parse AI JSON output, fall back to safe defaults, and clamp lengths.
     *
     * @return array{headline: string, subheadline: string, cta_text: string}
     */
    protected function parseAndClamp(string $raw, Movie $movie, string $tone): array
    {
        $json = $this->extractJson($raw);

        $headline    = trim((string) ($json['headline']    ?? ''));
        $subheadline = trim((string) ($json['subheadline'] ?? ''));
        $cta         = trim((string) ($json['cta_text']    ?? ''));

        // Safe fallbacks if AI returned junk
        if ($headline === '') {
            $headline = $movie->title;
        }
        if ($subheadline === '') {
            $subheadline = Str::limit((string) $movie->overview, self::MAX_SUBHEADLINE - 3);
        }
        if ($cta === '') {
            $cta = match ($tone) {
                'urgent'      => 'Nonton Sekarang',
                'celebratory' => 'Rayakan Sekarang',
                'casual'      => 'Tonton Yuk',
                default       => 'Nonton Sekarang',
            };
        }

        return [
            'headline'    => $this->clamp($headline,    self::MAX_HEADLINE),
            'subheadline' => $this->clamp($subheadline, self::MAX_SUBHEADLINE),
            'cta_text'    => $this->clamp($cta,         self::MAX_CTA),
        ];
    }

    /**
     * Best-effort JSON extraction. Strips code fences if model added them.
     */
    protected function extractJson(string $raw): array
    {
        $clean = trim($raw);

        // Strip ```json ... ``` fences
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Find first { ... last } if there's prose around it
        $first = strpos($clean, '{');
        $last  = strrpos($clean, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $clean = substr($clean, $first, $last - $first + 1);
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Hard-clamp to max chars, multibyte safe, with ellipsis only if truncated.
     */
    protected function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        // Reserve 1 char for ellipsis
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
