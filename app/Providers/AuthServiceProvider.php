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
use App\Models\WatchHistory;
use App\Models\WatchParty;
use App\Models\Watchlist;
use App\Policies\CommentPolicy;
use App\Policies\KnownDevicePolicy;
use App\Policies\MovieSchedulePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\QuizAttemptPolicy;
use App\Policies\RatingPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\WatchHistoryPolicy;
use App\Policies\WatchlistPolicy;
use App\Policies\WatchPartyPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Comment::class       => CommentPolicy::class,
        KnownDevice::class   => KnownDevicePolicy::class,
        MovieSchedule::class => MovieSchedulePolicy::class,
        Notification::class  => NotificationPolicy::class,
        QuizAttempt::class   => QuizAttemptPolicy::class,
        Rating::class        => RatingPolicy::class,
        Subscription::class  => SubscriptionPolicy::class,
        WatchHistory::class  => WatchHistoryPolicy::class,
        Watchlist::class     => WatchlistPolicy::class,
        WatchParty::class    => WatchPartyPolicy::class,
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
        // policy/gate check. We only return true (never false) so that
        // the rest of the policy ladder still runs for non-admins —
        // returning false here would override per-method denials.
        //
        // We honour BOTH the legacy `is_admin` boolean and the modern
        // role column (`role === 'super_admin'`) so the bypass keeps
        // working before/after the role migration backfill.
        Gate::before(function (?User $user, string $ability) {
            if ($user === null) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        // Backwards-compat: existing routes use Gate::check('admin')
        // Admin = anyone with staff role (super_admin or any specialty).
        Gate::define('admin', fn ($user) => $user->isStaff() || $user->is_admin);

        // Granular gates per role
        Gate::define('super-admin', fn ($user) => $user->hasRole(\App\Models\User::ROLE_SUPER_ADMIN) || $user->is_admin);

        Gate::define('manage-content', fn ($user) =>
            $user->hasRole([\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_CONTENT_MANAGER]) || $user->is_admin
        );

        Gate::define('manage-users', fn ($user) =>
            $user->hasRole([\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_CUSTOMER_SUPPORT]) || $user->is_admin
        );

        Gate::define('manage-finance', fn ($user) =>
            $user->hasRole([\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_FINANCE]) || $user->is_admin
        );

        Gate::define('manage-system', fn ($user) =>
            $user->hasRole(\App\Models\User::ROLE_SUPER_ADMIN) || $user->is_admin
        );
    }
}
