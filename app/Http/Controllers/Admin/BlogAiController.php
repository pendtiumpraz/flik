<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tasks\BlogCopyAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BlogAiController — admin-only AJAX endpoints powering the AI assist
 * buttons in the post editor sidebar.
 *
 * Permission gate: `can:blog.manage` (routes/web.php). The underlying
 * BlogCopyAssistant is fail-soft (returns empty array / original text
 * on provider error), so even when the AI provider is down these
 * endpoints respond with HTTP 200 and the editor surfaces a friendly
 * fallback message rather than crashing the page.
 */
class BlogAiController extends Controller
{
    public function __construct(
        protected BlogCopyAssistant $assistant,
    ) {}

    public function suggestTitles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $titles = $this->assistant->suggestTitles($data['topic']);

        return response()->json([
            'ok'     => true,
            'titles' => $titles,
        ]);
    }

    public function outline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brief' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $outline = $this->assistant->outlineFrom($data['brief']);

        return response()->json([
            'ok'      => true,
            'outline' => $outline,
        ]);
    }

    public function enrich(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draft' => ['required', 'string', 'min:10', 'max:20000'],
        ]);

        $enriched = $this->assistant->enrich($data['draft']);

        return response()->json([
            'ok'       => true,
            'enriched' => $enriched,
        ]);
    }
}
