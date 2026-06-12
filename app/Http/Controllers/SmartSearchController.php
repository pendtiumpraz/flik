<?php

namespace App\Http\Controllers;

use App\Models\Cast;
use App\Models\Genre;
use App\Models\Movie;
use App\Services\Ai\FilmKnowledgeService;
use App\Services\Ai\Search\DecadeStyleSearchService;
use App\Services\Ai\Search\DirectorActorSearchService;
use App\Services\Ai\Search\IntentClassifier;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Universal smart search front door.
 *
 * `search()`        — POSTs from the smart bar go GET here. Uses IntentClassifier to
 *                     route the query to the matching specialised service (title /
 *                     actor / director / vibe / year / genre), then renders all
 *                     results grouped by source on a single page.
 *
 * `autocomplete()`  — JSON endpoint for the smart bar typeahead. Lightweight,
 *                     synchronous LIKE search across `movies.title` + `casts.name`,
 *                     capped at 10 results per bucket. No AI calls.
 */
class SmartSearchController extends Controller
{
    public function __construct(
        protected IntentClassifier $classifier,
        protected DecadeStyleSearchService $decadeService,
        protected DirectorActorSearchService $personService,
        protected FilmKnowledgeService $knowledge,
    ) {}

    /**
     * Smart search results page.
     *
     * Query params:
     *   - q       (required, min 2 chars) — the free-form query.
     *   - intent  (optional)              — override the classifier ("title", "actor", ...).
     */
    public function search(Request $request): View|Factory
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:300'],
            'intent' => ['nullable', 'string', 'in:title,actor,director,vibe,year,genre,auto'],
        ]);

        $query = trim($data['q']);
        $override = $data['intent'] ?? 'auto';

        // Light per-user rate limiting — 30/min.
        if ($error = $this->rateLimit('smart-search')) {
            return view('search.smart', [
                'query' => $query,
                'intent' => 'title',
                'confidence' => 0.0,
                'normalized' => $query,
                'groups' => [],
                'totalCount' => 0,
                'genres' => $this->genreMap(),
                'error' => $error,
            ]);
        }

        if ($override !== 'auto' && $override !== null) {
            $classification = [
                'intent' => $override,
                'normalized_query' => $query,
                'confidence' => 1.0,
            ];
        } else {
            $classification = $this->classifier->classify($query);
        }

        $intent = $classification['intent'];
        $normalized = $classification['normalized_query'] !== '' ? $classification['normalized_query'] : $query;

        $groups = $this->dispatchIntent($intent, $normalized, $query);
        $totalCount = array_sum(array_map(fn ($g) => $g['movies']->count(), $groups));

        return view('search.smart', [
            'query' => $query,
            'intent' => $intent,
            'confidence' => $classification['confidence'],
            'normalized' => $normalized,
            'groups' => $groups,
            'totalCount' => $totalCount,
            'genres' => $this->genreMap(),
            'error' => null,
        ]);
    }

    /**
     * Autocomplete (typeahead) endpoint. Quick keyword LIKE against movies + casts.
     *
     * Returns JSON:
     *   {
     *     "movies": [{id, slug, title, year, poster_url, url}, ...],
     *     "people": [{id, name, profile_url}, ...]
     *   }
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $needle = trim($data['q']);
        $like = '%'.$needle.'%';

        $movies = Movie::query()
            ->where(function ($q) use ($like) {
                $q->whereLike('title', $like)
                    ->orWhereLike('original_title', $like);
            })
            ->orderByDesc('popularity')
            ->limit(10)
            ->get(['id', 'slug', 'title', 'poster_path', 'release_date'])
            ->map(function (Movie $m) {
                return [
                    'id' => $m->id,
                    'slug' => $m->slug,
                    'title' => $m->title,
                    'year' => $m->release_date ? $m->release_date->format('Y') : null,
                    'poster_url' => $m->poster_url,
                    'url' => $m->slug ? route('movies.show', $m->slug) : null,
                ];
            })
            ->values();

        $people = Cast::query()
            ->whereLike('name', $like)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'profile_path'])
            ->map(function (Cast $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'profile_url' => \App\Support\MediaDisk::url($c->profile_path),
                    'search_url' => route('search.smart').'?q='.urlencode($c->name).'&intent=actor',
                ];
            })
            ->values();

        return response()->json([
            'query' => $needle,
            'movies' => $movies,
            'people' => $people,
        ]);
    }

    // ─── Internals ────────────────────────────────────────────────────

    /**
     * Route the classified intent to the appropriate service / DB query.
     * Returns an ordered list of result groups:
     *   [
     *     ['key' => 'titles', 'label' => '…', 'movies' => Collection<array>],
     *     ...
     *   ]
     *
     * @return list<array{key: string, label: string, movies: Collection<int, array<string, mixed>>}>
     */
    protected function dispatchIntent(string $intent, string $normalized, string $original): array
    {
        $groups = [];

        switch ($intent) {
            case 'actor':
            case 'director':
                $matches = $this->personService->searchByPerson($normalized, $intent);
                $actorMatches = $matches->filter(fn (Movie $m) => $m->getAttribute('_match_type') === 'actor');
                $directorMatches = $matches->filter(fn (Movie $m) => $m->getAttribute('_match_type') === 'director');

                if ($actorMatches->isNotEmpty()) {
                    $groups[] = [
                        'key' => 'actor',
                        'label' => 'Sebagai aktor / aktris',
                        'movies' => $actorMatches->map(fn (Movie $m) => $this->mapMovie($m, [
                            '_match_type' => 'actor',
                        ])),
                    ];
                }
                if ($directorMatches->isNotEmpty()) {
                    $groups[] = [
                        'key' => 'director',
                        'label' => 'Sebagai sutradara',
                        'movies' => $directorMatches->map(fn (Movie $m) => $this->mapMovie($m, [
                            '_match_type' => 'director',
                            '_ai_guess_title' => $m->getAttribute('_ai_guess_title'),
                        ])),
                    ];
                }
                break;

            case 'vibe':
                $vibe = $this->decadeService->searchByVibe(vibe: $normalized, era: null, count: 18);
                if ($vibe->isNotEmpty()) {
                    $groups[] = [
                        'key' => 'vibe',
                        'label' => 'Curated berdasarkan vibe',
                        'movies' => $vibe->map(fn (Movie $m) => $this->mapMovie($m)),
                    ];
                }
                break;

            case 'year':
                $year = $this->extractYear($normalized);
                if ($year !== null) {
                    $movies = Movie::with('genres')
                        ->whereNotNull('release_date')
                        ->whereYear('release_date', '>=', $year)
                        ->whereYear('release_date', '<=', $this->isDecade($normalized) ? $year + 9 : $year)
                        ->orderByDesc('popularity')
                        ->limit(24)
                        ->get();

                    if ($movies->isNotEmpty()) {
                        $label = $this->isDecade($normalized)
                            ? "Tahun {$year}s"
                            : "Rilis {$year}";
                        $groups[] = [
                            'key' => 'year',
                            'label' => $label,
                            'movies' => $movies->map(fn (Movie $m) => $this->mapMovie($m)),
                        ];
                    }
                }
                break;

            case 'genre':
                $genreMatches = $this->resolveGenres($normalized);
                if ($genreMatches->isNotEmpty()) {
                    $movies = Movie::with('genres')
                        ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $genreMatches->pluck('id')->all()))
                        ->orderByDesc('popularity')
                        ->limit(24)
                        ->get();

                    if ($movies->isNotEmpty()) {
                        $groups[] = [
                            'key' => 'genre',
                            'label' => 'Genre: '.$genreMatches->pluck('name')->join(', '),
                            'movies' => $movies->map(fn (Movie $m) => $this->mapMovie($m)),
                        ];
                    }
                }
                break;

            case 'title':
            default:
                $titles = $this->knowledge->searchRelevant($normalized, 18);
                if ($titles->isNotEmpty()) {
                    $groups[] = [
                        'key' => 'title',
                        'label' => 'Hasil pencarian judul',
                        'movies' => $titles->map(fn (Movie $m) => $this->mapMovie($m)),
                    ];
                }
                break;
        }

        // Fallback augmentation: if the dispatched path returned nothing, run a
        // best-effort keyword search so the page is never blank.
        if (empty($groups)) {
            $fallback = $this->knowledge->searchRelevant($normalized !== '' ? $normalized : $original, 12);
            if ($fallback->isNotEmpty()) {
                $groups[] = [
                    'key' => 'fallback',
                    'label' => 'Mungkin kamu mencari…',
                    'movies' => $fallback->map(fn (Movie $m) => $this->mapMovie($m)),
                ];
            }
        }

        return $groups;
    }

    /**
     * Pull a 4-digit year out of a normalised query string.
     */
    protected function extractYear(string $query): ?int
    {
        $needle = mb_strtolower($query);
        if (preg_match('/(19|20)(\d{2})/', $needle, $m)) {
            return (int) ($m[1].$m[2]);
        }
        // "90an", "70s" → 1990, 1970
        if (preg_match('/(\d0)\s*-?(?:an|s|en)?\b/u', $needle, $m)) {
            $tens = (int) $m[1];
            $century = $tens >= 30 ? 19 : 20;

            return $century * 100 + $tens;
        }

        return null;
    }

    /**
     * True if the input expresses a decade rather than a specific year.
     */
    protected function isDecade(string $query): bool
    {
        $needle = mb_strtolower($query);
        if (preg_match('/\d0\s*-?(?:an|s|en)\b/u', $needle)) {
            return true;
        }
        // Decade if year ends in 0 + no explicit non-zero last digit context.
        if (preg_match('/^(19|20)(\d0)$/', trim($needle))) {
            // Ambiguous — treat as decade if user wrote "1990s" / "1990an".
            return preg_match('/(an|s|en)\b/u', $needle) === 1;
        }

        return false;
    }

    /**
     * Find genre rows matching keywords in the query.
     *
     * @return Collection<int, Genre>
     */
    protected function resolveGenres(string $query): Collection
    {
        $needle = mb_strtolower($query);
        $genres = Genre::all();
        $matches = $genres->filter(function (Genre $g) use ($needle) {
            $name = mb_strtolower($g->name);

            return str_contains($needle, $name) || str_contains($name, $needle);
        })->values();

        if ($matches->isNotEmpty()) {
            return $matches;
        }

        // Fall back: best LIKE match
        $like = Genre::where('name', 'LIKE', '%'.$needle.'%')->get();

        return $like;
    }

    /**
     * Per-user rate limiting — 30 hits / 60s. Returns Indonesian error or null.
     */
    protected function rateLimit(string $bucket): ?string
    {
        $key = $bucket.':'.(auth()->id() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return 'Terlalu banyak pencarian. Tunggu sebentar ya.';
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * id→name genre map for the <x-movies> popover pills.
     */
    protected function genreMap(): Collection
    {
        return Genre::all()->mapWithKeys(fn (Genre $g) => [$g->id => $g->name]);
    }

    /**
     * Shape a Movie model into the array structure <x-movies> expects.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function mapMovie(Movie $movie, array $extra = []): array
    {
        return array_merge([
            'id' => $movie->id,
            'slug' => $movie->slug,
            'title' => $movie->title,
            'original_title' => $movie->original_title,
            'overview' => $movie->overview,
            'release_date' => $movie->release_date ? $movie->release_date->format('Y-m-d') : null,
            'poster_path' => $movie->poster_url,
            'vote_average' => (float) $movie->vote_average,
            'vote_count' => $movie->vote_count,
            'genre_ids' => $movie->relationLoaded('genres')
                ? $movie->genres->pluck('id')->toArray()
                : [],
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
