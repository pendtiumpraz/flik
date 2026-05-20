<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MaintenanceState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CheckCustomMaintenance
 * --------------------------------------------------------------------------
 * Application-level maintenance gate. Sits AFTER the session middleware in
 * the web stack so $request->user() is hydrated — we need to know identity
 * to honour the role-based bypass rules.
 *
 * Short-circuits (always proceeds):
 *   - /admin/maintenance*  → so admins can disable the switch they enabled.
 *   - /login + /logout     → so a stranded super-admin can authenticate.
 *   - /healthz*            → load balancer probes must keep working.
 *   - asset URLs           → CSS/JS/images on the maintenance page itself.
 *
 * When triggered the user gets a themed 503 page rendered from
 * resources/views/errors/maintenance-custom.blade.php with the configured
 * message + countdown.
 *
 * Failure mode: if the table is missing or any check raises, the request
 * is allowed through — we never want this guard to take the site down by
 * accident.
 */
class CheckCustomMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        // Hard-coded escape hatches — these MUST always work, otherwise
        // there's no way back from a misconfigured maintenance window.
        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        try {
            // Skip cheaply when the migration hasn't run yet (CI, fresh
            // install, partial deploy). Schema::hasTable is itself cached
            // by the connection, so this is essentially free after the
            // first hit per process.
            if (! Schema::hasTable('maintenance_state')) {
                return $next($request);
            }

            $state = MaintenanceState::current();

            if (! $state->isEnabled()) {
                return $next($request);
            }

            if ($state->userCanBypass($request->user(), (string) $request->ip())) {
                return $next($request);
            }

            return $this->renderMaintenancePage($state);
        } catch (Throwable $e) {
            // FAIL OPEN. A bug in this middleware MUST NOT take the site
            // down — that's the opposite of what an admin asked for when
            // they flipped the switch.
            report($e);

            return $next($request);
        }
    }

    /**
     * The escape-hatch routes that must always reach the app, even when
     * maintenance is on. Anything else is gated.
     */
    private function isAlwaysAllowed(Request $request): bool
    {
        if ($request->is('admin/maintenance*')) {
            return true;
        }
        if ($request->is('login') || $request->is('logout') || $request->is('2fa*')) {
            return true;
        }
        if ($request->is('healthz') || $request->is('healthz/*')) {
            return true;
        }
        // Vite/static assets needed to render the 503 page itself.
        if ($request->is('build/*') || $request->is('storage/*') || $request->is('favicon.ico')) {
            return true;
        }

        return false;
    }

    private function renderMaintenancePage(MaintenanceState $state): Response
    {
        $payload = [
            'message'         => $state->message,
            'scheduledUntil'  => $state->scheduled_until,
        ];

        $view = view('errors.maintenance-custom', $payload);

        return response($view, 503)
            ->header('Retry-After', $this->retryAfterSeconds($state))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function retryAfterSeconds(MaintenanceState $state): int
    {
        if ($state->scheduled_until === null) {
            return 3600; // 1h default — a sensible "check back later"
        }

        $diff = now()->diffInSeconds($state->scheduled_until, false);

        // diffInSeconds returns a negative value when scheduled_until is in
        // the past. Floor at 60s so over-runs still suggest a polite retry
        // cadence rather than the client hammering us instantly.
        return max(60, (int) $diff);
    }
}
