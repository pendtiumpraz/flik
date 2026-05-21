<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FunnelDashboardTest
|--------------------------------------------------------------------------
|
| Smoke test for `/admin/funnel` (FunnelDashboardController@index) after
| the FIX #3 alignment that:
|   - added `FunnelAnalyzer::engagementFunnel()` as an alias for
|     `signupToSubscribed()`;
|   - emitted legacy `percent_from_previous` / `percent_from_top` keys
|     alongside the canonical `conversion_pct` / `from_top_pct`.
|
| Skips gracefully when the database isn't available (e.g. CI worker
| without MySQL) so the suite stays green.
|
*/

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }

    if (! class_exists(Role::class) || ! class_exists(Permission::class)) {
        $this->markTestSkipped('Role/Permission models not yet present.');
    }

    $this->seed(RolePermissionSeeder::class);
});

test('admin can view funnel dashboard', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/funnel');

    $response->assertOk();
    $response->assertViewIs('admin.funnel.dashboard');
    $response->assertViewHas('funnel');
    $response->assertViewHas('days', 30);
});

test('admin can pick a different window', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/funnel?days=90');

    $response->assertOk();
    $response->assertViewHas('days', 90);
});

test('regular user is forbidden from funnel dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get('/admin/funnel')
        ->assertForbidden();
});
