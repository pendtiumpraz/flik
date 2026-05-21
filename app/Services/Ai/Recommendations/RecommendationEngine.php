<?php

namespace App\Services\Ai\Recommendations;

use App\Models\Movie;
use App\Models\Rating;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserRecommendation;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use App\Services\Ai\Tasks\ColdStartRecommender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Personalized recommendation engine.
 *
 * Pipeline:
 *   1. Build user taste profile from watch history, ratings, watchlist
 *   2. Generate candidate movies via FilmKnowledgeService (content-based)
 *   3. Score candidates against profile (genre overlap, rating signal, popularity, recency)
 *   4. Optionally rerank top 50 with AI (DeepSeek/etc.) for human-readable reasons
 *   5. Persist to user_recommendations + cache top IDs in Redis 6h
 *
 * Designed for nightly batch use, but `computeFor()` is safe to call synchronously.
 */
class RecommendationEngine
{
    public const CACHE_TTL_SECONDS = 21600; // 6 hours
    public const AI_RERANK_THRESHOLD = 8;   // min profile signals before triggering AI rerank
    public const CANDIDATE_POOL_SIZE = 50;

    public function __construct(
        protected FilmKnowledgeService $knowledge,
        protected AiClient $ai,
    ) {}

    /**
     * Synchronous compute for one user.
     *
     * @return Collection<int, Movie>  Top $count movies, ordered by score desc.
     */
    public function computeFor(User $user, int $count = 20): Collection
    {
        $batchId = (string) Str::uuid();
        $profile = $this->buildProfile($user);

        // Cold-start: no signal yet → fall back to popularity
        if ($profile['is_cold_start']) {
            $result = $this->coldStartFallback($user, $count, $batchId);
            $this->cacheResult($user, $result);
            return $result;
        }

        // Generate candidates (content-based, excluding already-watched)
        $candidates = $this->generateCandidates($user, $profile);

        if ($candidates->isEmpty()) {
            $result = $this->coldStartFallback($user, $count, $batchId);
            $this->cacheResult($user, $result);
            return $result;
        }

        // Score them
        $scored = $this->scoreCandidates($candidates, $profile);

        // AI rerank for top-50 (only if profile is rich enough — saves API cost)
        $top = $scored->take(self::CANDIDATE_POOL_SIZE);
        $aiReasons = [];
        if ($this->shouldAiRerank($profile)) {
            $aiReasons = $this->aiRerank($user, $top, $profile);
        }

        // Persist & return final $count
        $final = $top->take($count);
        $this->persist($user, $final, $batchId, $aiReasons, $profile);

        $movies = $final->pluck('movie')->values();
        $this->cacheResult($user, $movies);

        return $movies;
    }

    /**
     * Read cached top-N from Redis, hydrate to Movie collection preserving order.
     */
    public function getCached(User $user): ?Collection
    {
        $ids = Cache::get($this->cacheKey($user));
        if (!is_array($ids) || empty($ids)) {
            return null;
        }

        $movies = Movie::with('genres', 'castMembers')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Preserve scored order
        return collect($ids)
            ->map(fn ($id) => $movies->get($id))
            ->filter()
            ->values();
    }

    // ────────────────────────────────────────────────────────────
    // Profile building
    // ────────────────────────────────────────────────────────────

    /**
     * Build taste profile from user's activity.
     *
     * @return array{
     *   genre_weights: array<int, float>,
     *   liked_movie_ids: array<int>,
     *   disliked_movie_ids: array<int>,
     *   watched_movie_ids: array<int>,
     *   top_genre_names: array<string>,
     *   liked_titles: array<string>,
     *   signal_count: int,
     *   is_cold_start: bool,
     * }
     */
    protected function buildProfile(User $user): array
    {
        // Watch history (with movie + genres eager-loaded)
        $histories = WatchHistory::with('movie.genres')
            ->where('user_id', $user->id)
            ->orderByDesc('last_watched_at')
            ->limit(100)
            ->get();

        // Ratings
        $ratings = Rating::with('movie.genres')
            ->where('user_id', $user->id)
            ->get();

        // Watchlist
        $watchlist = Watchlist::with('movie.genres')
            ->where('user_id', $user->id)
            ->get();

        $genreWeights = [];
        $likedIds = [];
        $dislikedIds = [];
        $watchedIds = [];
        $likedTitles = [];

        // Watch history → weight by completion + recency
        foreach ($histories as $h) {
            if (!$h->movie) continue;
            $watchedIds[] = $h->movie_id;

            $progressPct = $h->duration_seconds
                ? min(1.0, $h->progress_seconds / max(1, $h->duration_seconds))
                : 0.5;
            $recencyBoost = $h->last_watched_at
                ? max(0.3, 1.0 - (now()->diffInDays($h->last_watched_at) / 90))
                : 0.5;
            $weight = $progressPct * $recencyBoost * 2.0;

            if ($h->completed || $progressPct > 0.6) {
                $likedIds[] = $h->movie_id;
                $likedTitles[] = $h->movie->title;
            }

            foreach ($h->movie->genres as $g) {
                $genreWeights[$g->id] = ($genreWeights[$g->id] ?? 0) + $weight;
            }
        }

        // Ratings → strongest signal
        foreach ($ratings as $r) {
            if (!$r->movie) continue;
            $watchedIds[] = $r->movie_id;

            // Score in 1–10 (or 1–5 — normalize). Anything >=7/10 (or >=3.5/5) is liked.
            $normalized = (float) $r->score;
            if ($normalized > 5) $normalized = $normalized / 2; // 1–10 → 1–5

            if ($normalized >= 3.5) {
                $likedIds[] = $r->movie_id;
                $likedTitles[] = $r->movie->title;
                $weight = ($normalized - 2.5) * 1.5; // 1.5 → 3.75
                foreach ($r->movie->genres as $g) {
                    $genreWeights[$g->id] = ($genreWeights[$g->id] ?? 0) + $weight;
                }
            } elseif ($normalized <= 2.0) {
                $dislikedIds[] = $r->movie_id;
                // Penalize genres of disliked films
                foreach ($r->movie->genres as $g) {
                    $genreWeights[$g->id] = ($genreWeights[$g->id] ?? 0) - 1.0;
                }
            }
        }

        // Watchlist → intent signal
        foreach ($watchlist as $w) {
            if (!$w->movie) continue;
            $likedIds[] = $w->movie_id;
            $likedTitles[] = $w->movie->title;
            foreach ($w->movie->genres as $g) {
                $genreWeights[$g->id] = ($genreWeights[$g->id] ?? 0) + 1.0;
            }
        }

        arsort($genreWeights);

        // Resolve top genre names for human-readable reason
        $topGenreIds = array_slice(array_keys($genreWeights), 0, 5);
        $topGenreNames = \App\Models\Genre::whereIn('id', $topGenreIds)
            ->pluck('name', 'id')
            ->all();
        // Preserve order from $topGenreIds
        $orderedNames = [];
        foreach ($topGenreIds as $gid) {
            if (isset($topGenreNames[$gid])) $orderedNames[] = $topGenreNames[$gid];
        }

        $signalCount = $histories->count() + $ratings->count() + $watchlist->count();

        return [
            'genre_weights'      => $genreWeights,
            'liked_movie_ids'    => array_values(array_unique($likedIds)),
            'disliked_movie_ids' => array_values(array_unique($dislikedIds)),
            'watched_movie_ids'  => array_values(array_unique($watchedIds)),
            'top_genre_names'    => $orderedNames,
            'liked_titles'       => array_values(array_unique(array_slice($likedTitles, 0, 10))),
            'signal_count'       => $signalCount,
            'is_cold_start'      => $signalCount < 2,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // Candidate generation
    // ────────────────────────────────────────────────────────────

    /**
     * Build candidate pool using content-based similarity to liked movies.
     *
     * @return Collection<int, Movie>
     */
    protected function generateCandidates(User $user, array $profile): Collection
    {
        $excluded = $profile['watched_movie_ids'];
        $topGenreIds = array_slice(array_keys($profile['genre_weights']), 0, 5);

        $query = Movie::with('genres', 'castMembers')
            ->when($excluded, fn ($q) => $q->whereNotIn('id', $excluded));

        if (!empty($topGenreIds)) {
            $query->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $topGenreIds))
                ->withCount(['genres as matching_genres' => fn ($q) => $q->whereIn('genres.id', $topGenreIds)]);
        }

        return $query
            ->orderByDesc('popularity')
            ->orderByDesc('vote_average')
            ->limit(self::CANDIDATE_POOL_SIZE * 2) // overfetch for scoring
            ->get();
    }

    // ────────────────────────────────────────────────────────────
    // Scoring
    // ────────────────────────────────────────────────────────────

    /**
     * Score each candidate against the user profile.
     *
     * @return Collection<int, array{movie: Movie, score: float, reason: string}>
     */
    protected function scoreCandidates(Collection $candidates, array $profile): Collection
    {
        $totalGenreWeight = max(1.0, array_sum(array_map('abs', $profile['genre_weights'])));

        return $candidates
            ->map(function (Movie $movie) use ($profile, $totalGenreWeight) {
                $genreScore = 0.0;
                $matchedGenres = [];
                foreach ($movie->genres as $g) {
                    $w = $profile['genre_weights'][$g->id] ?? 0;
                    $genreScore += $w;
                    if ($w > 0) $matchedGenres[] = $g->name;
                }

                $normalizedGenre = $genreScore / $totalGenreWeight; // ~-1..1

                $popularityScore = min(1.0, ((float) $movie->popularity) / 1000);
                $ratingScore = ((float) $movie->vote_average) / 10;

                // Weighted blend
                $score = (
                    $normalizedGenre * 6.0
                    + $ratingScore   * 2.0
                    + $popularityScore * 1.0
                );

                $reason = $this->craftReason($matchedGenres, $profile);

                return [
                    'movie'  => $movie,
                    'score'  => round($score, 3),
                    'reason' => $reason,
                    'source' => UserRecommendation::SOURCE_CONTENT_BASED,
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    protected function craftReason(array $matchedGenres, array $profile): string
    {
        if (empty($matchedGenres) && empty($profile['liked_titles'])) {
            return 'Populer di FLiK saat ini';
        }

        if (!empty($matchedGenres)) {
            $g = array_slice($matchedGenres, 0, 2);
            $genreStr = implode(' & ', $g);

            if (!empty($profile['liked_titles'])) {
                $sample = $profile['liked_titles'][0];
                return "Karena kamu suka {$sample} dan film {$genreStr}";
            }
            return "Cocok dengan selera {$genreStr} kamu";
        }

        $sample = $profile['liked_titles'][0];
        return "Karena kamu suka {$sample}";
    }

    // ────────────────────────────────────────────────────────────
    // AI rerank (optional)
    // ────────────────────────────────────────────────────────────

    protected function shouldAiRerank(array $profile): bool
    {
        return $profile['signal_count'] >= self::AI_RERANK_THRESHOLD;
    }

    /**
     * Ask AI to provide curated reasons for top candidates.
     * Falls back gracefully if AI not configured.
     *
     * @param  Collection<int, array> $scored
     * @return array<int, string>  movie_id => AI-generated reason
     */
    protected function aiRerank(User $user, Collection $scored, array $profile): array
    {
        if ($scored->isEmpty()) return [];

        $films = $scored->take(15)->map(function ($row) {
            $m = $row['movie'];
            return [
                'id'     => $m->id,
                'title'  => $m->title,
                'genres' => $m->genres->pluck('name')->toArray(),
                'rating' => (float) $m->vote_average,
            ];
        })->toArray();

        $likedSample = array_slice($profile['liked_titles'], 0, 6);
        $topGenres = implode(', ', array_slice($profile['top_genre_names'], 0, 4));

        $prompt = "User {$user->name} suka film: " . implode(', ', $likedSample)
            . ". Top genre: {$topGenres}.\n\n"
            . "Untuk SETIAP film di list JSON di bawah, beri 1 kalimat alasan Bahasa Indonesia singkat (max 12 kata) "
            . "kenapa user ini bakal suka, mengacu pada film/genre favoritnya. "
            . "Output WAJIB JSON object: {\"<movie_id>\": \"<reason>\"}.\n\n"
            . json_encode($films, JSON_UNESCAPED_UNICODE);

        try {
            $result = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => 'Kamu film curator. Output JSON object only, no prose.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                options: [
                    'max_tokens'  => 800,
                    'temperature' => 0.4,
                ],
                taskType: 'recommend.ai_rerank',
                subject: $user,
            );

            $content = trim($result['content'] ?? '');
            // Strip code fences if present
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                Log::info('RecommendationEngine: AI rerank returned non-JSON', ['user_id' => $user->id]);
                return [];
            }

            // Normalize keys to int
            $reasons = [];
            foreach ($parsed as $k => $v) {
                if (is_string($v) && $v !== '') {
                    $reasons[(int) $k] = Str::limit($v, 140);
                }
            }
            return $reasons;
        } catch (\Throwable $e) {
            Log::info('RecommendationEngine: AI rerank skipped', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ────────────────────────────────────────────────────────────
    // Persistence & cache
    // ────────────────────────────────────────────────────────────

    /**
     * @param Collection<int, array> $scored  rows with movie/score/reason/source keys
     * @param array<int, string>     $aiReasons  movie_id => reason from AI
     */
    protected function persist(User $user, Collection $scored, string $batchId, array $aiReasons, array $profile): void
    {
        if ($scored->isEmpty()) return;

        $now = now();
        $rows = $scored->map(function ($row) use ($user, $batchId, $aiReasons, $now) {
            $movieId = $row['movie']->id;
            $aiReason = $aiReasons[$movieId] ?? null;

            return [
                'user_id'      => $user->id,
                'movie_id'     => $movieId,
                'score'        => $row['score'],
                'reason'       => $aiReason ?: $row['reason'],
                'source'       => $aiReason
                    ? UserRecommendation::SOURCE_AI_CURATED
                    : ($row['source'] ?? UserRecommendation::SOURCE_CONTENT_BASED),
                'batch_id'     => $batchId,
                'generated_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        })->all();

        DB::transaction(function () use ($user, $rows) {
            // Replace previous recommendations for this user (keeps table small)
            UserRecommendation::where('user_id', $user->id)->delete();
            // Insert in chunks (MySQL default placeholder limit)
            foreach (array_chunk($rows, 100) as $chunk) {
                UserRecommendation::insert($chunk);
            }
        });
    }

    /**
     * @param Collection<int, Movie> $movies
     */
    protected function cacheResult(User $user, Collection $movies): void
    {
        $ids = $movies->pluck('id')->all();
        Cache::put($this->cacheKey($user), $ids, self::CACHE_TTL_SECONDS);
    }

    protected function cacheKey(User $user): string
    {
        return "flik:user:{$user->id}:recommendations";
    }

    // ────────────────────────────────────────────────────────────
    // Cold start
    // ────────────────────────────────────────────────────────────

    /**
     * No signal yet → prefer onboarding-quiz answers (ColdStartRecommender),
     * fall through to popularity when the user skipped the quiz.
     *
     * Bridge: UserPreference stores genre slugs/names; ColdStartRecommender
     * already handles both — it does the slug/name lookup against
     * Genre.slug / Genre.name internally (see ColdStartRecommender::recommendForNewUser).
     */
    protected function coldStartFallback(User $user, int $count, string $batchId): Collection
    {
        $prefs = UserPreference::where('user_id', $user->id)->first();

        if ($prefs !== null) {
            $hasSignal = ! empty($prefs->favorite_genres) || ! empty($prefs->favorite_eras);

            if ($hasSignal) {
                try {
                    /** @var ColdStartRecommender $recommender */
                    $recommender = app(ColdStartRecommender::class);
                    $movies = $recommender->recommendForNewUser($prefs, $count);

                    if ($movies->isNotEmpty()) {
                        $this->persistColdStart($user, $movies, $batchId, 'Karena kamu suka ' . $this->reasonFromPrefs($prefs));
                        return $movies;
                    }
                } catch (\Throwable $e) {
                    Log::warning('RecommendationEngine: ColdStartRecommender failed, falling back to popularity', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // Pure popularity fallback (no quiz, or quiz produced no matches).
        $movies = Movie::with('genres')
            ->orderByDesc('is_trending')
            ->orderByDesc('popularity')
            ->limit($count)
            ->get();

        if ($movies->isEmpty()) return collect();

        $this->persistColdStart($user, $movies, $batchId, 'Lagi populer di FLiK');

        return $movies;
    }

    /**
     * Shared persistence path for cold-start payloads.
     *
     * @param  Collection<int, Movie>  $movies
     */
    protected function persistColdStart(User $user, Collection $movies, string $batchId, string $reason): void
    {
        if ($movies->isEmpty()) return;

        $now = now();
        $rank = 0;
        $rows = $movies->map(function (Movie $m) use ($user, $batchId, $now, $reason, &$rank) {
            $rank++;
            return [
                'user_id'      => $user->id,
                'movie_id'     => $m->id,
                'score'        => round(max(0.1, 10 - $rank * 0.3), 3),
                'reason'       => $reason,
                'source'       => UserRecommendation::SOURCE_CONTENT_BASED,
                'batch_id'     => $batchId,
                'generated_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        })->all();

        DB::transaction(function () use ($user, $rows) {
            UserRecommendation::where('user_id', $user->id)->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                UserRecommendation::insert($chunk);
            }
        });
    }

    /**
     * Build a short Indonesian reason string from the user's onboarding answers.
     */
    protected function reasonFromPrefs(UserPreference $prefs): string
    {
        $genres = is_array($prefs->favorite_genres) ? $prefs->favorite_genres : [];
        $eras   = is_array($prefs->favorite_eras) ? $prefs->favorite_eras : [];

        $bits = [];
        if (!empty($genres)) {
            $bits[] = 'genre ' . implode(', ', array_slice(array_map('ucfirst', $genres), 0, 2));
        }
        if (!empty($eras)) {
            $bits[] = 'era ' . implode(', ', array_slice($eras, 0, 2));
        }

        return $bits ? implode(' & ', $bits) : 'pilihanmu';
    }
}
