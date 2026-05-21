<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Str;

/**
 * Generate a SHORT 3-sentence spoiler-free movie summary (Bahasa Indonesia)
 * for use on preview cards / hover previews.
 *
 * Output is persisted to:
 *   - movies.ai_short_summary
 *   - movies.ai_short_summary_generated_at
 *
 * Caller is responsible for queueing (see App\Jobs\GenerateMovieShortSummary)
 * and for any catalog-wide rate limiting (see AiGenerateShortSummary command).
 */
class MovieSummaryGenerator
{
    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate and persist the short summary. Returns the saved summary text.
     *
     * @throws \RuntimeException on AI provider failure or empty response.
     */
    public function generate(Movie $movie): string
    {
        $systemPrompt = "You're a film catalog editor for Indonesian streaming platform FLiK. "
            . "Write a SHORT 3-sentence spoiler-free summary in Indonesian for the preview card. "
            . "Each sentence under 25 words. Hook the reader. NO spoilers.";

        $userPrompt = $this->buildUserPrompt($movie);

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            options: [
                'max_tokens'  => 220,
                'temperature' => 0.7,
            ],
            taskType: 'summary.short_generate',
            subject: $movie,
        );

        $summary = $this->sanitize($response['content'] ?? '');

        if ($summary === '') {
            throw new \RuntimeException(
                "AI returned empty short-summary for movie #{$movie->id} ({$movie->title})."
            );
        }

        // Persist without firing model events (we don't want to retrigger any save hooks).
        $movie->forceFill([
            'ai_short_summary'              => $summary,
            'ai_short_summary_generated_at' => now(),
        ])->saveQuietly();

        return $summary;
    }

    /**
     * Build a structured user-side prompt. Genres + title + year are safe to share;
     * overview is given as raw context but the AI is told NOT to leak spoilers from it.
     */
    protected function buildUserPrompt(Movie $movie): string
    {
        $movie->loadMissing('genres');

        $title  = $movie->title;
        $year   = $movie->release_date?->format('Y') ?? 'unknown';
        $genres = $movie->genres->pluck('name')->filter()->implode(', ') ?: 'unknown';
        $overview = trim((string) ($movie->overview ?? ''));

        // Soft-cap context so we don't blow the token budget on long synopses.
        if ($overview !== '') {
            $overview = Str::limit($overview, 1200, '');
        } else {
            $overview = '(no overview provided)';
        }

        return <<<PROMPT
            Film context (for your understanding ONLY — the overview below may contain spoilers; DO NOT repeat them):

            Title  : {$title}
            Year   : {$year}
            Genres : {$genres}
            Overview (reference, possibly spoiler-laden):
            {$overview}

            Task:
            - Write exactly 3 sentences in natural Bahasa Indonesia.
            - Each sentence MUST be under 25 words.
            - Spoiler-free, suitable for a preview card teaser.
            - Hook the reader emotionally; describe premise + tone, not outcomes.
            - DO NOT spoil plot twists, deaths, endings, identity reveals.
            - DO NOT reveal who survives, who betrays whom, or how the conflict resolves.
            - DO NOT mention "spoiler", "sinopsis", or meta-commentary.
            - Output ONLY the 3 sentences, no headings, no numbering, no quotes.
            PROMPT;
    }

    /**
     * Strip wrapping quotes, leading numbering, code fences, and extra whitespace.
     */
    protected function sanitize(string $raw): string
    {
        $text = trim($raw);

        // Strip markdown code fences if the model wrapped output.
        $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text) ?? $text;

        // Strip surrounding straight or smart quotes.
        $text = trim($text, " \t\n\r\0\x0B\"'“”‘’");

        // Drop leading "1." / "1)" / "- " style numbering on each line.
        $text = preg_replace('/^\s*(?:\d+[\.\)]|[-*•])\s+/m', '', $text) ?? $text;

        // Collapse excessive blank lines.
        $text = preg_replace("/\n{2,}/", "\n", $text) ?? $text;

        return trim($text);
    }
}
