<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PushSubscriptionController
 * --------------------------------------------------------------------------
 * Browser-facing subscribe / unsubscribe endpoints. Auth is OPTIONAL —
 * anonymous visitors may opt in to push and we'll address them via the
 * `segment:anonymous` audience. Once they sign in, the browser re-runs
 * subscribe() with the same endpoint and we upsert the user_id onto the
 * existing row.
 *
 * When VAPID is not configured both endpoints return HTTP 503 with a
 * helpful message so the client-side JS can surface a single banner
 * instead of crashing with a generic error.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(
        private readonly WebPushSender $sender,
    ) {
    }

    /**
     * POST /api/push/subscribe
     */
    public function subscribe(Request $request): JsonResponse
    {
        if (! $this->sender->enabled()) {
            return $this->notConfigured();
        }

        $data = $request->validate([
            'endpoint'       => ['required', 'string', 'url', 'max:2048'],
            'keys'           => ['required', 'array'],
            'keys.p256dh'    => ['required', 'string', 'max:128'],
            'keys.auth'      => ['required', 'string', 'max:40'],
            'userAgent'      => ['sometimes', 'nullable', 'string', 'max:512'],
            'deviceType'     => ['sometimes', 'nullable', 'string', 'in:mobile,tablet,desktop'],
        ]);

        $userId = Auth::id();

        // Upsert by endpoint. We look up by the deterministic sha1 hash
        // (the unique-indexed column) instead of the raw TEXT endpoint —
        // same row, much cheaper, and the unique index is the race-safe
        // safety net.
        $sub = PushSubscription::query()
            ->where('endpoint_hash', sha1($data['endpoint']))
            ->first();

        if ($sub === null) {
            $sub = new PushSubscription();
            $sub->endpoint = $data['endpoint'];
        }

        $sub->user_id     = $userId;
        $sub->p256dh      = $data['keys']['p256dh'];
        $sub->auth_key    = $data['keys']['auth'];
        $sub->user_agent  = $data['userAgent'] ?? substr((string) $request->userAgent(), 0, 512);
        $sub->device_type = $data['deviceType'] ?? $this->guessDeviceType($sub->user_agent);
        $sub->failure_count = 0;
        $sub->save();

        return response()->json([
            'success' => true,
            'id'      => $sub->id,
        ]);
    }

    /**
     * POST /api/push/unsubscribe
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        if (! $this->sender->enabled()) {
            return $this->notConfigured();
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
        ]);

        PushSubscription::query()
            ->where('endpoint_hash', sha1($data['endpoint']))
            ->delete();

        return response()->json(['success' => true]);
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'reason'  => 'not_configured',
            'message' => 'Web push is not configured on this server. Set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in .env.',
        ], 503);
    }

    /**
     * Best-effort device classification — purely for admin audience picking,
     * never load-bearing.
     */
    private function guessDeviceType(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }
        $lower = strtolower($ua);
        if (str_contains($lower, 'ipad') || str_contains($lower, 'tablet')) {
            return 'tablet';
        }
        if (str_contains($lower, 'mobile') || str_contains($lower, 'android') || str_contains($lower, 'iphone')) {
            return 'mobile';
        }
        return 'desktop';
    }
}
