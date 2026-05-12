<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * FamilyMovieNightRecommender (Feature N4)
 *
 * Pick films that work for a GROUP of viewers — aggregating their watch
 * histories into consensus genre/mood preferences and applying real-world
 * constraints (kid-safety, runtime, language, mood).
 *
 * Pipeline:
 *   1. Aggregate every supplied user's recent watch history → consensus
 *      genre & mood weights (intersection-favoured).
 *   2. Build a candidate pool from the catalog filtered by hard constraints
 *      (release year as age proxy, runtime, language, mood/ai_tags).
 *   3. Score candidates by consensus overlap + popularity.
 *   4. AI re-rank the top 15 → final 5; AI failure → trust local score.
 */
class FamilyMovieNightRecommender
{
    /**
     * Loose mood → genre slug fallback (mirrors MoodDiscoveryService).
     */
    protected const MOOD_GENRE_FALLBACK = [
        'nostalgic'     => ['drama', 'family', 'romance', 'music'],
        'happy'         => ['comedy', 'family', 'animation', 'music'],
        'melancholic'   => ['drama', 'romance', 'history'],
        'intense'       => ['action', 'thriller', 'crime'],
        'action-packed' => ['action', 'adventure', 'science-fiction'],
        'cozy'          => ['family', 'animation', 'comedy', 'romance'],
        'romantic'      => ['romance', 'drama'],
        'thrilling'     => ['thriller', 'mystery', 'crime'],
        'inspiring'     => ['drama', 'history', 'documentary', 'music', 'family'],
        'dark'          => ['horror', 'thriller', 'crime', 'mystery'],
        'lighthearted'  => ['comedy', 'family', 'animation', 'romance'],
        'mysterious'    => ['mystery', 'thriller', 'crime', 'science-fiction', 'fantasy'],
    ];

    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $kb,
    ) {}

    /**
     * Recommend films for a family movie night.
     *
     * @param  array<int, int>  $userIds      Viewers participating tonight.
     * @param  array{
     *     min_age?: int,
     *     duration_max_minutes?: int,
     *     language?: string,
     *     mood?: string
     * }|null  $constraints
     * @return Collection<int, Movie>  Top 5 recommendations.
     */
    public function recommend(array $userIds, ?array $constraints = []): Collection
    {
        $constraints = $constraints ?? [];
        $userIds = array_values(array_filter(array_unique(array_map('intval', $userIds))));

        if (empty($userIds)) {
            return $this->fallbackPopular(5, $constraints);
        }

        // 1. Aggregate consensus preferences.
        $consensus = $this->aggregatePreferences($userIds);

        // 2. Build candidate pool with constraint filters applied.
        $candidates = $this->candidatePool($consensus, $constraints, 60);

        if ($candidates->isEmpty()) {
            // Loosen filters: keep only mood/language hard filters off, keep age/duration.
            $candidates = $this->candidatePool($consensus, $constraints, 60, loose: true);
        }

        if ($candidates->isEmpty()) {
            return $this->fallbackPopular(5, $constraints);
        }

        // 3. Local scoring against consensus.
        $scored = $candidates->map(function (Movie $m) use ($consensus) {
            $m->_family_score = $this->scoreCandidate($m, $consensus);
            return $m;
        })->sortByDesc('_family_score')->values();

        // 4. AI re-rank the top 15 down to 5.
        $top = $scored->take(15);
        $reranked = $this->aiRerank($top, $consensus, $constraints, 5);

        if ($reranked !== null && $reranked->isNotEmpty()) {
            return $reranked->take(5)->values();
        }

        return $scored->take(5)->values();
    }

    // ───────────────────────────────────────────────────────────────────
    // PREFERENCE AGGREGATION
    // ───────────────────────────────────────────────────────────────────

    /**
     * Aggregate genre + mood preferences across all viewers.
     *
     * @return array{genres: array<string,float>, moods: array<string,float>, viewer_count: int}
     */
    protected function aggregatePreferences(array $userIds): array
    {
        $genreScore = [];
        $moodScore  = [];
        $hasAiTags  = $this->aiTagsColumnExists();

        foreach ($userIds as $uid) {
            $perUserGenres = [];
            $perUserMoods  = [];

            $recent = WatchHistory::with('movie.genres')
                ->where('user_id', $uid)
                ->orderByDesc('last_watched_at')
                ->limit(30)
                ->get();

            foreach ($recent as $i => $h) {
                if (!$h->movie) continue;
                $weight = max(0.2, 1.0 - ($i / 30.0));

                foreach ($h->movie->genres as $g) {
                    $key = mb_strtolower($g->slug ?? $g->name);
                    $perUserGenres[$key] = ($perUserGenres[$key] ?? 0.0) + $weight;
                }

                if ($hasAiTags && is_array($h->movie->ai_tags ?? null)) {
                    foreach ($h->movie->ai_tags as $tag) {
                        if (!is_string($tag)) continue;
                        $key = mb_strtolower(trim($tag));
                        if ($key === '') continue;
                        $perUserMoods[$key] = ($perUserMoods[$key] ?? 0.0) + $weight;
                    }
                }
            }

            // Merge: intersection (genres present for multiple viewers get bonus).
            foreach ($perUserGenres as $g => $w) {
                $genreScore[$g] = ($genreScore[$g] ?? 0.0) + $w;
            }
            foreach ($perUserMoods as $m => $w) {
                $moodScore[$m] = ($moodScore[$m] ?? 0.0) + $w;
            }
        }

        arsort($genreScore);
        arsort($moodScore);

        return [
            'genres'       => array_slice($genreScore, 0, 10, true),
            'moods'        => array_slice($moodScore, 0, 8, true),
            'viewer_count' => count($userIds),
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    // CANDIDATE POOL
    // ───────────────────────────────────────────────────────────────────

    /**
     * Build a candidate pool from the catalog respecting hard constraints.
     *
     * @return Collection<int, Movie>
     */
    protected function candidatePool(array $consensus, array $constraints, int $limit, bool $loose = false): Collection
    {
        $query = Movie::with('genres');

        // (a) min_age — proxy: if age <= 12, restrict to family/animation/comedy/adventure
        //                       if age <= 16, exclude horror.
        $minAge = isset($constraints['min_age']) ? (int) $constraints['min_age'] : null;
        if ($minAge !== null && !$loose) {
            if ($minAge <= 12) {
                $query->whereHas('genres', fn ($q) =>
                    $q->whereIn('slug', ['family', 'animation', 'comedy', 'adventure', 'fantasy', 'music'])
                );
            } elseif ($minAge <= 16) {
                $query->whereDoesntHave('genres', fn ($q) =>
                    $q->whereIn('slug', ['horror'])
                );
            }
        }

        // (b) duration_max_minutes — runtime cap. Field is `runtime_minutes` if present,
        //     else fall back to `runtime`. Apply only if we know the column exists.
        $maxDuration = isset($constraints['duration_max_minutes']) ? (int) $constraints['duration_max_minutes'] : null;
        if ($maxDuration !== null && $maxDuration > 0) {
            $runtimeColumn = $this->runtimeColumnName();
            if ($runtimeColumn) {
                $query->where(function ($q) use ($runtimeColumn, $maxDuration) {
                    $q->whereNull($runtimeColumn)
                      ->orWhere($runtimeColumn, '<=', $maxDuration);
                });
            }
        }

        // (c) language filter (movies.original_language = 'id'/'en'/...)
        $language = $constraints['language'] ?? null;
        if (is_string($language) && $language !== '' && !$loose) {
            $langColumn = $this->languageColumnName();
            if ($langColumn) {
                $query->where($langColumn, $language);
            }
        }

        // (d) mood filter via ai_tags or genre fallback.
        $mood = $constraints['mood'] ?? null;
        if (is_string($mood) && $mood !== '' && !$loose) {
            $moodKey = mb_strtolower(trim($mood));

            if ($this->aiTagsColumnExists()) {
                $query->where(function ($q) use ($moodKey) {
                    $q->whereJsonContains('ai_tags', $moodKey);
                    if (isset(self::MOOD_GENRE_FALLBACK[$moodKey])) {
                        $q->orWhereHas('genres', fn ($qg) =>
                            $qg->whereIn('slug', self::MOOD_GENRE_FALLBACK[$moodKey])
                        );
                    }
                });
            } elseif (isset(self::MOOD_GENRE_FALLBACK[$moodKey])) {
                $query->whereHas('genres', fn ($qg) =>
                    $qg->whereIn('slug', self::MOOD_GENRE_FALLBACK[$moodKey])
                );
            }
        }

        // (e) Bias toward the consensus genres (if we have any).
        $consensusGenres = array_keys($consensus['genres']);
        if (!empty($consensusGenres)) {
            $query->whereHas('genres', fn ($qg) =>
                $qg->whereIn('slug', $consensusGenres)->orWhereIn('name', $consensusGenres)
            );
        }

        return $query
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    /**
     * Last-resort: just popular catalog films (still respect duration if possible).
     */
    protected function fallbackPopular(int $count, array $constraints = []): Collection
    {
        $query = Movie::with('genres');

        $maxDuration = isset($constraints['duration_max_minutes']) ? (int) $constraints['duration_max_minutes'] : null;
        if ($maxDuration !== null && $maxDuration > 0) {
            $runtimeColumn = $this->runtimeColumnName();
            if ($runtimeColumn) {
                $query->where(function ($q) use ($runtimeColumn, $maxDuration) {
                    $q->whereNull($runtimeColumn)->orWhere($runtimeColumn, '<=', $maxDuration);
                });
            }
        }

        return $query->orderByDesc('popularity')->limit($count)->get();
    }

    // ───────────────────────────────────────────────────────────────────
    // SCORING
    // ───────────────────────────────────────────────────────────────────

    protected function scoreCandidate(Movie $movie, array $consensus): float
    {
        $score = 0.0;

        $movieGenreKeys = $movie->genres
            ->map(fn ($g) => mb_strtolower($g->slug ?? $g->name))
            ->all();

        foreach ($movieGenreKeys as $key) {
            if (isset($consensus['genres'][$key])) {
                $score += $consensus['genres'][$key] * 2.0;
            }
        }

        if (is_array($movie->ai_tags ?? null) && !empty($consensus['moods'])) {
            foreach ($movie->ai_tags as $tag) {
                if (!is_string($tag)) continue;
                $key = mb_strtolower(trim($tag));
                if (isset($consensus['moods'][$key])) {
                    $score += $consensus['moods'][$key] * 1.5;
                }
            }
        }

        // Family-friendly bonus (helps when viewers include kids).
        $familyGenres = ['family', 'animation', 'comedy', 'adventure'];
        if (count(array_intersect($familyGenres, $movieGenreKeys)) > 0) {
            $score += 0.6;
        }

        // Popularity & rating modest bonuses.
        $score += min(2.0, ((float) $movie->popularity) / 100.0);
        $score += ((float) $movie->vote_average) / 10.0 * 0.8;

        return $score;
    }

    // ───────────────────────────────────────────────────────────────────
    // AI RE-RANK
    // ───────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Movie>  $candidates
     * @return Collection<int, Movie>|null
     */
    protected function aiRerank(Collection $candidates, array $consensus, array $constraints, int $count): ?Collection
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $payload = $candidates->map(fn (Movie $m) => [
            'slug'    => $m->slug,
            'title'   => $m->title,
            'year'    => $m->release_date?->format('Y'),
            'genres'  => $m->genres->pluck('name')->take(4)->values()->all(),
            'rating'  => $m->vote_average !== null ? round((float) $m->vote_average, 1) : null,
            'tags'    => is_array($m->ai_tags ?? null) ? array_slice($m->ai_tags, 0, 4) : [],
        ])->values()->all();

        $prefsLine  = 'genres: ' . (implode(', ', array_keys($consensus['genres'])) ?: 'no signal')
            . '; moods: ' . (implode(', ', array_keys($consensus['moods'])) ?: 'no signal')
            . '; viewers: ' . $consensus['viewer_count'];

        $constraintLines = [];
        if (isset($constraints['min_age']))              $constraintLines[] = 'youngest viewer age: ' . (int) $constraints['min_age'];
        if (isset($constraints['duration_max_minutes'])) $constraintLines[] = 'max runtime: ' . (int) $constraints['duration_max_minutes'] . ' min';
        if (!empty($constraints['language']))            $constraintLines[] = 'preferred language: ' . $constraints['language'];
        if (!empty($constraints['mood']))                $constraintLines[] = 'desired mood: ' . $constraints['mood'];
        $constraintsStr = empty($constraintLines) ? 'none' : implode('; ', $constraintLines);

        $userPrompt = "Pick {$count} films PERFECT for a family/group movie night.\n\n"
            . "Viewer preferences (aggregated): {$prefsLine}\n"
            . "Constraints: {$constraintsStr}\n\n"
            . "Films available:\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nReturn ONLY a JSON array of {$count} slugs in priority order. Use ONLY slugs from the input. No prose, no markdown.";

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => 'You are a family movie-night curator. Respond with strict JSON array of slugs only.'],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'max_tokens'  => 250,
                'temperature' => 0.5,
            ]);

            $content = trim((string) ($response['content'] ?? ''));
            if ($content === '') return null;

            $slugs = $this->extractSlugs($content);
            if (empty($slugs)) return null;

            $bySlug = $candidates->keyBy('slug');
            $ordered = collect();
            foreach ($slugs as $slug) {
                if ($bySlug->has($slug)) {
                    $ordered->push($bySlug->get($slug));
                }
                if ($ordered->count() >= $count) break;
            }

            return $ordered->isNotEmpty() ? $ordered : null;
        } catch (\Throwable $e) {
            Log::warning('FamilyMovieNightRecommender: AI re-rank failed', [
                'viewer_count' => $consensus['viewer_count'] ?? 0,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // SCHEMA PROBES
    // ───────────────────────────────────────────────────────────────────

    /**
     * Pull a JSON array of slugs from AI output.
     *
     * @return list<string>
     */
    protected function extractSlugs(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/im', '', $content) ?? $content;
        $content = trim($content);

        if (!str_starts_with($content, '[')) {
            if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return [];

        return array_values(array_filter(
            array_map(fn ($s) => is_string($s) ? trim($s) : null, $decoded),
            fn ($s) => is_string($s) && $s !== '',
        ));
    }

    protected function aiTagsColumnExists(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $cached = Schema::hasColumn('movies', 'ai_tags');
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Resolve which runtime column is present in the movies table (varies between
     * the base schema and the distribution-extension migration).
     */
    protected function runtimeColumnName(): ?string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            foreach (['runtime_minutes', 'runtime', 'duration_minutes', 'duration'] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    return $cached = $col;
                }
            }
        } catch (\Throwable) {
            // ignore
        }
        return $cached = null;
    }

    /**
     * Resolve which language column is present.
     */
    protected function languageColumnName(): ?string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            foreach (['original_language', 'language'] as $col) {
                if (Schema::hasColumn('movies', $col)) {
                    return $cached = $col;
                }
            }
        } catch (\Throwable) {
            // ignore
        }
        return $cached = null;
    }
}
