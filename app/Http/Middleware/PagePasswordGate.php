<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * PagePasswordGate — single-shot password gate for sensitive admin pages.
 *
 * UX contract:
 *   - GET request without correct `_pgate` POST field → render gate form.
 *   - POST request with `_pgate` matching the stored password → render the
 *     wrapped controller's response normally.
 *   - No session memoisation — every page reload re-prompts (per user
 *     explicit request). This is intentionally hostile so screen-share
 *     audiences can't peek at the doc after the admin walks away.
 *
 * Password source: `settings` table, key `pages.protected_password`.
 * Default ott2026 is seeded by SettingSeeder + PagePasswordGateSeeder.
 *
 * Usage: route()->middleware('page-password')
 */
class PagePasswordGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->resolvePassword();

        // Submitted via POST → check + render real content if correct.
        if ($request->isMethod('POST') && $request->has('_pgate')) {
            $submitted = (string) $request->input('_pgate');
            if (hash_equals($expected, $submitted)) {
                // Re-dispatch as GET so the wrapped controller sees a normal
                // request. The form POSTs to the same URL, so this is the
                // canonical place to swap method back.
                $request->setMethod('GET');
                return $next($request);
            }
            return $this->gate($request, 'Password salah. Coba lagi.');
        }

        return $this->gate($request);
    }

    /**
     * Resolve the gate password from the `settings` table.
     * Falls back to `ott2026` if the table or row is missing (so the gate
     * never accidentally becomes wide-open).
     */
    protected function resolvePassword(): string
    {
        $default = 'ott2026';
        try {
            if (! Schema::hasTable('settings')) {
                return $default;
            }
            $val = Setting::get('pages.protected_password', $default);
            return is_string($val) && $val !== '' ? $val : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected function gate(Request $request, ?string $error = null): Response
    {
        return response()->view('admin.gate.password', [
            'error' => $error,
            'target' => $request->fullUrl(),
        ], 200);
    }
}
