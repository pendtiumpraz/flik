<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieAiReview;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use InvalidArgumentException;

/**
 * Generate multi-perspective AI reviews for a Movie.
 *
 * Four reviewer voices are supported, each with its own system prompt,
 * tone, target word-count, and analytical lens:
 *
 *  - critic   : 200 words, analytical, Indonesian publication critic
 *  - casual   : 150 words, friendly blogger tone
 *  - family   : 120 words, parent/family-friendliness focus
 *  - academic : 250 words, film-studies analysis with theory references
 *
 * Reviews are persisted to `movie_ai_reviews` (unique by movie_id + perspective).
 * Re-generating overwrites the previous version via updateOrCreate.
 */
class MovieReviewer
{
    /**
     * Tunables per perspective. Centralized so the prompt copy + token budget
     * stay in lockstep — bumping a word count without bumping max_tokens
     * leads to mid-sentence cutoffs.
     */
    protected const PROFILES = [
        'critic' => [
            'system' => "You're a film critic for a major Indonesian publication. Write critical analysis in Indonesian, 200 words. Cover direction, performances, screenplay, themes.",
            'max_tokens' => 700,
            'temperature' => 0.6,
        ],
        'casual' => [
            'system' => "You're an Indonesian movie blogger writing for general audience. Casual tone, 150 words, fun and relatable.",
            'max_tokens' => 550,
            'temperature' => 0.85,
        ],
        'family' => [
            'system' => "You're an Indonesian parent reviewing for family-friendliness. 120 words, focus on age-appropriateness, themes, language, violence.",
            'max_tokens' => 450,
            'temperature' => 0.55,
        ],
        'academic' => [
            'system' => "You're a film studies academic. 250 words, analytical, with theoretical references (auteur theory, genre conventions, etc).",
            'max_tokens' => 850,
            'temperature' => 0.5,
        ],
    ];

    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $knowledge,
    ) {}

    /**
     * Produce (and persist) a review of $movie from the given $perspective.
     *
     * @throws InvalidArgumentException if perspective is not one of the four supported voices
     * @throws \RuntimeException        if the AI provider returns an empty body
     */
    public function review(Movie $movie, string $perspective): MovieAiReview
    {
        if (!array_key_exists($perspective, self::PROFILES)) {
            throw new InvalidArgumentException(
                "Unknown perspective '{$perspective}'. Allowed: " . implode(', ', array_keys(self::PROFILES))
            );
        }

        $profile = self::PROFILES[$perspective];

        // Eager-load relations FilmKnowledgeService::formatForAi() touches —
        // avoids N+1 when caller passes a freshly-bound route model.
        $movie->loadMissing(['genres', 'castMembers']);
        $context = $this->knowledge->formatForAi($movie);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($profile['system'])],
            ['role' => 'user',   'content' => $this->buildUserPrompt($context)],
        ];

        $response = $this->ai->chat(
            messages: $messages,
            options: [
                'max_tokens'  => $profile['max_tokens'],
                'temperature' => $profile['temperature'],
            ],
            taskType: 'review.' . $perspective,
            subject: $movie,
        );

        $raw = trim((string) ($response['content'] ?? ''));
        if ($raw === '') {
            throw new \RuntimeException(
                "AI provider returned empty review for movie #{$movie->id} ({$perspective})."
            );
        }

        [$title, $body, $rating] = $this->parseReview($raw, $movie, $perspective);

        $providerUsed = trim(
            ($response['provider'] ?? 'unknown') . ':' . ($response['model'] ?? 'unknown'),
            ':'
        );

        return MovieAiReview::updateOrCreate(
            [
                'movie_id'    => $movie->id,
                'perspective' => $perspective,
            ],
            [
                'title'         => $title,
                'body'          => $body,
                'rating'        => $rating,
                'provider_used' => $providerUsed,
                'generated_at'  => now(),
            ]
        );
    }

    /**
     * Augment the perspective system prompt with a strict output contract so
     * we can deterministically extract title / rating / body downstream.
     */
    protected function buildSystemPrompt(string $base): string
    {
        return $base . "\n\n"
            . "FORMAT OUTPUT (wajib, persis seperti template, tanpa markdown fence):\n"
            . "TITLE: <judul review pendek 4-8 kata>\n"
            . "RATING: <angka 0.0-10.0, satu desimal — tulis 'N/A' jika tidak relevan>\n"
            . "REVIEW:\n"
            . "<isi review lengkap di sini>";
    }

    /**
     * Compose the user message with the movie metadata produced by
     * FilmKnowledgeService::formatForAi().
     *
     * @param  array<string, mixed>  $ctx
     */
    protected function buildUserPrompt(array $ctx): string
    {
        $lines = [
            'Tulis review untuk film berikut:',
            '',
            'JUDUL          : ' . ($ctx['title'] ?? '-'),
        ];

        if (!empty($ctx['original_title'])) {
            $lines[] = 'JUDUL ASLI     : ' . $ctx['original_title'];
        }

        $lines[] = 'TAHUN          : ' . ($ctx['year'] ?? '-');
        $lines[] = 'GENRE          : ' . (!empty($ctx['genres']) ? implode(', ', $ctx['genres']) : '-');
        $lines[] = 'RATING PENONTON: ' . ($ctx['rating'] !== null ? $ctx['rating'] . '/10' : '-');
        $lines[] = 'POPULARITAS    : ' . ($ctx['popularity'] ?? '-');

        if (!empty($ctx['cast'])) {
            $castLines = array_map(
                fn ($c) => '  - ' . $c['name'] . (!empty($c['as']) ? ' sebagai ' . $c['as'] : ''),
                $ctx['cast']
            );
            $lines[] = 'PEMERAN UTAMA  :';
            $lines = array_merge($lines, $castLines);
        }

        $lines[] = '';
        $lines[] = 'SINOPSIS:';
        $lines[] = $ctx['overview'] ?? '(tidak tersedia)';

        return implode("\n", $lines);
    }

    /**
     * Pull TITLE / RATING / REVIEW out of the model's response.
     * Falls back gracefully when the model ignores the contract.
     *
     * @return array{0: string, 1: string, 2: float|null}  [title, body, rating]
     */
    protected function parseReview(string $raw, Movie $movie, string $perspective): array
    {
        $title  = null;
        $rating = null;
        $body   = $raw;

        if (preg_match('/^\s*TITLE\s*:\s*(.+?)\s*$/mi', $raw, $m)) {
            $title = trim($m[1]);
        }

        if (preg_match('/^\s*RATING\s*:\s*([0-9]+(?:[.,][0-9]+)?|N\/A|n\/a|-)/mi', $raw, $m)) {
            $candidate = str_replace(',', '.', trim($m[1]));
            if (is_numeric($candidate)) {
                $rating = max(0.0, min(10.0, round((float) $candidate, 1)));
            }
        }

        if (preg_match('/^\s*REVIEW\s*:\s*\n?(.+)$/sim', $raw, $m)) {
            $body = trim($m[1]);
        } else {
            // No contract — strip any leftover TITLE/RATING lines we did parse.
            $body = preg_replace('/^\s*(TITLE|RATING)\s*:.*$/mi', '', $raw);
            $body = trim((string) $body);
        }

        if ($title === null || $title === '') {
            $label = MovieAiReview::PERSPECTIVE_LABELS[$perspective] ?? ucfirst($perspective);
            $title = "Review {$label}: " . $movie->title;
        }

        // Hard cap title to fit the string column comfortably.
        if (mb_strlen($title) > 180) {
            $title = mb_substr($title, 0, 177) . '...';
        }

        return [$title, $body, $rating];
    }
}
