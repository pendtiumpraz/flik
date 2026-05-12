<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Services\Ai\Tasks\CustomerSupportReplyDrafter;
use App\Services\Ai\Tasks\EmailSubjectAbTester;
use App\Services\Ai\Tasks\TikTokClipSuggester;
use App\Services\Ai\Tasks\TitleAlternativeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin-side AI marketing/operations endpoints (TikTok clips, title alternatives,
 * email subject A/B tests, customer-support reply drafter).
 *
 * GET endpoints render the form views; POST endpoints execute the underlying AI
 * task and return JSON for in-page preview rendering.
 *
 * NOTE: routes are NOT registered here — wire them in routes/web.php manually:
 *   GET/POST /admin/movies/{movie}/marketing-ops/tiktok-clips
 *   GET/POST /admin/movies/{movie}/marketing-ops/title-alternatives
 *   GET/POST /admin/marketing-ops/email-subjects
 *   POST     /admin/marketing-ops/cs-reply
 */
class MarketingOpsController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // M2 — TikTok Clip Suggester
    // ─────────────────────────────────────────────────────────────

    public function tikTokClipsForm(Movie $movie)
    {
        $movie->loadMissing('genres');

        return view('admin.marketing-ops.tiktok-clips', [
            'movie' => $movie,
        ]);
    }

    public function generateTikTokClips(
        Request $request,
        Movie $movie,
        TikTokClipSuggester $suggester,
    ): JsonResponse {
        $data = $request->validate([
            'count' => 'nullable|integer|min:1|max:10',
        ]);

        $count = (int) ($data['count'] ?? 3);

        try {
            $clips = $suggester->suggest($movie, $count);

            return response()->json([
                'ok'    => true,
                'movie' => [
                    'id'    => $movie->id,
                    'title' => $movie->title,
                    'slug'  => $movie->slug,
                ],
                'count' => count($clips),
                'data'  => $clips,
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingOps TikTok clip generation failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate TikTok clips: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // M9 — Title Alternative Generator
    // ─────────────────────────────────────────────────────────────

    public function titleAlternativesForm(Movie $movie)
    {
        $movie->loadMissing('genres');

        return view('admin.marketing-ops.title-alternatives', [
            'movie' => $movie,
        ]);
    }

    public function generateTitleAlternatives(
        Request $request,
        Movie $movie,
        TitleAlternativeGenerator $generator,
    ): JsonResponse {
        $data = $request->validate([
            'count' => 'nullable|integer|min:1|max:' . TitleAlternativeGenerator::MAX_COUNT,
        ]);

        $count = (int) ($data['count'] ?? TitleAlternativeGenerator::DEFAULT_COUNT);

        try {
            $alternatives = $generator->generate($movie, $count);

            return response()->json([
                'ok'    => true,
                'movie' => [
                    'id'             => $movie->id,
                    'title'          => $movie->title,
                    'slug'           => $movie->slug,
                    'original_title' => $movie->original_title,
                ],
                'count' => count($alternatives),
                'data'  => $alternatives,
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingOps title alternative generation failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate alternatif judul: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // M10 — Email Subject A/B Tester
    // ─────────────────────────────────────────────────────────────

    public function emailSubjectsForm()
    {
        return view('admin.marketing-ops.email-subjects', [
            'intents' => EmailSubjectAbTester::KNOWN_INTENTS,
            'tones'   => EmailSubjectAbTester::TONES,
        ]);
    }

    public function generateEmailSubjects(
        Request $request,
        EmailSubjectAbTester $tester,
    ): JsonResponse {
        $data = $request->validate([
            'intent'   => 'required|string|max:80',
            'variants' => 'nullable|integer|min:1|max:' . EmailSubjectAbTester::MAX_VARIANTS,
            'context'  => 'nullable|array',
        ]);

        $intent   = $data['intent'];
        $variants = (int) ($data['variants'] ?? EmailSubjectAbTester::DEFAULT_VARIANTS);
        $context  = $data['context'] ?? [];

        try {
            $subjects = $tester->generate($intent, $context, $variants);

            return response()->json([
                'ok'       => true,
                'intent'   => $intent,
                'variants' => count($subjects),
                'data'     => $subjects,
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingOps email subject generation failed', [
                'intent' => $intent,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate subject lines: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // D7 — Customer Support Reply Drafter
    // ─────────────────────────────────────────────────────────────

    public function csReplyDraft(
        Request $request,
        CustomerSupportReplyDrafter $drafter,
    ): JsonResponse {
        $data = $request->validate([
            'query'    => 'required|string|max:5000',
            'category' => 'nullable|string|in:' . implode(',', CustomerSupportReplyDrafter::CATEGORIES),
            'context'  => 'nullable|array',
        ]);

        $query    = $data['query'];
        $category = $data['category'] ?? 'general';
        $context  = $data['context']  ?? [];

        try {
            $draft = $drafter->draft($query, $context, $category);

            return response()->json([
                'ok'       => true,
                'category' => $category,
                'data'     => [
                    'draft'      => $draft,
                    'word_count' => str_word_count($draft),
                    'char_count' => mb_strlen($draft),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketingOps CS reply draft failed', [
                'category' => $category,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Gagal generate draft balasan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
