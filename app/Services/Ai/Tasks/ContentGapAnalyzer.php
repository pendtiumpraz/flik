<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Rating;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compares the FLiK catalog footprint against actual user demand signals
 * (watch activity + ratings) and asks the configured AI provider to
 * surface the top content acquisition / restoration gaps.
 *
 * The catalog dimensions analysed are:
 *   - genre coverage     (movies per Genre)
 *   - decade coverage    (movies per decade derived from release_date)
 *   - language coverage  (active subtitle languages — best proxy we have for
 *                         "spoken language" since the movies table has no
 *                         language column today)
 *   - country coverage   (only emitted when a `country` / `country_code`
 *                         column actually exists on the movies table — we
 *                         feature-detect because the schema in this branch
 *                         does not ship one)
 *
 * Output shape:
 *   [
 *     'generated_at'    => 'Y-m-d H:i:s',
 *     'window_days'     => 90,
 *     'catalog_stats'   => [
 *         'total_movies'          => int,
 *         'by_genre'              => [['name'=>..,'slug'=>..,'count'=>..], ...],
 *         'by_decade'             => [['decade'=>'1990s','count'=>..], ...],
 *         'by_language'           => [['code'=>'id','count'=>..], ...]   // optional
 *         'by_country'            => [['code'=>'ID','count'=>..], ...]   // optional
 *     ],
 *     'demand_stats'    => [
 *         'most_watched_genres'   => [['name'=>..,'slug'=>..,'views'=>..], ...],
 *         'most_rated_genres'     => [['name'=>..,'slug'=>..,'ratings'=>..,'avg_score'=>..], ...],
 *         'top_demand_decades'    => [['decade'=>..,'views'=>..], ...],
 *     ],
 *     'gap_signals'     => [['kind'=>'genre','label'=>..,'demand_share'=>%,'supply_share'=>%,'delta'=>%], ...],
 *     'recommendations' => [['gap_description'=>..,'evidence'=>..,'recommendation'=>..,'priority'=>'high|medium|low'], ...],
 *     'ai_error'        => ?string,
 *   ]
 */
class ContentGapAnalyzer
{
    /** Look-back window for demand signals (watch + ratings). */
    protected const WINDOW_DAYS = 90;

    /** Hard cap on recommendation list length (matches AI prompt). */
    protected const MAX_RECOMMENDATIONS = 5;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Run the full analysis. Always returns a populated array — the AI step
     * degrades to an empty `recommendations` list with `ai_error` set if the
     * provider is missing or errors out, so the caller can still render stats.
     *
     * @return array<string,mixed>
     */
    public function analyze(): array
    {
        $catalog = $this->collectCatalogStats();
        $demand = $this->collectDemandStats();
        $signals = $this->buildGapSignals($catalog, $demand);

        $recommendations = [];
        $aiError = null;

        try {
            $recommendations = $this->askAi($catalog, $demand, $signals);
        } catch (\Throwable $e) {
            Log::warning('ContentGapAnalyzer: AI call failed', [
                'error' => $e->getMessage(),
            ]);
            $aiError = $e->getMessage();
        }

        return [
            'generated_at' => now()->toDateTimeString(),
            'window_days' => self::WINDOW_DAYS,
            'catalog_stats' => $catalog,
            'demand_stats' => $demand,
            'gap_signals' => $signals,
            'recommendations' => $recommendations,
            'ai_error' => $aiError,
        ];
    }

    // ── Catalog side ─────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    protected function collectCatalogStats(): array
    {
        $totalMovies = (int) Movie::query()->count();

        // by_genre — every genre with its movie count, descending.
        $byGenre = Genre::query()
            ->leftJoin('genre_movie', 'genres.id', '=', 'genre_movie.genre_id')
            ->select('genres.id', 'genres.name', 'genres.slug', DB::raw('COUNT(genre_movie.movie_id) as cnt'))
            ->groupBy('genres.id', 'genres.name', 'genres.slug')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'count' => (int) $row->cnt,
            ])
            ->values()
            ->all();

        // by_decade — derived from release_date. The year-extraction function
        // differs across drivers (strftime on SQLite, YEAR()/EXTRACT() on
        // MySQL/Postgres), so we always use the PHP-side bucketer — small
        // dataset for an admin report, and portable.
        $byDecade = $this->byDecadePortable();

        $stats = [
            'total_movies' => $totalMovies,
            'by_genre' => $byGenre,
            'by_decade' => $byDecade,
        ];

        // Subtitle languages → best available "language coverage" proxy.
        if (Schema::hasTable('movie_subtitles')) {
            $byLanguage = DB::table('movie_subtitles')
                ->select('language_code as code', DB::raw('COUNT(DISTINCT movie_id) as cnt'))
                ->where('is_active', true)
                ->groupBy('language_code')
                ->orderByDesc('cnt')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'code' => (string) $row->code,
                    'count' => (int) $row->cnt,
                ])
                ->values()
                ->all();

            if (! empty($byLanguage)) {
                $stats['by_language'] = $byLanguage;
            }
        }

        // Optional country column (feature-detected — current schema has none).
        $countryColumn = $this->detectMovieCountryColumn();
        if ($countryColumn !== null) {
            $byCountry = Movie::query()
                ->whereNotNull($countryColumn)
                ->select($countryColumn.' as code', DB::raw('COUNT(*) as cnt'))
                ->groupBy($countryColumn)
                ->orderByDesc('cnt')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'code' => (string) $row->code,
                    'count' => (int) $row->cnt,
                ])
                ->values()
                ->all();

            if (! empty($byCountry)) {
                $stats['by_country'] = $byCountry;
            }
        }

        return $stats;
    }

    /**
     * Portable decade aggregation (works on MySQL/Postgres/SQLite) using PHP-side bucketing.
     * Slower than a pure-SQL group but tiny in practice for an admin report.
     *
     * @return array<int,array{decade:string,count:int}>
     */
    protected function byDecadePortable(): array
    {
        $buckets = [];
        Movie::query()
            ->whereNotNull('release_date')
            ->select('release_date')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$buckets) {
                foreach ($rows as $row) {
                    $year = (int) (
                        $row->release_date instanceof \DateTimeInterface
                            ? $row->release_date->format('Y')
                            : substr((string) $row->release_date, 0, 4)
                    );
                    if ($year < 1880 || $year > 2100) {
                        continue;
                    }
                    $decade = (int) (floor($year / 10) * 10);
                    $buckets[$decade] = ($buckets[$decade] ?? 0) + 1;
                }
            });

        ksort($buckets);
        $out = [];
        foreach ($buckets as $decade => $count) {
            $out[] = ['decade' => $decade.'s', 'count' => $count];
        }

        return $out;
    }

    /**
     * Returns the actual column name on `movies` that holds a country code,
     * or null if no such column exists. We check several common conventions.
     */
    protected function detectMovieCountryColumn(): ?string
    {
        foreach (['country', 'country_code', 'origin_country', 'production_country'] as $candidate) {
            if (Schema::hasColumn('movies', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // ── Demand side ──────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    protected function collectDemandStats(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        // Most-watched genres (last N days). watch_histories has a unique
        // (user_id, movie_id) constraint, so COUNT(*) on the join is
        // already "distinct user-movie pairs per genre" — portable across
        // SQLite/MySQL/Postgres without string concat tricks.
        $watchCountInWindow = (int) WatchHistory::query()
            ->where('last_watched_at', '>=', $since)
            ->count();

        $watchedRows = DB::table('watch_histories')
            ->join('genre_movie', 'genre_movie.movie_id', '=', 'watch_histories.movie_id')
            ->join('genres', 'genres.id', '=', 'genre_movie.genre_id')
            ->where('watch_histories.last_watched_at', '>=', $since)
            ->select('genres.id', 'genres.name', 'genres.slug', DB::raw('COUNT(*) as views'))
            ->groupBy('genres.id', 'genres.name', 'genres.slug')
            ->orderByDesc('views')
            ->limit(15)
            ->get();

        $mostWatchedGenres = $watchedRows
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'views' => (int) $row->views,
            ])
            ->values()
            ->all();

        // Most-rated genres.
        $mostRatedGenres = DB::table('ratings')
            ->join('genre_movie', 'genre_movie.movie_id', '=', 'ratings.movie_id')
            ->join('genres', 'genres.id', '=', 'genre_movie.genre_id')
            ->where('ratings.created_at', '>=', $since)
            ->select(
                'genres.id',
                'genres.name',
                'genres.slug',
                DB::raw('COUNT(*) as ratings_count'),
                DB::raw('AVG(ratings.score) as avg_score'),
            )
            ->groupBy('genres.id', 'genres.name', 'genres.slug')
            ->orderByDesc('ratings_count')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'ratings' => (int) $row->ratings_count,
                'avg_score' => round((float) $row->avg_score, 2),
            ])
            ->values()
            ->all();

        // Demand by decade (proxy for "people want X-era films").
        $topDecades = $this->demandByDecade($since);

        return [
            'most_watched_genres' => $mostWatchedGenres,
            'most_rated_genres' => $mostRatedGenres,
            'top_demand_decades' => $topDecades,
            'total_ratings' => (int) Rating::query()->where('created_at', '>=', $since)->count(),
            'total_views' => $watchCountInWindow,
        ];
    }

    /**
     * @return array<int,array{decade:string,views:int}>
     */
    protected function demandByDecade(\DateTimeInterface $since): array
    {
        $buckets = [];
        DB::table('watch_histories')
            ->join('movies', 'movies.id', '=', 'watch_histories.movie_id')
            ->where('watch_histories.last_watched_at', '>=', $since)
            ->whereNotNull('movies.release_date')
            ->select('movies.release_date')
            ->orderBy('watch_histories.id')
            ->chunk(1000, function ($rows) use (&$buckets) {
                foreach ($rows as $row) {
                    $year = (int) substr((string) $row->release_date, 0, 4);
                    if ($year < 1880 || $year > 2100) {
                        continue;
                    }
                    $decade = (int) (floor($year / 10) * 10);
                    $buckets[$decade] = ($buckets[$decade] ?? 0) + 1;
                }
            });

        arsort($buckets);
        $out = [];
        foreach (array_slice($buckets, 0, 8, true) as $decade => $count) {
            $out[] = ['decade' => $decade.'s', 'views' => $count];
        }

        return $out;
    }

    // ── Gap signal computation ───────────────────────────────────────────

    /**
     * For each genre we know the user demands (most-watched), compare its
     * share of demand to its share of supply. Positive `delta` means people
     * watch this genre more than the catalog can serve.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildGapSignals(array $catalog, array $demand): array
    {
        $totalSupply = max(1, array_sum(array_column($catalog['by_genre'], 'count')));
        $totalDemand = max(1, array_sum(array_column($demand['most_watched_genres'], 'views')));

        $supplyBySlug = [];
        foreach ($catalog['by_genre'] as $row) {
            $supplyBySlug[$row['slug']] = $row['count'];
        }

        $signals = [];
        foreach ($demand['most_watched_genres'] as $row) {
            $slug = $row['slug'];
            $supplyCount = (int) ($supplyBySlug[$slug] ?? 0);
            $supplyShare = ($supplyCount / $totalSupply) * 100;
            $demandShare = ((int) $row['views'] / $totalDemand) * 100;
            $delta = $demandShare - $supplyShare;

            $signals[] = [
                'kind' => 'genre',
                'label' => $row['name'],
                'slug' => $slug,
                'supply_count' => $supplyCount,
                'demand_views' => (int) $row['views'],
                'supply_share' => round($supplyShare, 1),
                'demand_share' => round($demandShare, 1),
                'delta' => round($delta, 1),
            ];
        }

        // Sort by largest unmet demand first.
        usort($signals, fn ($a, $b) => $b['delta'] <=> $a['delta']);

        return array_slice($signals, 0, 10);
    }

    // ── AI step ──────────────────────────────────────────────────────────

    /**
     * Ask the AI to prioritise the gaps and produce concrete recommendations.
     *
     * @return array<int,array{gap_description:string,evidence:string,recommendation:string,priority:string}>
     */
    protected function askAi(array $catalog, array $demand, array $signals): array
    {
        $context = [
            'jendela_analisis_hari' => self::WINDOW_DAYS,
            'total_film' => $catalog['total_movies'],
            'top_genre_supply' => array_slice($catalog['by_genre'], 0, 10),
            'distribusi_dekade' => $catalog['by_decade'],
            'cakupan_bahasa' => $catalog['by_language'] ?? [],
            'cakupan_negara' => $catalog['by_country'] ?? [],
            'genre_paling_ditonton' => $demand['most_watched_genres'],
            'genre_paling_dirating' => $demand['most_rated_genres'],
            'dekade_paling_dicari' => $demand['top_demand_decades'],
            'sinyal_kesenjangan' => $signals,
        ];

        $system = 'Anda adalah analis akuisisi konten untuk FLiK, layanan streaming film Indonesia. '
            .'Tugas Anda: membaca statistik catalog vs permintaan pengguna lalu menyimpulkan kesenjangan konten paling kritis. '
            .'Output WAJIB strict JSON tanpa prosa, tanpa fence markdown.';

        $user = 'Identifikasi maksimal '.self::MAX_RECOMMENDATIONS." kesenjangan konten paling penting dan beri rekomendasi akuisisi atau restorasi.\n\n"
            ."Output WAJIB array JSON dengan schema:\n"
            ."[{\"gap_description\":\"...\",\"evidence\":\"...\",\"recommendation\":\"...\",\"priority\":\"high|medium|low\"}]\n\n"
            ."Aturan:\n"
            ."- Semua teks dalam Bahasa Indonesia.\n"
            ."- evidence harus mengutip angka konkret dari data (misal: persentase share, jumlah view, dekade).\n"
            ."- recommendation harus actionable (misal: \"akuisisi 8-10 film drama Indonesia tahun 2010-an\").\n"
            ."- priority high jika delta demand-supply > 10pp atau coverage < 5 film pada genre ramai.\n"
            ."- Jangan ulangi rekomendasi yang serupa.\n\n"
            ."Data:\n```json\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n```";

        $response = $this->ai->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            ['max_tokens' => 1200, 'temperature' => 0.5],
        );

        return $this->parseRecommendations((string) ($response['content'] ?? ''));
    }

    /**
     * @return array<int,array{gap_description:string,evidence:string,recommendation:string,priority:string}>
     */
    protected function parseRecommendations(string $raw): array
    {
        $json = $this->extractJsonArray($raw);
        if ($json === null) {
            Log::warning('ContentGapAnalyzer: could not extract JSON', [
                'raw' => Str::limit($raw, 400),
            ]);

            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $allowedPriorities = ['high', 'medium', 'low'];
        $clean = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $priority = strtolower(trim((string) ($item['priority'] ?? 'medium')));
            if (! in_array($priority, $allowedPriorities, true)) {
                $priority = 'medium';
            }
            $entry = [
                'gap_description' => trim((string) ($item['gap_description'] ?? '')),
                'evidence' => trim((string) ($item['evidence'] ?? '')),
                'recommendation' => trim((string) ($item['recommendation'] ?? '')),
                'priority' => $priority,
            ];
            // Drop entries where the AI gave us nothing useful.
            if ($entry['gap_description'] === '' && $entry['recommendation'] === '') {
                continue;
            }
            $clean[] = $entry;
            if (count($clean) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Strip markdown fences, isolate the first top-level JSON array.
     */
    protected function extractJsonArray(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }
}
