<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Facades\Log;

/**
 * Explain confusing endings, plot twists, character arcs, and themes for a Movie.
 *
 * Uses the default active AiProvider (typically DeepSeek V4 Flash for text tasks).
 * Unlike SynopsisGenerator, this task EXPLICITLY allows spoilers — the user is
 * asking for them.
 *
 * Output target: Indonesian, 250-350 words, cinematic editorial tone, NO markdown.
 */
class PlotExplainer
{
    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $kb,
    ) {}

    /**
     * Generate a spoiler-laden plot explanation.
     *
     * @param  Movie   $movie         Movie row. Will be auto-loaded with genres + castMembers if not yet loaded.
     * @param  string  $userQuestion  Optional user follow-up. Empty → default "explain the ending" prompt.
     * @return array{explanation:string, spoiler_warning:bool, movie:Movie}
     *
     * @throws \RuntimeException When AI provider fails or returns empty content.
     */
    public function explain(Movie $movie, string $userQuestion = ''): array
    {
        // Make sure relations exist so buildContext has full cast / genre data.
        if (!$movie->relationLoaded('genres')) {
            $movie->load('genres');
        }
        if (!$movie->relationLoaded('castMembers')) {
            $movie->load('castMembers');
        }

        $userQuestion = trim($userQuestion);

        $systemPrompt = $this->systemPrompt();
        $userPrompt   = $this->buildUserPrompt($movie, $userQuestion);

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    // ~350 words * 2.2 tokens/word headroom for Indonesian
                    'max_tokens'  => 800,
                    'temperature' => 0.6,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('PlotExplainer: AI call failed', [
                'movie_id'    => $movie->id,
                'movie_title' => $movie->title,
                'has_question' => $userQuestion !== '',
                'error'       => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Failed to generate plot explanation for movie #{$movie->id}: " . $e->getMessage(),
                previous: $e
            );
        }

        $explanation = $this->sanitize((string) ($response['content'] ?? ''));

        if ($explanation === '') {
            throw new \RuntimeException(
                "AI returned empty plot explanation for movie #{$movie->id} ({$movie->title})."
            );
        }

        return [
            'explanation'     => $explanation,
            'spoiler_warning' => true,
            'movie'           => $movie,
        ];
    }

    /**
     * The cinematic-analyst system prompt (Indonesian output).
     */
    protected function systemPrompt(): string
    {
        return "You're a film analyst specializing in explaining complex plots, ending twists, "
            . "character arcs, and theme interpretation. Be detailed but concise (250-350 words). "
            . "User WANTS spoilers — explain freely. Cover: what happened in the ending, what it means "
            . "thematically, key clues throughout the film, character motivations. Output Indonesian. "
            . "NO markdown formatting (no bold, no bullets, no headers). Write as flowing analytical prose. "
            . "Do not preface with 'Tentu' or 'Baik'. Start directly with the analysis.";
    }

    /**
     * Compose the user-side prompt with full movie context.
     */
    protected function buildUserPrompt(Movie $movie, string $userQuestion): string
    {
        $title         = trim((string) $movie->title);
        $originalTitle = trim((string) ($movie->original_title ?? ''));
        $overview      = trim((string) ($movie->overview ?? ''));

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
            $genres = $movie->genres->pluck('name')->filter()->values()->all();
        } catch (\Throwable) {
            $genres = [];
        }

        // FULL cast (not the 5-person summary FilmKnowledgeService uses for cards).
        $castLines = [];
        try {
            foreach ($movie->castMembers as $member) {
                $character = $member->pivot->character ?? null;
                $castLines[] = $character
                    ? "{$member->name} sebagai {$character}"
                    : $member->name;
            }
        } catch (\Throwable) {
            $castLines = [];
        }

        $lines = [];
        $lines[] = "Konteks Film:";
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

        if (!empty($castLines)) {
            $lines[] = "Pemeran:";
            foreach ($castLines as $cl) {
                $lines[] = "- {$cl}";
            }
        }

        if ($overview !== '') {
            $lines[] = "";
            $lines[] = "Sinopsis Resmi:";
            $lines[] = $overview;
        }

        $lines[] = "";

        if ($userQuestion !== '') {
            // Verbatim — user explicitly asked something specific.
            $lines[] = $userQuestion;
        } else {
            $lines[] = "Jelaskan ending dan plot twist film {$title}";
        }

        return implode("\n", $lines);
    }

    /**
     * Strip stray markdown / code fences / wrapping quotes that some models add.
     * Mirrors SynopsisGenerator::sanitize() for output consistency.
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
        $last  = mb_substr($text, -1, 1);
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
