<?php

namespace App\Services\Ai\Search;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * K5 — Decade-style Search.
 *
 * Accepts a free-form Indonesian/English "vibe" phrase (e.g. "film 70an seperti The Godfather",
 * "film 90an romance"), uses AI to extract:
 *   - decade           (e.g. 1970, 1990, 2020)  — release-year filter
 *   - style descriptors (genres + ai_tags)       — content filter
 *   - reference titles (optional)                — boost films matching genre/cast overlap
 *
 * Then queries the catalog by decade + style overlap, ranked by overlap × popularity.
 *
 * Degrades gracefully: when the AI is unavailable, falls back to keyword heuristics
 * over the input string.
 */
class DecadeStyleSearchService
{
    public function __construct(protected AiClient $ai)
    {
    }

    /**
     * Run a vibe-based search.
     *
     * @param  string       $vibe   Free-text description of what the user wants.
     * @param  string|null  $era    Optional override (decade as string e.g. "1970", "90s").
     * @param  int          $count  Max results.
     * @return Collection<int, Movie>
     */
    public function searchByVibe(string $vibe, ?string $era = null, int $count = 12): Collection
    {
        $vibe = trim($vibe);
        if ($vibe === '' && ($era === null || trim($era) === '')) {
            return collect();
        }

        $extracted = $this->extractVibe($vibe);

        // CLI-supplied era wins over AI guess.
        if ($era !== null && trim($era) !== '') {
            $decade = $this->normalizeDecade($era);
            if ($decade !== null) {
                $extracted['decade'] = $decade;
            }
        }

        return $this->queryCatalog($extracted, $count);
    }

    /**
     * Use AI to pull structured fields out of the user's vibe text.
     * Falls back to a keyword heuristic on any failure.
     *
     * @return array{decade: int|null, styles: list<string>, genres: list<string>, references: list<string>}
     */
    protected function extractVibe(string $vibe): array
    {
        $system = "You analyse user requests for films from a specific era + style.\n"
            . "Extract: (a) the decade as a 4-digit year (1970, 1980, 1990, 2000, 2010, 2020) — null if none mentioned;\n"
            . "(b) style descriptors as lowercase tags (e.g. \"romance\", \"action\", \"noir\", \"epic\", \"slow-burn\");\n"
            . "(c) genre names in English (e.g. \"Drama\", \"Romance\");\n"
            . "(d) any reference film titles the user mentions (e.g. \"The Godfather\").\n\n"
            . "Respond ONLY with a JSON object of this shape (no prose, no markdown):\n"
            . '{"decade": 1970, "styles": ["epic","crime"], "genres": ["Drama","Crime"], "references": ["The Godfather"]}';

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $vibe],
                ],
                options: [
                    'max_tokens' => 200,
                    'temperature' => 0.2,
                ],
            );

            $parsed = $this->parseVibeJson((string) ($response['content'] ?? ''));
            if ($parsed !== null) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            Log::warning('DecadeStyleSearchService: AI call failed', [
                'vibe' => $vibe,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->heuristicVibe($vibe);
    }

    /**
     * @return array{decade: int|null, styles: list<string>, genres: list<string>, references: list<string>}|null
     */
    protected function parseVibeJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'decade' => $this->normalizeDecade($decoded['decade'] ?? null),
            'styles' => $this->stringList($decoded['styles'] ?? []),
            'genres' => $this->stringList($decoded['genres'] ?? []),
            'references' => $this->stringList($decoded['references'] ?? []),
        ];
    }

    /**
     * Cheap heuristic when AI is unavailable.
     *
     * @return array{decade: int|null, styles: list<string>, genres: list<string>, references: list<string>}
     */
    protected function heuristicVibe(string $vibe): array
    {
        $needle = mb_strtolower($vibe);

        // Decade detection — match "70an", "1970s", "90s", "2000-an", etc.
        $decade = null;
        if (preg_match('/(19|20)?(\d0)\s*-?(?:an|s|en)?\b/u', $needle, $m)) {
            $tens = (int) $m[2];
            $century = $m[1] !== '' ? (int) $m[1] : ($tens >= 30 ? 19 : 20);
            $decade = $century * 100 + $tens;
        }

        $genreKeywords = [
            'Romance' => ['romance', 'romantic', 'romantis', 'cinta'],
            'Action' => ['action', 'aksi', 'laga', 'fight'],
            'Drama' => ['drama'],
            'Comedy' => ['comedy', 'komedi', 'lucu'],
            'Horror' => ['horror', 'horor', 'seram'],
            'Thriller' => ['thriller', 'menegangkan'],
            'Crime' => ['crime', 'kriminal', 'mafia'],
            'Science Fiction' => ['sci-fi', 'science fiction', 'fiksi ilmiah'],
            'Fantasy' => ['fantasy', 'fantasi'],
            'Animation' => ['animation', 'animasi', 'kartun'],
            'Documentary' => ['documentary', 'dokumenter'],
        ];

        $genres = [];
        foreach ($genreKeywords as $genre => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($needle, $kw)) {
                    $genres[] = $genre;
                    break;
                }
            }
        }

        // Pull tokens that look like proper nouns after "seperti" / "like" as references.
        $references = [];
        if (preg_match('/(?:seperti|like|kayak)\s+(.+?)(?:[\.\?\!,]|$)/iu', $vibe, $m)) {
            $references[] = trim($m[1]);
        }

        return [
            'decade' => $decade,
            'styles' => [],
            'genres' => array_values(array_unique($genres)),
            'references' => array_values(array_filter($references, fn ($r) => $r !== '')),
        ];
    }

    /**
     * Build and execute the actual catalog query.
     *
     * @param  array{decade: int|null, styles: list<string>, genres: list<string>, references: list<string>}  $extracted
     * @return Collection<int, Movie>
     */
    protected function queryCatalog(array $extracted, int $count): Collection
    {
        $query = Movie::query()->with('genres');

        $decade = $extracted['decade'];
        if ($decade !== null) {
            $query->whereNotNull('release_date')
                  ->whereYear('release_date', '>=', $decade)
                  ->whereYear('release_date', '<=', $decade + 9);
        }

        $genres = $extracted['genres'];
        $styles = $extracted['styles'];
        $hasAiTags = $this->aiTagsColumnExists();

        if (!empty($genres) || (!empty($styles) && $hasAiTags)) {
            $query->where(function ($q) use ($genres, $styles, $hasAiTags) {
                if (!empty($genres)) {
                    $q->orWhereHas('genres', function ($qg) use ($genres) {
                        $qg->where(function ($inner) use ($genres) {
                            foreach ($genres as $g) {
                                $inner->orWhere('name', 'LIKE', "%{$g}%");
                            }
                        });
                    });
                }
                if (!empty($styles) && $hasAiTags) {
                    $q->orWhere(function ($qj) use ($styles) {
                        $qj->whereNotNull('ai_tags');
                        $qj->where(function ($inner) use ($styles) {
                            foreach ($styles as $s) {
                                $inner->orWhereJsonContains('ai_tags', mb_strtolower($s));
                            }
                        });
                    });
                }
            });
        }

        // Overfetch for reranking when we have signals to sort by.
        $rows = $query->orderByDesc('popularity')
            ->limit(max($count * 3, $count))
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        // Re-rank: genre/style overlap + popularity.
        $genresLower = array_map('mb_strtolower', $genres);
        $stylesLower = array_map('mb_strtolower', $styles);

        $scored = $rows->map(function (Movie $m) use ($genresLower, $stylesLower) {
            $score = 0.0;

            $movieGenres = $m->genres->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
            foreach ($genresLower as $g) {
                foreach ($movieGenres as $mg) {
                    if (str_contains($mg, $g) || str_contains($g, $mg)) {
                        $score += 3;
                        break;
                    }
                }
            }

            $tags = is_array($m->ai_tags ?? null) ? array_map('mb_strtolower', $m->ai_tags) : [];
            foreach ($stylesLower as $s) {
                if (in_array($s, $tags, true)) {
                    $score += 2;
                }
            }

            $score += ((float) $m->popularity) / 200.0;
            $score += ((float) $m->vote_average) / 10.0;

            $m->setAttribute('_relevance', $score);
            return $m;
        });

        return $scored->sortByDesc('_relevance')->take($count)->values();
    }

    /**
     * Normalise messy decade input ("70", "70s", "1970", "70an", 1995) → 1970.
     */
    protected function normalizeDecade(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $year = $value;
        } elseif (is_string($value) && preg_match('/(19|20)?(\d{1,2})/', $value, $m)) {
            $tens = (int) $m[2];
            // 2-digit form: 70 → 1970, 20 → 2020 (heuristic split at >= 30)
            if (mb_strlen($m[2]) === 1) {
                $tens *= 10;
            }
            if ($tens > 9) {
                $tens = ((int) ($tens / 10)) * 10;
            }
            $century = $m[1] !== '' ? (int) $m[1] : ($tens >= 30 ? 19 : 20);
            $year = $century * 100 + $tens;
        } else {
            return null;
        }

        // Floor to decade.
        return (int) (floor($year / 10) * 10);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Cached probe for the optional `ai_tags` column.
     */
    protected function aiTagsColumnExists(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cached = Schema::hasColumn('movies', 'ai_tags');
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }
}
