<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * MoodDiscoveryService — convert a free-form Indonesian/English mood phrase
 * (e.g. "lagi sedih", "mau yang seru-seruan") into a curated film selection.
 *
 * Pipeline:
 *   1. AI call → JSON array of mood tags drawn from a closed vocabulary.
 *   2. Query `movies.ai_tags` (nullable JSON) for any-tag overlap.
 *   3. If catalog has no ai_tags populated yet, fall back to a curated
 *      mood→genre-slug heuristic and query by genre.
 *
 * Returns a Collection<Movie> ordered by popularity desc, capped at $count.
 */
class MoodDiscoveryService
{
    /**
     * Closed vocabulary of mood tags the AI must choose from.
     * Keep in sync with the user-facing prompt below.
     */
    public const MOOD_VOCABULARY = [
        'nostalgic',
        'happy',
        'melancholic',
        'intense',
        'action-packed',
        'cozy',
        'romantic',
        'thrilling',
        'inspiring',
        'dark',
        'lighthearted',
        'mysterious',
    ];

    /**
     * Fallback mapping: mood tag → list of genre slugs.
     * Used when no movie row has ai_tags populated.
     * Genre slugs match `database/seeders/GenreSeeder.php`.
     */
    protected const MOOD_GENRE_FALLBACK = [
        'nostalgic'     => ['drama', 'family', 'romance', 'music'],
        'happy'         => ['comedy', 'family', 'animation', 'music'],
        'melancholic'   => ['drama', 'romance', 'history'],
        'intense'       => ['action', 'thriller', 'horror', 'crime'],
        'action-packed' => ['action', 'adventure', 'science-fiction'],
        'cozy'          => ['family', 'animation', 'comedy', 'romance'],
        'romantic'      => ['romance', 'drama'],
        'thrilling'     => ['thriller', 'mystery', 'crime', 'horror'],
        'inspiring'     => ['drama', 'history', 'documentary', 'music', 'family'],
        'dark'          => ['horror', 'thriller', 'crime', 'mystery'],
        'lighthearted'  => ['comedy', 'family', 'animation', 'romance'],
        'mysterious'    => ['mystery', 'thriller', 'crime', 'science-fiction', 'fantasy'],
    ];

    public function __construct(protected AiClient $ai)
    {
    }

    /**
     * Recommend films from a free-text mood input.
     *
     * @param  string  $moodInput   e.g. "lagi sedih", "mau yang seru-seruan"
     * @param  int     $count       max films to return (default 8)
     * @return Collection<int, Movie>
     */
    public function recommend(string $moodInput, int $count = 8): Collection
    {
        $moodInput = trim($moodInput);
        if ($moodInput === '') {
            return $this->fallbackPopular($count);
        }

        // Step 1: derive mood tags via AI (with safe fallback to heuristic).
        $tags = $this->extractMoodTags($moodInput);

        if (empty($tags)) {
            return $this->fallbackPopular($count);
        }

        // Step 2: try ai_tags JSON match first.
        $byTags = $this->queryByAiTags($tags, $count);
        if ($byTags->isNotEmpty()) {
            return $byTags;
        }

        // Step 3: fall back to genre-based heuristic.
        return $this->queryByGenreFallback($tags, $count);
    }

    /**
     * Call the AI to convert free-form mood input into a JSON array of mood tags
     * drawn from the closed vocabulary. Returns an empty array on failure so the
     * caller can fall back gracefully.
     *
     * @return list<string>
     */
    protected function extractMoodTags(string $moodInput): array
    {
        $vocab = implode(', ', self::MOOD_VOCABULARY);

        $system = "You are a film-mood classifier. Convert the user's mood description into 3-5 mood tags chosen ONLY from this list: {$vocab}. "
            . "Respond ONLY with a JSON array of lowercase strings — no prose, no markdown, no code fences. "
            . "Example: [\"melancholic\",\"cozy\",\"nostalgic\"]";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $moodInput],
                ],
                options: [
                    'max_tokens'  => 80,
                    'temperature' => 0.3,
                ],
            );

            $tags = $this->parseTagsJson($response['content'] ?? '');
            if (!empty($tags)) {
                return $tags;
            }
        } catch (\Throwable $e) {
            Log::warning('MoodDiscoveryService: AI call failed, using heuristic', [
                'input' => $moodInput,
                'error' => $e->getMessage(),
            ]);
        }

        // Heuristic fallback when AI is unavailable or returns garbage.
        return $this->heuristicMoodTags($moodInput);
    }

    /**
     * Parse the AI response (expected: bare JSON array) into a sanitized tag list.
     * Tolerates code fences and stray prose.
     *
     * @return list<string>
     */
    protected function parseTagsJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip code fences if model added them.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        // Extract the first [...] block if there is surrounding text.
        if (preg_match('/\[[^\[\]]*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowed = array_flip(self::MOOD_VOCABULARY);
        $tags = [];
        foreach ($decoded as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $clean = strtolower(trim($tag));
            if (isset($allowed[$clean]) && !in_array($clean, $tags, true)) {
                $tags[] = $clean;
            }
        }

        return array_slice($tags, 0, 5);
    }

    /**
     * Last-resort keyword heuristic when the AI is unavailable.
     * Maps common Indonesian/English mood words to vocabulary tags.
     *
     * @return list<string>
     */
    protected function heuristicMoodTags(string $input): array
    {
        $needle = mb_strtolower($input);

        $rules = [
            'nostalgic'     => ['nostalgia', 'kenangan', 'jadul', 'masa kecil', 'old school'],
            'happy'         => ['senang', 'gembira', 'happy', 'ceria', 'bahagia'],
            'melancholic'   => ['sedih', 'galau', 'patah hati', 'baper', 'sad', 'melancholy', 'mellow'],
            'intense'       => ['intens', 'tegang', 'menegangkan', 'intense', 'serius'],
            'action-packed' => ['seru', 'aksi', 'action', 'laga', 'tembak', 'fight'],
            'cozy'          => ['santai', 'cozy', 'rileks', 'tidur', 'sebelum tidur', 'chill', 'rebahan'],
            'romantic'      => ['romantis', 'romance', 'cinta', 'pacaran', 'kencan', 'date'],
            'thrilling'     => ['thrilling', 'thriller', 'menegangkan', 'jantung'],
            'inspiring'     => ['inspirasi', 'inspiratif', 'motivasi', 'semangat', 'inspiring'],
            'dark'          => ['gelap', 'dark', 'kelam', 'suram', 'noir', 'horror', 'horor'],
            'lighthearted'  => ['ringan', 'lucu', 'kocak', 'komedi', 'comedy', 'funny', 'humor'],
            'mysterious'    => ['misteri', 'mystery', 'misterius', 'teka-teki'],
        ];

        $hits = [];
        foreach ($rules as $tag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($needle, $kw)) {
                    $hits[] = $tag;
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($hits)), 0, 5);
    }

    /**
     * Query Movie rows whose `ai_tags` JSON column contains ANY of the given tags.
     * Returns an empty collection if no rows match (so the caller can fall back).
     *
     * @param  list<string>  $tags
     * @return Collection<int, Movie>
     */
    protected function queryByAiTags(array $tags, int $count): Collection
    {
        if (empty($tags)) {
            return collect();
        }

        // `ai_tags` is a nullable JSON column (added by another agent).
        // Guard with hasColumn so we don't fatal if migration hasn't landed yet.
        if (!$this->aiTagsColumnExists()) {
            return collect();
        }

        $query = Movie::query()
            ->with('genres')
            ->whereNotNull('ai_tags');

        $query->where(function ($q) use ($tags) {
            foreach ($tags as $tag) {
                // whereJsonContains works for both MySQL and Postgres JSON columns.
                $q->orWhereJsonContains('ai_tags', $tag);
            }
        });

        return $query
            ->orderByDesc('popularity')
            ->limit($count)
            ->get();
    }

    /**
     * Fallback: map mood tags to genre slugs and query by genre.
     *
     * @param  list<string>  $tags
     * @return Collection<int, Movie>
     */
    protected function queryByGenreFallback(array $tags, int $count): Collection
    {
        $genreSlugs = [];
        foreach ($tags as $tag) {
            foreach (self::MOOD_GENRE_FALLBACK[$tag] ?? [] as $slug) {
                $genreSlugs[$slug] = true;
            }
        }

        $genreSlugs = array_keys($genreSlugs);

        if (empty($genreSlugs)) {
            return $this->fallbackPopular($count);
        }

        return Movie::query()
            ->with('genres')
            ->whereHas('genres', fn ($q) => $q->whereIn('slug', $genreSlugs))
            ->orderByDesc('popularity')
            ->limit($count)
            ->get();
    }

    /**
     * Absolute last resort: just return the most popular films.
     *
     * @return Collection<int, Movie>
     */
    protected function fallbackPopular(int $count): Collection
    {
        return Movie::query()
            ->with('genres')
            ->orderByDesc('popularity')
            ->limit($count)
            ->get();
    }

    /**
     * Cached probe for the optional `ai_tags` column. Returns false until the
     * sibling agent's migration runs, so this service degrades cleanly.
     */
    protected function aiTagsColumnExists(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cached = \Illuminate\Support\Facades\Schema::hasColumn('movies', 'ai_tags');
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }
}
