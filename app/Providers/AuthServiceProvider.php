<?php

namespace App\Providers;

use App\Models\Comment;
use App\Models\KnownDevice;
use App\Models\MovieSchedule;
use App\Models\Notification;
use App\Models\QuizAttempt;
use App\Models\Rating;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserList;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use App\Models\WatchParty;
use App\Policies\CommentPolicy;
use App\Policies\KnownDevicePolicy;
use App\Policies\MovieSchedulePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\QuizAttemptPolicy;
use App\Policies\RatingPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\UserListPolicy;
use App\Policies\WatchHistoryPolicy;
use App\Policies\WatchlistPolicy;
use App\Policies\WatchPartyPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Comment::class => CommentPolicy::class,
        KnownDevice::class => KnownDevicePolicy::class,
        MovieSchedule::class => MovieSchedulePolicy::class,
        Notification::class => NotificationPolicy::class,
        QuizAttempt::class => QuizAttemptPolicy::class,
        Rating::class => RatingPolicy::class,
        Subscription::class => SubscriptionPolicy::class,
        UserList::class => UserListPolicy::class,
        WatchHistory::class => WatchHistoryPolicy::class,
        Watchlist::class => WatchlistPolicy::class,
        WatchParty::class => WatchPartyPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // ── Super-admin bypass ────────────────────────────────────
        // Returning `true` from a Gate::before short-circuits every
        // policy/gate check. We only return true (never false) so the
        // rest of the policy ladder still runs for non-admins —
        // returning false here would override per-method denials.
        //
        // Super-admin resolution lives entirely inside the User model
        // (`isSuperAdmin()`) so legacy `is_admin` boolean, legacy `role`
        // column, AND the modern pivot role are all honoured uniformly.
        Gate::before(function (?User $user, string $ability) {
            if ($user === null) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        // Backwards-compat: existing routes use `can:admin` middleware.
        // Routed through the new system: super_admin OR users with the
        // 'admin' role (pivot or legacy column). DO NOT rename this gate —
        // dozens of routes in routes/web.php reference it as `can:admin`.
        Gate::define('admin', fn (User $user) => $user->isSuperAdmin() || $user->hasRole('admin'));

        // Granular role-shorthand gates kept for back-compat with views
        // that already call `@can('manage-content')` etc. The permission
        // taxonomy registered below is the preferred path for new code.
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());

        Gate::define('manage-content', fn (User $user) => $user->isSuperAdmin() || $user->hasRole([User::ROLE_SUPER_ADMIN, User::ROLE_CONTENT_MANAGER, 'admin'])
        );

        Gate::define('manage-users', fn (User $user) => $user->isSuperAdmin() || $user->hasRole([User::ROLE_SUPER_ADMIN, User::ROLE_CUSTOMER_SUPPORT, 'admin'])
        );

        Gate::define('manage-finance', fn (User $user) => $user->isSuperAdmin() || $user->hasRole([User::ROLE_SUPER_ADMIN, User::ROLE_FINANCE, 'admin'])
        );

        Gate::define('manage-system', fn (User $user) => $user->isSuperAdmin() || $user->hasRole(User::ROLE_SUPER_ADMIN)
        );

        // ── Dynamic permission-name gates ─────────────────────────
        // Register one Gate::define() for every row in `permissions`
        // (e.g. `movies.create`, `users.delete`, `ai.providers.manage`).
        // Cached so we do not hammer the DB on every request — invalidated
        // when permissions are seeded/edited (clear via `php artisan cache:clear`).
        //
        // Defensive layers, in order:
        //   1. Permission model class must exist (peer agent's migration).
        //   2. `permissions` table must exist (skip on fresh install
        //      pre-migrate, otherwise `php artisan migrate` itself blows up
        //      because the framework boots providers before running migrations).
        //   3. Any unexpected error is logged and swallowed — auth must
        //      not crash the app at boot.
        try {
            if (class_exists(\App\Models\Permission::class) && Schema::hasTable('permissions')) {
                $names = \Illuminate\Support\Facades\Cache::remember(
                    'rbac.permission-names',
                    now()->addMinutes(15),
                    fn () => \App\Models\Permission::query()->pluck('name')->all(),
                );

                foreach ($names as $name) {
                    // Skip names that collide with the legacy gates above
                    // so the more specific implementation wins.
                    if (in_array($name, ['admin', 'super-admin', 'manage-content', 'manage-users', 'manage-finance', 'manage-system'], true)) {
                        continue;
                    }

                    Gate::define($name, fn (User $user) => $user->hasPermission($name));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('RBAC: failed to register dynamic permission gates', [
                'message' => $e->getMessage(),
            ]);
        }

        // ── Defensive fallback for unknown dotted-ability gates ───────────
        // Per-route admin guards use `->middleware('can:movies.create')` etc.
        // If the Permission model/table is absent (fresh install before
        // peer migrations) OR the seeder never ran, the `Gate::define()`
        // loop above registers nothing and `can:movies.create` would
        // resolve to 403 (default deny) — locking legacy `is_admin` users
        // out of their own admin panel.
        //
        // This `Gate::before` catches that case: for ANY dotted ability
        // (i.e. our permission-name convention) that has NO matching
        // Gate::define yet, fall back to the legacy `is_admin` flag.
        // Returning `null` (not `false`) when the legacy check fails lets
        // the Gate ladder continue, so once peer ROLE #2 registers the
        // real gate this hook becomes a no-op for known abilities.
        Gate::before(function (?User $user, string $ability) {
            if ($user === null) {
                return null;
            }

            // Only intervene for dotted permission-style abilities —
            // never override policy abilities (`view`, `update`) or the
            // explicit role-shorthand gates registered above.
            if (! str_contains($ability, '.')) {
                return null;
            }

            // If the gate is registered (peer ROLE #2 shipped + seed ran),
            // defer to it — return null lets the registered gate run.
            if (Gate::has($ability)) {
                return null;
            }

            // Unknown ability + legacy admin → allow (back-compat).
            // Unknown ability + regular user → null (continue to deny).
            return $user->is_admin ? true : null;
        });
    }
}
