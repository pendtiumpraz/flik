<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\Ai\Tasks\PlotExplainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PlotExplainController extends Controller
{
    /**
     * Auth required — wired via route middleware('auth').
     * Defense-in-depth: still re-check inside the controller.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * POST /api/movies/{movie}/plot-explain
     *
     * Body:
     *   - question (string|nullable, max 500): optional user follow-up.
     *
     * Returns: { explanation, spoiler_warning, movie: { id, slug, title } }.
     * Rate-limited to 10/hour/user (PlotExplainer is an expensive AI call).
     */
    public function explain(
        Request $request,
        Movie $movie,
        PlotExplainer $explainer
    ): JsonResponse {
        $data = $request->validate([
            'question' => 'nullable|string|max:500',
        ]);

        $userId = (int) (auth()->id() ?? 0);
        $rateKey = 'plot-explain:' . ($userId ?: $request->ip());

        // 10 requests / hour / user
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            $retryAfter = RateLimiter::availableIn($rateKey);
            return response()->json([
                'error'       => 'Terlalu banyak permintaan analisis plot. Coba lagi sebentar.',
                'retry_after' => $retryAfter,
            ], 429);
        }
        RateLimiter::hit($rateKey, 3600);

        try {
            $result = $explainer->explain($movie, (string) ($data['question'] ?? ''));

            return response()->json([
                'explanation'     => $result['explanation'],
                'spoiler_warning' => (bool) $result['spoiler_warning'],
                'movie'           => [
                    'id'    => $movie->id,
                    'slug'  => $movie->slug,
                    'title' => $movie->title,
                ],
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('PlotExplainController: explainer runtime error', [
                'movie_id' => $movie->id,
                'user_id'  => $userId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'error'       => 'AI plot explainer belum tersedia. Admin bisa konfigurasi di /admin/ai-settings.',
                'explanation' => null,
            ], 503);
        } catch (\Throwable $e) {
            Log::error('PlotExplainController: unexpected failure', [
                'movie_id' => $movie->id,
                'user_id'  => $userId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'error'       => 'Terjadi kesalahan saat membuat penjelasan. Coba lagi sebentar.',
                'explanation' => null,
            ], 500);
        }
    }
}
