<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auto-tag a movie with AI-derived taxonomy:
 *   { mood:[], era:"YYYYs", themes:[], audience:[], intensity:"low|medium|high" }
 *
 * Persists to movies.ai_tags (JSON) + movies.ai_tagged_at.
 *
 * Errors are logged and surface as an empty array — never throws — so callers
 * (queued jobs, batch commands) can keep iterating across the catalog.
 */
class MovieTagger
{
    /**
     * Allowed values for the `intensity` field. Anything else is coerced to null.
     */
    protected const INTENSITY_VALUES = ['low', 'medium', 'high'];

    /**
     * Strict-JSON system prompt sent on every call.
     */
    protected const SYSTEM_PROMPT = 'You are a film classifier. Output strict JSON only — no prose, no markdown fences. '
        . 'Schema: {"mood":[...],"era":"1970s","themes":[...],"audience":[...],"intensity":"low|medium|high"}. '
        . 'mood: 2-5 short adjectives (e.g. "tense","melancholic","uplifting"). '
        . 'era: decade string like "1970s","1980s","2020s" derived from release year. '
        . 'themes: 2-6 short noun phrases (e.g. "found family","corporate greed","coming of age"). '
        . 'audience: array from ["kids","family","teens","adults","mature"]. '
        . 'intensity: exactly one of low|medium|high. '
        . 'Keep all strings lowercase, ASCII, under 40 chars. Return ONLY the JSON object.';

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Tag the given movie. Returns the parsed tag array (possibly empty on failure).
     *
     * @return array{mood?:array<int,string>,era?:string|null,themes?:array<int,string>,audience?:array<int,string>,intensity?:string|null}
     */
    public function tag(Movie $movie): array
    {
        try {
            $userPrompt = $this->buildUserPrompt($movie);

            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: [
                    'max_tokens' => 400,
                    'temperature' => 0.4,
                ],
                taskType: 'tag.movie',
                subject: $movie,
            );

            $tags = $this->parseAndValidate($response['content'] ?? '');

            if (empty($tags)) {
                Log::warning('MovieTagger: empty/invalid tags from AI', [
                    'movie_id' => $movie->id,
                    'slug' => $movie->slug,
                    'raw' => Str::limit((string) ($response['content'] ?? ''), 300),
                ]);

                return [];
            }

            // Persist without firing model events (avoid slug regeneration etc.)
            Movie::query()
                ->whereKey($movie->id)
                ->update([
                    'ai_tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                    'ai_tagged_at' => now(),
                ]);

            // Sync in-memory model so callers see fresh values.
            $movie->setAttribute('ai_tags', $tags);
            $movie->setAttribute('ai_tagged_at', now());

            return $tags;
        } catch (\Throwable $e) {
            Log::error('MovieTagger: failed', [
                'movie_id' => $movie->id ?? null,
                'slug' => $movie->slug ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the user message containing the film's metadata.
     */
    protected function buildUserPrompt(Movie $movie): string
    {
        $genres = $movie->relationLoaded('genres')
            ? $movie->genres->pluck('name')->toArray()
            : $movie->genres()->pluck('name')->toArray();

        $year = null;
        if ($movie->release_date) {
            $year = $movie->release_date instanceof \DateTimeInterface
                ? (int) $movie->release_date->format('Y')
                : (int) substr((string) $movie->release_date, 0, 4);
        }

        $payload = [
            'title' => (string) $movie->title,
            'original_title' => $movie->original_title ?: null,
            'release_year' => $year,
            'overview' => Str::limit((string) ($movie->overview ?? ''), 800, ''),
            'genres' => array_values(array_filter($genres)),
        ];

        return "Classify this film. Return only the JSON object specified by the system prompt.\n\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Parse and validate the AI response into a clean tag array.
     * Returns [] if the JSON is unrecoverable.
     *
     * @return array<string,mixed>
     */
    protected function parseAndValidate(string $raw): array
    {
        $json = $this->extractJson($raw);
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $clean = [
            'mood' => $this->cleanStringList($decoded['mood'] ?? [], 8),
            'era' => $this->cleanEra($decoded['era'] ?? null),
            'themes' => $this->cleanStringList($decoded['themes'] ?? [], 8),
            'audience' => $this->cleanStringList($decoded['audience'] ?? [], 6),
            'intensity' => $this->cleanIntensity($decoded['intensity'] ?? null),
        ];

        // If everything came back empty, treat as failure.
        $hasContent = !empty($clean['mood'])
            || !empty($clean['themes'])
            || !empty($clean['audience'])
            || $clean['era'] !== null
            || $clean['intensity'] !== null;

        return $hasContent ? $clean : [];
    }

    /**
     * Strip markdown fences and locate the first {...} block in the AI response.
     */
    protected function extractJson(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Strip ```json ... ``` or ``` ... ``` fences
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        // Find the outermost JSON object
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    /**
     * Normalize a list of short strings: trim, lowercase, dedupe, length-cap.
     *
     * @return array<int,string>
     */
    protected function cleanStringList(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $s = trim(mb_strtolower((string) $item));
            if ($s === '' || mb_strlen($s) > 40) {
                continue;
            }
            $out[$s] = true; // dedupe
            if (count($out) >= $max) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * Coerce era to "YYYYs" form. Accepts "1970s", "1970", 1970, etc.
     */
    protected function cleanEra(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = trim((string) $value);
        if (preg_match('/(\d{4})/', $s, $m)) {
            $year = (int) $m[1];
            if ($year >= 1880 && $year <= 2100) {
                $decade = (int) (floor($year / 10) * 10);
                return $decade . 's';
            }
        }

        return null;
    }

    /**
     * Restrict intensity to {low, medium, high} or null.
     */
    protected function cleanIntensity(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $s = strtolower(trim($value));
        return in_array($s, self::INTENSITY_VALUES, true) ? $s : null;
    }
}
