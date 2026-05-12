<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YearInReviewGenerator — Spotify-Wrapped style annual recap for a FLiK user.
 *
 * Aggregates a user's watch history for a given calendar year, derives "fun stats"
 * (top genres, actors, decades, longest session, most active month/weekday, etc.)
 * and asks the configured AI provider to write a warm, celebratory narrative in
 * Bahasa Indonesia.
 *
 * Output shape (also persisted to year_in_reviews.stats / narrative columns):
 *   [
 *     'year'      => int,
 *     'stats'     => [...],          // see computeStats()
 *     'narrative' => string,
 *     'shareable_image_data' => [    // small payload tailored for OG / social-card render
 *         'title'         => string, // e.g. "Tahun {year} {name} di FLiK"
 *         'top_3_genres'  => string[],
 *         'total_hours'   => float,
 *     ],
 *   ]
 */
class YearInReviewGenerator
{
    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate the year-in-review for $user. Defaults to the previous calendar year.
     */
    public function generate(User $user, ?int $year = null): array
    {
        $year ??= Carbon::now()->subYear()->year;

        $from = Carbon::create($year, 1, 1, 0, 0, 0);
        $to   = Carbon::create($year, 12, 31, 23, 59, 59);

        $stats = $this->computeStats($user, $from, $to);

        $narrative = null;
        try {
            $narrative = $this->writeNarrative($user, $year, $stats);
        } catch (\Throwable $e) {
            Log::warning('YearInReviewGenerator narrative failed', [
                'user_id' => $user->id,
                'year'    => $year,
                'error'   => $e->getMessage(),
            ]);
            $narrative = $this->fallbackNarrative($user, $year, $stats);
        }

        return [
            'year'                 => $year,
            'stats'                => $stats,
            'narrative'            => $narrative,
            'shareable_image_data' => $this->buildShareableImageData($user, $year, $stats),
        ];
    }

    // ── Stat aggregation ─────────────────────────────────────────────────

    /**
     * Compute the full stats payload from watch_histories within [$from, $to].
     *
     * We filter on `created_at` per the spec ("filtered by created_at year"),
     * but also expose `last_watched_at`-based aggregates where they make more
     * sense (longest session, monthly/weekday distribution).
     */
    protected function computeStats(User $user, Carbon $from, Carbon $to): array
    {
        // Base scope: this user's watch history rows created within the year.
        $base = WatchHistory::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to]);

        // Pull all rows we need once — keeps us at O(1) DB roundtrips on the hot path.
        $rows = (clone $base)
            ->select('id', 'movie_id', 'progress_seconds', 'duration_seconds',
                'created_at', 'last_watched_at', 'completed')
            ->get();

        $movieIds = $rows->pluck('movie_id')->unique()->values();

        $totalFilms = $movieIds->count();
        $totalSeconds = (int) $rows->sum('progress_seconds');
        $totalHours = round($totalSeconds / 3600, 2);

        $movies = $totalFilms > 0
            ? Movie::whereIn('id', $movieIds)->get()->keyBy('id')
            : collect();

        return [
            'year_range' => [
                'from' => $from->toDateTimeString(),
                'to'   => $to->toDateTimeString(),
            ],
            'total_films_watched'   => $totalFilms,
            'total_watch_hours'     => $totalHours,
            'total_watch_seconds'   => $totalSeconds,
            'completed_count'       => (int) $rows->where('completed', true)->count(),
            'top_genres'            => $this->topGenres($movieIds, 5),
            'top_actors'            => $this->topActors($movieIds, 5),
            'top_decades'           => $this->topDecades($movies),
            'longest_session'       => $this->longestSession($rows, $movies),
            'most_active_month'     => $this->mostActiveMonth($rows),
            'most_active_weekday'   => $this->mostActiveWeekday($rows),
            'first_watched_movie'   => $this->firstWatchedMovie($rows, $movies),
            'most_rewatched_movie'  => $this->mostRewatchedMovie($user, $movieIds, $movies),
        ];
    }

    /**
     * Top N genres by # of distinct watched movies tagged with that genre.
     */
    protected function topGenres($movieIds, int $limit): array
    {
        if ($movieIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('genre_movie')
            ->join('genres', 'genres.id', '=', 'genre_movie.genre_id')
            ->whereIn('genre_movie.movie_id', $movieIds)
            ->select('genres.id', 'genres.name', DB::raw('COUNT(DISTINCT genre_movie.movie_id) as cnt'))
            ->groupBy('genres.id', 'genres.name')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'id'    => (int) $r->id,
            'name'  => (string) $r->name,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Top N cast members across watched movies (cast_movie pivot).
     */
    protected function topActors($movieIds, int $limit): array
    {
        if ($movieIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('cast_movie')
            ->join('casts', 'casts.id', '=', 'cast_movie.cast_id')
            ->whereIn('cast_movie.movie_id', $movieIds)
            ->select('casts.id', 'casts.name', DB::raw('COUNT(DISTINCT cast_movie.movie_id) as cnt'))
            ->groupBy('casts.id', 'casts.name')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'id'    => (int) $r->id,
            'name'  => (string) $r->name,
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Group watched movies by release-date decade and return them sorted by count desc.
     */
    protected function topDecades($movies): array
    {
        if ($movies->isEmpty()) {
            return [];
        }

        $buckets = [];
        foreach ($movies as $movie) {
            if (!$movie->release_date) {
                continue;
            }
            $year = (int) Carbon::parse($movie->release_date)->year;
            if ($year < 1900) {
                continue;
            }
            $decade = (int) (floor($year / 10) * 10);
            $buckets[$decade] = ($buckets[$decade] ?? 0) + 1;
        }

        if (!$buckets) {
            return [];
        }

        arsort($buckets);

        $out = [];
        foreach ($buckets as $decade => $cnt) {
            $out[] = [
                'decade' => (int) $decade,
                'label'  => $decade . 's',
                'count'  => (int) $cnt,
            ];
        }

        return $out;
    }

    /**
     * Longest single watching session — proxied by max(progress_seconds) on a row,
     * since WatchHistory is upsert-per-(user,movie) and doesn't track per-session deltas.
     */
    protected function longestSession($rows, $movies): array
    {
        if ($rows->isEmpty()) {
            return [
                'movie_id' => null,
                'title'    => null,
                'seconds'  => 0,
                'minutes'  => 0,
            ];
        }

        $top = $rows->sortByDesc('progress_seconds')->first();
        $movie = $movies->get($top->movie_id);

        return [
            'movie_id' => (int) $top->movie_id,
            'title'    => $movie?->title,
            'seconds'  => (int) $top->progress_seconds,
            'minutes'  => (int) round(((int) $top->progress_seconds) / 60),
        ];
    }

    /**
     * Month (1–12) with the most watch-history rows.
     */
    protected function mostActiveMonth($rows): array
    {
        if ($rows->isEmpty()) {
            return ['month' => null, 'label' => null, 'count' => 0];
        }

        $counts = [];
        foreach ($rows as $r) {
            $ts = $r->last_watched_at ?: $r->created_at;
            if (!$ts) {
                continue;
            }
            $m = (int) Carbon::parse($ts)->month;
            $counts[$m] = ($counts[$m] ?? 0) + 1;
        }

        if (!$counts) {
            return ['month' => null, 'label' => null, 'count' => 0];
        }

        arsort($counts);
        $month = (int) array_key_first($counts);
        $label = Carbon::create(null, $month, 1)->locale('id')->isoFormat('MMMM');

        return [
            'month' => $month,
            'label' => $label,
            'count' => (int) $counts[$month],
        ];
    }

    /**
     * Weekday (Carbon::dayOfWeek; 0 = Sunday) with the most rows.
     */
    protected function mostActiveWeekday($rows): array
    {
        if ($rows->isEmpty()) {
            return ['weekday' => null, 'label' => null, 'count' => 0];
        }

        $counts = [];
        foreach ($rows as $r) {
            $ts = $r->last_watched_at ?: $r->created_at;
            if (!$ts) {
                continue;
            }
            $dow = (int) Carbon::parse($ts)->dayOfWeek;
            $counts[$dow] = ($counts[$dow] ?? 0) + 1;
        }

        if (!$counts) {
            return ['weekday' => null, 'label' => null, 'count' => 0];
        }

        arsort($counts);
        $dow = (int) array_key_first($counts);
        // Build a real date for that weekday this week, then format in ID locale.
        $label = Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($dow)->locale('id')->isoFormat('dddd');

        return [
            'weekday' => $dow,
            'label'   => $label,
            'count'   => (int) $counts[$dow],
        ];
    }

    /**
     * The very first movie this user watched within the year window.
     */
    protected function firstWatchedMovie($rows, $movies): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->sortBy('created_at')->first();
        $movie = $movies->get($first->movie_id);

        return [
            'movie_id'   => (int) $first->movie_id,
            'title'      => $movie?->title,
            'watched_at' => optional($first->created_at)->toDateTimeString(),
        ];
    }

    /**
     * "Most rewatched" — movie with highest total progress_seconds across all of
     * this user's history rows for movies first watched in $year. Approximates
     * rewatch since WatchHistory accumulates on the same row.
     */
    protected function mostRewatchedMovie(User $user, $movieIds, $movies): ?array
    {
        if ($movieIds->isEmpty()) {
            return null;
        }

        $row = WatchHistory::query()
            ->where('user_id', $user->id)
            ->whereIn('movie_id', $movieIds)
            ->select('movie_id', 'progress_seconds', 'duration_seconds')
            ->orderByDesc('progress_seconds')
            ->first();

        if (!$row) {
            return null;
        }

        // Heuristic rewatch count: progress_seconds / duration_seconds, capped to >=1.
        $duration = (int) ($row->duration_seconds ?? 0);
        $rewatches = $duration > 0
            ? max(1, (int) round($row->progress_seconds / $duration))
            : 1;

        $movie = $movies->get($row->movie_id);

        return [
            'movie_id'           => (int) $row->movie_id,
            'title'              => $movie?->title,
            'estimated_rewatches' => $rewatches,
            'progress_seconds'   => (int) $row->progress_seconds,
        ];
    }

    // ── Narrative ────────────────────────────────────────────────────────

    /**
     * Ask the configured AI provider for a 3–4 paragraph Bahasa Indonesia recap.
     */
    protected function writeNarrative(User $user, int $year, array $stats): string
    {
        $context = [
            'nama_pengguna'        => $user->name,
            'tahun'                => $year,
            'total_film_ditonton'  => $stats['total_films_watched'],
            'total_jam_tonton'     => $stats['total_watch_hours'],
            'film_diselesaikan'    => $stats['completed_count'],
            'genre_favorit'        => $stats['top_genres'],
            'aktor_favorit'        => $stats['top_actors'],
            'dekade_favorit'       => $stats['top_decades'],
            'sesi_terpanjang'      => $stats['longest_session'],
            'bulan_paling_aktif'   => $stats['most_active_month'],
            'hari_paling_aktif'    => $stats['most_active_weekday'],
            'film_pertama'         => $stats['first_watched_movie'],
            'film_paling_diulang'  => $stats['most_rewatched_movie'],
        ];

        $system = 'Anda adalah copywriter ramah untuk FLiK, platform streaming film Indonesia. '
            . 'Tugas Anda menulis "Year In Review" pribadi bergaya Spotify Wrapped: hangat, '
            . 'merayakan, sedikit playful, dan bicara langsung kepada pengguna dengan namanya. '
            . 'Selalu dalam Bahasa Indonesia.';

        $userPrompt = "Tulis ringkasan 'Tahun {$year} di FLiK' untuk pengguna **{$user->name}** "
            . "berdasarkan statistik berikut (JSON). Format: 3-4 paragraf, hangat dan merayakan, "
            . "seperti Spotify Wrapped. Sapa pengguna dengan nama. Soroti satu-dua insight unik / "
            . "lucu dari datanya (misal hari paling aktif, dekade favorit yang tak terduga, atau "
            . "binge session terpanjang). Hindari bullet list, pakai paragraf mengalir. Jangan "
            . "ulangi semua angka mentah - pilih yang paling menarik. Jika ada metrik bernilai 0 "
            . "atau kosong, akui dengan jenaka tanpa membuat pengguna merasa buruk.\n\n"
            . "Data:\n```json\n"
            . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n```";

        $response = $this->ai->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            ['max_tokens' => 900, 'temperature' => 0.8],
        );

        return trim((string) ($response['content'] ?? ''));
    }

    /**
     * Plain-text fallback if AI provider is unavailable.
     */
    protected function fallbackNarrative(User $user, int $year, array $stats): string
    {
        $films = (int) $stats['total_films_watched'];
        $hours = (float) $stats['total_watch_hours'];
        $topGenre = $stats['top_genres'][0]['name'] ?? null;
        $month = $stats['most_active_month']['label'] ?? null;

        if ($films === 0) {
            return "Halo {$user->name}, sepertinya kamu belum sempat menonton apa pun di FLiK "
                . "sepanjang tahun {$year}. Tahun {$year} mungkin sibuk, dan itu tidak apa-apa! "
                . "Tahun depan, mari mulai petualangan sinematik kamu di FLiK.";
        }

        $line1 = "Halo {$user->name}! Sepanjang tahun {$year}, kamu menonton {$films} film "
            . "dengan total sekitar {$hours} jam di FLiK.";

        $line2 = $topGenre
            ? "Genre yang paling sering kamu pilih adalah {$topGenre} - kelihatan banget seleramu. "
            : "Kamu mencoba beragam genre tahun ini. ";

        $line3 = $month
            ? "Bulan {$month} jadi bulan paling aktif buat marathon film kamu."
            : "";

        $line4 = "Catatan otomatis ini dibuat saat narasi AI tidak tersedia. Aktifkan provider AI "
            . "di /admin/ai-settings untuk recap bergaya Spotify Wrapped yang lebih personal.";

        return trim($line1 . ' ' . $line2 . $line3 . "\n\n" . $line4);
    }

    /**
     * Compact payload designed for an OpenGraph card / social-share image renderer.
     */
    protected function buildShareableImageData(User $user, int $year, array $stats): array
    {
        $top3 = collect($stats['top_genres'] ?? [])->take(3)->pluck('name')->values()->all();

        return [
            'title'        => "Tahun {$year} {$user->name} di FLiK",
            'top_3_genres' => $top3,
            'total_hours'  => (float) ($stats['total_watch_hours'] ?? 0),
        ];
    }
}
