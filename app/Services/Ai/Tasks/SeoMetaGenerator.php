<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generate SEO meta tags (title, description, keywords) for a Movie
 * using the configured AI provider (default: DeepSeek V4 Flash).
 *
 * Output is persisted to the seo_title / seo_description / seo_keywords
 * / seo_generated_at columns on the movie row, and also returned as an array
 * so callers can use it without re-querying.
 */
class SeoMetaGenerator
{
    /**
     * Hard caps that match DB column lengths.
     * AI is also instructed in the prompt, but we truncate defensively.
     */
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MAX = 160;
    public const KEYWORDS_MAX = 500;

    public function __construct(
        protected AiClient $ai
    ) {}

    /**
     * Generate and persist SEO meta for a movie.
     *
     * @return array{title:string, description:string, keywords:string}
     */
    public function generate(Movie $movie): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You\'re SEO specialist for Indonesian streaming platform FLiK. Generate SEO meta for movie page. Output strict JSON: {"title": "max 60 chars include movie title + year + tagline", "description": "max 160 chars compelling description with call to watch", "keywords": "comma-separated 8-12 relevant keywords in Indonesian + English"}. Indonesian for body, mix with English for keywords.',
            ],
            [
                'role' => 'user',
                'content' => $this->buildUserPrompt($movie),
            ],
        ];

        $response = $this->ai->chat(
            messages: $messages,
            options: [
                'max_tokens' => 400,
                'temperature' => 0.6,
            ],
            taskType: 'seo.meta_generate',
            subject: $movie,
        );

        $parsed = $this->parseJson($response['content'] ?? '');

        $title = $this->sanitize($parsed['title'] ?? '', self::TITLE_MAX)
            ?: $this->fallbackTitle($movie);
        $description = $this->sanitize($parsed['description'] ?? '', self::DESCRIPTION_MAX)
            ?: $this->fallbackDescription($movie);
        $keywords = $this->sanitize($parsed['keywords'] ?? '', self::KEYWORDS_MAX)
            ?: $this->fallbackKeywords($movie);

        // Persist (saveQuietly so model observers don't trigger on a non-content edit)
        $movie->forceFill([
            'seo_title' => $title,
            'seo_description' => $description,
            'seo_keywords' => $keywords,
            'seo_generated_at' => now(),
        ])->saveQuietly();

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
        ];
    }

    /**
     * Build the user prompt with movie context.
     */
    protected function buildUserPrompt(Movie $movie): string
    {
        $year = $movie->release_date?->format('Y');
        $genres = $movie->relationLoaded('genres')
            ? $movie->genres->pluck('name')->join(', ')
            : $movie->genres()->pluck('name')->join(', ');
        $cast = $movie->relationLoaded('castMembers')
            ? $movie->castMembers->take(5)->pluck('name')->join(', ')
            : $movie->castMembers()->limit(5)->pluck('name')->join(', ');

        $overview = Str::limit((string) $movie->overview, 600);
        $rating = $movie->vote_average ? round((float) $movie->vote_average, 1) : null;

        $lines = [
            "Title: {$movie->title}",
            $movie->original_title && $movie->original_title !== $movie->title
                ? "Original title: {$movie->original_title}"
                : null,
            $year ? "Release year: {$year}" : null,
            $genres ? "Genres: {$genres}" : null,
            $cast ? "Top cast: {$cast}" : null,
            $rating ? "Rating: {$rating}/10" : null,
            $overview ? "Overview: {$overview}" : null,
        ];

        $context = implode("\n", array_filter($lines));

        return "Generate SEO meta JSON for this movie page on FLiK.\n\n{$context}\n\nReturn ONLY valid JSON, no markdown fences, no commentary.";
    }

    /**
     * Robustly parse JSON from AI response.
     * Strips markdown fences and tries to recover from common drift.
     *
     * @return array<string,mixed>
     */
    protected function parseJson(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') return [];

        // Strip ```json ... ``` fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', (string) $text);

        $decoded = json_decode((string) $text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to extract the first {...} block
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', (string) $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('SeoMetaGenerator: failed to parse AI JSON', ['raw' => Str::limit($raw, 300)]);

        return [];
    }

    /**
     * Trim, collapse whitespace, strip control chars, enforce length.
     */
    protected function sanitize(mixed $value, int $max): string
    {
        if (!is_string($value)) return '';
        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?? '';
        return Str::limit($clean, $max, '');
    }

    protected function fallbackTitle(Movie $movie): string
    {
        $year = $movie->release_date?->format('Y');
        $base = $year ? "{$movie->title} ({$year})" : $movie->title;
        return Str::limit("{$base} — Streaming di FLiK", self::TITLE_MAX, '');
    }

    protected function fallbackDescription(Movie $movie): string
    {
        $overview = (string) ($movie->overview ?? '');
        if ($overview !== '') {
            return Str::limit("Nonton {$movie->title} di FLiK. {$overview}", self::DESCRIPTION_MAX, '');
        }
        return Str::limit("Nonton {$movie->title} di FLiK — rumah sinema Indonesia. Streaming film premium dengan kualitas terbaik.", self::DESCRIPTION_MAX, '');
    }

    protected function fallbackKeywords(Movie $movie): string
    {
        $parts = array_filter([
            $movie->title,
            'nonton ' . $movie->title,
            'streaming ' . $movie->title,
            'FLiK',
            'film Indonesia',
            'streaming film',
            'nonton online',
            'watch movie online',
        ]);
        return Str::limit(implode(', ', $parts), self::KEYWORDS_MAX, '');
    }
}
