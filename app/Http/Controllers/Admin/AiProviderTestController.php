<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Services\Ai\ProviderTester;
use Illuminate\Http\JsonResponse;

class AiProviderTestController extends Controller
{
    public function __construct(private readonly ProviderTester $tester)
    {
    }

    /**
     * POST /admin/ai-settings/{aiProvider}/test
     *
     * Runs a minimal connectivity probe against the given provider row and
     * returns the structured result as JSON for the admin UI's inline display.
     */
    public function test(AiProvider $aiProvider): JsonResponse
    {
        $result = $this->tester->test($aiProvider);

        // Echo provider identity so the UI can label the row without re-fetching.
        $result['provider_id']    = $aiProvider->id;
        $result['provider_label'] = $aiProvider->provider_label;

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
