<?php

namespace App\Http\Controllers;

use App\Models\UserRecommendation;
use App\Services\Ai\Recommendations\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function __construct(protected RecommendationEngine $engine) {}

    /**
     * GET /api/recommendations  (or wherever wired in routes/web.php)
     *
     * Cache-first: serves the Redis-cached movie ID list if available,
     * otherwise reads the latest persisted batch from user_recommendations.
     * As a last resort it computes synchronously (cold start).
     */
    public function forUser(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $limit = (int) $request->query('limit', 20);
        $limit = max(1, min(50, $limit));

        // 1. Redis cache
        $movies = $this->engine->getCached($user);

        // 2. Fall back to DB (latest persisted batch)
        if (!$movies || $movies->isEmpty()) {
            $movies = $this->loadFromDatabase($user->id, $limit);
        }

        // 3. Last resort — compute now
        if (!$movies || $movies->isEmpty()) {
            $movies = $this->engine->computeFor($user, $limit);
        }

        // Pull reasons from latest batch so the UI can show them
        $reasons = UserRecommendation::query()
            ->where('user_id', $user->id)
            ->whereIn('movie_id', $movies->pluck('id'))
            ->orderByDesc('generated_at')
            ->get()
            ->keyBy('movie_id');

        $payload = $movies->take($limit)->map(function ($movie) use ($reasons) {
            $reco = $reasons->get($movie->id);
            return [
                'id'           => $movie->id,
                'slug'         => $movie->slug,
                'title'        => $movie->title,
                'overview'     => $movie->overview,
                'poster_url'   => $movie->poster_url,
                'backdrop_url' => $movie->backdrop_url,
                'vote_average' => (float) $movie->vote_average,
                'year'         => optional($movie->release_date)->format('Y'),
                'url'          => '/movie/' . $movie->slug,
                'genres'       => $movie->relationLoaded('genres')
                    ? $movie->genres->pluck('name')
                    : [],
                'reason'       => $reco?->reason,
                'source'       => $reco?->source,
                'score'        => $reco ? (float) $reco->score : null,
            ];
        })->values();

        return response()->json([
            'count'           => $payload->count(),
            'recommendations' => $payload,
            'cache_hit'       => (bool) $this->engine->getCached($user),
        ]);
    }

    /**
     * Load latest persisted batch from DB and hydrate to Movie collection.
     */
    protected function loadFromDatabase(int $userId, int $limit): \Illuminate\Support\Collection
    {
        $latestBatch = UserRecommendation::where('user_id', $userId)
            ->orderByDesc('generated_at')
            ->value('batch_id');

        if (!$latestBatch) return collect();

        return UserRecommendation::with(['movie.genres'])
            ->where('user_id', $userId)
            ->where('batch_id', $latestBatch)
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->pluck('movie')
            ->filter()
            ->values();
    }
}
