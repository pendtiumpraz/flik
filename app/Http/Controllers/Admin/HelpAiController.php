<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tasks\HelpArticleAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only AJAX endpoints that power the AI assist buttons in the
 * Help Center article editor sidebar.
 *
 * Permission gate: `can:help.manage` (routes/web.php). Each method
 * mirrors BlogAiController's contract — { ok: bool, ... } JSON so the
 * Alpine editor can render success/failure without inspecting HTTP status.
 *
 * The underlying HelpArticleAssistant is fail-soft: on provider outage it
 * returns empty arrays / original input, and these endpoints surface that
 * with HTTP 200 + ok=true so the editor can show a friendly empty state
 * instead of crashing the page.
 */
class HelpAiController extends Controller
{
    public function __construct(
        protected HelpArticleAssistant $assistant,
    ) {}

    public function suggestTitle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $titles = $this->assistant->suggestTitle($data['question']);

        return response()->json([
            'ok'     => true,
            'titles' => $titles,
        ]);
    }

    public function draftAnswer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $draft = $this->assistant->draftAnswer($data['question']);

        return response()->json([
            'ok'    => true,
            'draft' => $draft,
        ]);
    }

    public function improve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'existing' => ['required', 'string', 'min:10', 'max:30000'],
        ]);

        $improved = $this->assistant->improveArticle($data['existing']);

        return response()->json([
            'ok'       => true,
            'improved' => $improved,
        ]);
    }
}
