<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureOwnership middleware.
 *
 * Defence-in-depth IDOR guard for routes whose route-bound model carries
 * a `user_id` column. Use this when wiring a full Policy is overkill
 * (e.g. a single ad-hoc endpoint) — it short-circuits with 403 unless
 * the resolved model's `user_id` matches `auth()->id()`, OR the caller
 * is a super-admin.
 *
 * Usage examples (routes/web.php):
 *
 *   Route::get('/my-schedule/{schedule}/ics', [ScheduleController::class, 'ics'])
 *       ->middleware('owns:schedule');
 *
 *   Route::post('/notifications/{notification}/read', [...])
 *       ->middleware('owns:notification');
 *
 *   // Custom column name (defaults to `user_id`):
 *   Route::delete('/comment/{comment}', [...])
 *       ->middleware('owns:comment,user_id');
 *
 * Register in app/Http/Kernel.php as `'owns' => EnsureOwnership::class`.
 */
class EnsureOwnership
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $param   route parameter name carrying the model
     * @param  string  $column  ownership column on the model (default: user_id)
     */
    public function handle(Request $request, Closure $next, string $param, string $column = 'user_id'): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication required.');
        }

        // Super-admin bypass — mirrors AuthServiceProvider::Gate::before.
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $model = $request->route($param);

        if (! $model instanceof Model) {
            // Either the binding failed or the param doesn't exist —
            // 404 is more honest than 403 here.
            abort(404, 'Resource not found.');
        }

        $owner = $model->getAttribute($column);

        if ($owner === null) {
            // The column doesn't exist on this model — programmer error,
            // refuse loudly so it surfaces in dev rather than silently
            // letting requests through.
            abort(500, "Ownership column [{$column}] missing on " . $model::class);
        }

        if ((int) $owner !== (int) $user->id) {
            abort(403, 'Bukan milik kamu.');
        }

        return $next($request);
    }
}
