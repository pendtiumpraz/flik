<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Generate a polished, user-facing Indonesian synopsis for a Movie.
 *
 * Uses the default active AiProvider (typically DeepSeek V4 Flash for text tasks).
 * Persists the result on the Movie row (`ai_synopsis` + `ai_synopsis_generated_at`).
 *
 * Tone: cinematic editorial, accessible, NO spoilers, NO markdown — pure prose.
 */
class SynopsisGenerator
{
    public function __construct(
        protected AiClient $ai
    ) {}

    /**
     * Generate and persist an AI synopsis for the movie.
     *
     * @param  Movie  $movie     Movie with at minimum a `title`. `original_title`,
     *                           `overview`, `release_date`, and loaded `genres` improve quality.
     * @param  int    $maxWords  Target word budget for the synopsis. Default 150.
     * @return string            The generated synopsis (also saved to $movie->ai_synopsis).
     *
     * @throws \RuntimeException When AI provider fails or returns empty content.
     */
    public function generate(Movie $movie, int $maxWords = 150): string
    {
        $maxWords = max(40, min($maxWords, 400));

        $systemPrompt = sprintf(
            "You're a senior film editorial writer for Indonesian streaming platform FLiK. "
            . "Write a polished, engaging synopsis (%d words) in Indonesian for the user-facing "
            . "film detail page. Tone: cinematic but accessible. NO spoilers. NO markdown formatting. "
            . "Pure prose.",
            $maxWords
        );

        $userPrompt = $this->buildUserPrompt($movie, $maxWords);

        // Token budget: ~1.4 tokens per word in Indonesian, plus headroom.
        $maxTokens = (int) ceil($maxWords * 2.2);

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                [
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.7,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('SynopsisGenerator: AI call failed', [
                'movie_id' => $movie->id,
                'movie_title' => $movie->title,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Failed to generate synopsis for movie #{$movie->id}: " . $e->getMessage(),
                previous: $e
            );
        }

        $synopsis = $this->sanitize((string) ($response['content'] ?? ''));

        if ($synopsis === '') {
            throw new \RuntimeException(
                "AI returned empty synopsis for movie #{$movie->id} ({$movie->title})."
            );
        }

        try {
            $movie->forceFill([
                'ai_synopsis' => $synopsis,
                'ai_synopsis_generated_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('SynopsisGenerator: persist failed', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Failed to save synopsis for movie #{$movie->id}: " . $e->getMessage(),
                previous: $e
            );
        }

        return $synopsis;
    }

    /**
     * Compose the user-side prompt with all available signals.
     */
    protected function buildUserPrompt(Movie $movie, int $maxWords): string
    {
        $title = trim((string) $movie->title);
        $originalTitle = trim((string) ($movie->original_title ?? ''));
        $overview = trim((string) ($movie->overview ?? ''));

        $year = null;
        if (!empty($movie->release_date)) {
            try {
                $year = $movie->release_date instanceof \DateTimeInterface
                    ? (int) $movie->release_date->format('Y')
                    : (int) date('Y', strtotime((string) $movie->release_date));
            } catch (\Throwable) {
                $year = null;
            }
        }

        $genres = [];
        try {
            // Use already-loaded relation if available; otherwise lazy-load.
            $genreCollection = $movie->relationLoaded('genres')
                ? $movie->genres
                : $movie->genres()->get();
            $genres = $genreCollection->pluck('name')->filter()->values()->all();
        } catch (\Throwable) {
            $genres = [];
        }

        $lines = [];
        $lines[] = "Tulis sinopsis berbahasa Indonesia (~{$maxWords} kata) untuk film berikut:";
        $lines[] = '';
        $lines[] = "Judul: {$title}";

        if ($originalTitle !== '' && strcasecmp($originalTitle, $title) !== 0) {
            $lines[] = "Judul Asli: {$originalTitle}";
        }

        if ($year !== null && $year > 1880) {
            $lines[] = "Tahun Rilis: {$year}";
        }

        if (!empty($genres)) {
            $lines[] = "Genre: " . implode(', ', $genres);
        }

        if ($overview !== '') {
            $lines[] = '';
            $lines[] = "Sinopsis Asli (referensi mentah, jangan diterjemahkan langsung):";
            $lines[] = $overview;
        }

        $lines[] = '';
        $lines[] = "Tugas: Tulis ulang menjadi sinopsis editorial yang sinematik, mengalir, dan menggugah rasa penasaran. "
            . "Hindari spoiler. Hindari format markdown atau bullet. Keluarkan hanya teks sinopsis itu sendiri "
            . "(tanpa judul, tanpa label, tanpa tanda kutip pembungkus).";

        return implode("\n", $lines);
    }

    /**
     * Strip stray markdown, code fences, and surrounding quotes that some models add.
     */
    protected function sanitize(string $text): string
    {
        $text = trim($text);

        // Remove fenced code blocks if the model wrapped output.
        $text = preg_replace('/^```[a-zA-Z]*\s*/m', '', $text) ?? $text;
        $text = preg_replace('/```\s*$/m', '', $text) ?? $text;

        // Strip leading markdown headers / bullets.
        $text = preg_replace('/^\s*#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*]\s+/m', '', $text) ?? $text;

        // Drop bold/italic markers but keep inner text.
        $text = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.*?)\1/s', '$2', $text) ?? $text;

        // Unwrap surrounding straight or curly quotes.
        $text = trim($text);
        $first = mb_substr($text, 0, 1);
        $last = mb_substr($text, -1, 1);
        $pairs = [['"', '"'], ["'", "'"], ['“', '”'], ['‘', '’'], ['«', '»']];
        foreach ($pairs as [$open, $close]) {
            if ($first === $open && $last === $close) {
                $text = trim(mb_substr($text, 1, mb_strlen($text) - 2));
                break;
            }
        }

        // Collapse 3+ blank lines.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
