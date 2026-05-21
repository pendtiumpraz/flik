<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\UserPreference;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Cold-start recommender.
 *
 * Builds a candidate pool by overlapping the user's onboarding answers
 * (genres + decade eras) against the catalog, weights by popularity/rating,
 * and (optionally) lets the configured AiClient re-rank the top 30
 * candidates into a final ordered list.
 *
 * If AI re-ranking is unavailable or fails, falls back to the local score.
 */
class ColdStartRecommender
{
    public function __construct(
        protected ?AiClient $ai = null,
    ) {}

    /**
     * Recommend films for a freshly-onboarded user.
     *
     * @return Collection<int, Movie>
     */
    public function recommendForNewUser(UserPreference $prefs, int $count = 12): Collection
    {
        $genres = $this->asStringArray($prefs->favorite_genres);
        $eras   = $this->asStringArray($prefs->favorite_eras);

        // 1. Build candidate query.
        $query = Movie::with('genres')->where(function ($q) use ($genres, $eras) {
            $hasFilter = false;

            if (!empty($genres)) {
                $hasFilter = true;
                $q->whereHas('genres', function ($qg) use ($genres) {
                    $qg->whereIn('genres.slug', $genres)
                       ->orWhereIn('genres.name', $genres);
                });
            }

            if (!empty($eras)) {
                $hasFilter = true;
                $q->orWhere(function ($qe) use ($eras) {
                    foreach ($eras as $era) {
                        [$start, $end] = $this->eraRange($era);
                        if ($start === null) continue;
                        $qe->orWhereBetween('release_date', [$start, $end]);
                    }
                });
            }

            // If user gave no signals at all, this $q is empty → returns ALL.
            if (!$hasFilter) {
                $q->whereNotNull('id');
            }
        });

        // Overfetch — we need a pool to re-rank from.
        $candidates = $query
            ->orderByDesc('popularity')
            ->limit(80)
            ->get();

        // 2. Local scoring.
        $scored = $candidates->map(function (Movie $m) use ($genres, $eras) {
            $score = 0.0;

            // Genre overlap (×4 per match)
            $movieGenres = $m->genres->pluck('slug')
                ->merge($m->genres->pluck('name'))
                ->map(fn ($g) => mb_strtolower((string) $g))
                ->all();
            $genreLower = array_map(fn ($g) => mb_strtolower((string) $g), $genres);
            $genreOverlap = count(array_intersect($genreLower, $movieGenres));
            $score += $genreOverlap * 4.0;

            // Era overlap (×3)
            $year = $m->release_date?->format('Y');
            if ($year && !empty($eras)) {
                foreach ($eras as $era) {
                    [$start, $end] = $this->eraRange($era);
                    if ($start === null) continue;
                    if ((int) $year >= (int) substr($start, 0, 4) &&
                        (int) $year <= (int) substr($end, 0, 4)) {
                        $score += 3.0;
                        break;
                    }
                }
            }

            // Popularity bonus (capped, normalized).
            $score += min(2.5, ((float) $m->popularity) / 100.0);

            // Vote average bonus.
            $score += ((float) $m->vote_average) / 10.0 * 1.5;

            $m->_cs_score = $score;
            return $m;
        })
        ->sortByDesc('_cs_score')
        ->values();

        // 3. Try AI re-rank on top 30. If it returns slugs we recognise, honour them.
        $top30 = $scored->take(30);

        $reranked = $this->aiRerank($top30, $genres, $eras, $count);

        if ($reranked !== null && $reranked->isNotEmpty()) {
            return $reranked->take($count)->values();
        }

        // 4. Fallback: trust local scoring.
        return $scored->take($count)->values();
    }

    /**
     * Ask the AI to re-rank candidates by slug. Returns null on any failure.
     *
     * @param  Collection<int, Movie>  $candidates
     * @return Collection<int, Movie>|null
     */
    protected function aiRerank(Collection $candidates, array $genres, array $eras, int $count): ?Collection
    {
        if (!$this->ai || $candidates->isEmpty()) {
            return null;
        }

        // Compact payload for the model.
        $payload = $candidates->map(fn (Movie $m) => [
            'slug'  => $m->slug,
            'title' => $m->title,
            'year'  => $m->release_date?->format('Y'),
            'genres'=> $m->genres->pluck('name')->take(4)->values()->all(),
            'rating'=> $m->vote_average !== null ? round((float) $m->vote_average, 1) : null,
        ])->values()->all();

        $genresStr = implode(', ', $genres) ?: 'no specific genre';
        $erasStr   = implode(', ', $eras)   ?: 'any era';

        $userPrompt = "Pick {$count} best movies for a user who likes {$genresStr}, {$erasStr}. "
            . "Movies available: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ". "
            . "Output JSON array of {$count} slugs in priority order. "
            . "Respond with ONLY a JSON array of slug strings, no prose, no markdown.";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => 'You are a film curator. Respond with strict JSON only.'],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                options: [
                    'max_tokens'  => 400,
                    'temperature' => 0.4,
                ],
                taskType: 'recommend.cold_start',
            );

            $content = trim((string) ($response['content'] ?? ''));
            if ($content === '') return null;

            $slugs = $this->extractSlugs($content);
            if (empty($slugs)) return null;

            // Map slugs back to candidate movies, preserving AI order.
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
            Log::warning('ColdStartRecommender AI re-rank failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Pull a JSON array of slugs out of an AI response (tolerates code-fence wrapping).
     *
     * @return list<string>
     */
    protected function extractSlugs(string $content): array
    {
        // Strip ```json ... ``` fences if present.
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/im', '', $content) ?? $content;
        $content = trim($content);

        // If we got more than just an array, try to extract the first [...] block.
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

    /**
     * Convert a value that may be JSON string, array, or null into a list<string>.
     *
     * @return list<string>
     */
    protected function asStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) return [];

        return array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : null, $value),
            fn ($v) => is_string($v) && $v !== '',
        ));
    }

    /**
     * Map era label (e.g. "1950s", "2020s") → [start_date, end_date] for whereBetween.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function eraRange(string $era): array
    {
        $era = strtolower(trim($era));

        // Match "1950s", "50s", "60s", "2020s", etc.
        if (preg_match('/^(\d{2,4})s$/', $era, $m)) {
            $n = (int) $m[1];

            // Two-digit shorthand: 50s → 1950s, 20s → 2020s.
            if ($n < 100) {
                $n = $n >= 50 ? 1900 + $n : 2000 + $n;
            }

            $decadeStart = (int) (floor($n / 10) * 10);
            return [
                sprintf('%04d-01-01', $decadeStart),
                sprintf('%04d-12-31', $decadeStart + 9),
            ];
        }

        return [null, null];
    }
}
