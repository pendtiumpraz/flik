<?php

namespace App\Providers;

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
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

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
