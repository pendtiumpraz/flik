<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Services\Ai\Tasks\PromoBannerGenerator;
use App\Services\Ai\Tasks\SocialMediaPostGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin-side AI marketing copy generators (promo banners + social posts).
 *
 * GET endpoints render the admin forms (per-movie). POST endpoints run the AI
 * task and return JSON for in-page preview rendering.
 *
 * Note: routes are NOT registered here — wire them in routes/web.php manually.
 */
class MarketingAiController extends Controller
{
    public function bannerForm(Movie $movie)
    {
        $movie->loadMissing('genres');

        return view('admin.marketing-ai.banner', [
            'movie' => $movie,
            'tones' => PromoBannerGenerator::TONES,
        ]);
    }

    public function generateBanner(
        Request $request,
        Movie $movie,
        PromoBannerGenerator $generator,
    ): JsonResponse {
        $data = $request->validate([
            'tone' => 'nullable|string|in:' . implode(',', PromoBannerGenerator::TONES),
        ]);

        $tone = $data['tone'] ?? 'cinematic';

        try {
            $result = $generator->generate($movie, $tone);

            return response()->json([
                'ok'    => true,
                'tone'  => $tone,
                'movie' => [
                    'id'    => $movie->id,
                    'title' => $movie->title,
                    'slug'  => $movie->slug,
                ],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingAi banner generation failed', [
                'movie_id' => $movie->id,
                'tone'     => $tone,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate banner: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function socialForm(Movie $movie)
    {
        $movie->loadMissing('genres');

        return view('admin.marketing-ai.social', [
            'movie'     => $movie,
            'platforms' => SocialMediaPostGenerator::PLATFORMS,
        ]);
    }

    public function generateSocial(
        Request $request,
        Movie $movie,
        SocialMediaPostGenerator $generator,
    ): JsonResponse {
        $data = $request->validate([
            'platform' => 'nullable|string|in:' . implode(',', SocialMediaPostGenerator::PLATFORMS),
        ]);

        $platform = $data['platform'] ?? 'instagram';

        try {
            $result = $generator->generate($movie, $platform);

            return response()->json([
                'ok'       => true,
                'platform' => $platform,
                'movie'    => [
                    'id'    => $movie->id,
                    'title' => $movie->title,
                    'slug'  => $movie->slug,
                ],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingAi social generation failed', [
                'movie_id' => $movie->id,
                'platform' => $platform,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate social post: ' . $e->getMessage(),
            ], 500);
        }
    }
}
