<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PwaInstall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PwaInstallTrackController
 * --------------------------------------------------------------------------
 * Backs POST /api/pwa/track-install. Fired by resources/js/pwa-install.js
 * after the `appinstalled` event or a Chromium userChoice resolution.
 *
 * Auth is OPTIONAL — guests can install the PWA too. We hash the request
 * IP rather than storing it raw so the table doesn't accumulate PII.
 *
 * IP HASH PEPPER (FIX #10, audit 19 PWA-8): a bare sha256(ip) is trivially
 * reversible by brute-forcing the ~4 billion IPv4 keyspace, so a table leak
 * would de-anonymise every install row to a real IP. We mirror the
 * App\Services\Trending\ViewTracker::resolveIpHash() pattern and pepper with
 * APP_KEY — without the pepper an attacker cannot reverse the hash even with
 * the full table dump.
 *
 * Failures are silent (`{ok: false}` with HTTP 200) — telemetry MUST NOT
 * surface red noise in the browser console for legitimate install events.
 */
class PwaInstallTrackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'max:32'],
            'device'  => ['nullable', 'string', 'max:64'],
            'ua'      => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            PwaInstall::create([
                'user_id'      => $request->user()?->id,
                'device'       => $data['device'] ?? null,
                'ua'           => $data['ua'] ?? substr((string) $request->userAgent(), 0, 1024),
                'outcome'      => $data['outcome'] ?? 'installed',
                // Peppered IP hash — see class docblock (audit 19 PWA-8).
                // The pepper is APP_KEY so the hash is irreversible even on
                // a full table leak. Mirrors ViewTracker::resolveIpHash().
                'ip_hash'      => hash('sha256', (string) $request->ip() . '|' . (string) config('app.key')),
                'installed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Swallow — never let telemetry break the install UX.
            return response()->json(['ok' => false], 200);
        }

        return response()->json(['ok' => true], 201);
    }
}
