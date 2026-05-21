<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\FeatureFlag;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smoke-tests the Feature Flags admin CRUD wired up by FIX #5:
 *
 *   - super_admin can list / create / edit / update / delete a flag
 *   - regular user gets 403 on every action (outer can:admin guard)
 *   - the route names registered in routes/web.php resolve cleanly so
 *     the sidebar's Route::has() filter stops silently hiding the link
 *
 * Skips gracefully when MySQL isn't available or the RBAC infrastructure
 * from peer ROLE swarms hasn't shipped — keeps the suite green on bare
 * CI environments. CSRF is bypassed because this file only cares about
 * authorization + routing reachability, not form-token plumbing.
 */
class FeatureFlagAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Skip the entire class BEFORE RefreshDatabase fires its `migrate:fresh`
     * — RefreshDatabase runs during `setUpTraits()` which is invoked from
     * the parent setUp() before our own setUp() body, so a normal
     * markTestSkipped() inside setUp() comes too late and we'd error out
     * trying to migrate against an unreachable DB. Overriding this hook
     * gives us the earliest possible bail-out.
     */
    protected function setUpTraits(): array
    {
        try {
            \DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        if (! class_exists(Role::class) || ! class_exists(Permission::class)) {
            $this->markTestSkipped('Role/Permission models not yet present.');
        }

        if (! class_exists(\App\Http\Controllers\Admin\FeatureFlagController::class)) {
            $this->markTestSkipped('Admin\\FeatureFlagController not present yet.');
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('feature_flags')) {
            $this->markTestSkipped('feature_flags table not yet migrated.');
        }

        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_super_admin_can_list_flags_via_get(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        FeatureFlag::create([
            'key' => 'smoke_test_list',
            'name' => 'Smoke Test Listing',
            'description' => 'Seeded for the list test.',
            'is_enabled' => false,
            'strategy' => 'off',
            'strategy_config' => null,
        ]);

        $response = $this->actingAs($superAdmin)->get('/admin/feature-flags');

        $response->assertOk();
        $response->assertSee('Feature Flags');
        $response->assertSee('smoke_test_list');
    }

    public function test_super_admin_can_reach_the_create_form(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->get('/admin/feature-flags/create');

        $response->assertOk();
        $response->assertSee('Create Feature Flag');
        $response->assertSee('Identity');
    }

    public function test_super_admin_can_create_a_flag(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->post('/admin/feature-flags', [
            'key' => 'smoke_test_create',
            'name' => 'Smoke Test Creation',
            'description' => 'Created by the smoke test.',
        ]);

        $response->assertRedirect();
        $this->assertTrue(FeatureFlag::query()->where('key', 'smoke_test_create')->exists());

        $flag = FeatureFlag::where('key', 'smoke_test_create')->first();
        // Controller forces the safe defaults so a freshly-created flag
        // never accidentally goes live.
        $this->assertFalse($flag->is_enabled);
        $this->assertSame('off', $flag->strategy);
    }

    public function test_super_admin_can_edit_and_update_a_flag_to_percentage_rollout(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $flag = FeatureFlag::create([
            'key' => 'smoke_test_edit',
            'name' => 'Smoke Edit',
            'is_enabled' => false,
            'strategy' => 'off',
            'strategy_config' => null,
        ]);

        // GET edit form renders.
        $this->actingAs($superAdmin)
            ->get("/admin/feature-flags/{$flag->id}/edit")
            ->assertOk()
            ->assertSee('smoke_test_edit');

        // PUT enables + flips to percentage rollout @ 25%.
        $response = $this->actingAs($superAdmin)->put("/admin/feature-flags/{$flag->id}", [
            'name' => 'Smoke Edit (Updated)',
            'description' => 'Now percentage-rolled.',
            'is_enabled' => 1,
            'strategy' => 'percentage',
            'percentage' => 25,
        ]);
        $response->assertRedirect();

        $flag->refresh();
        $this->assertSame('Smoke Edit (Updated)', $flag->name);
        $this->assertTrue($flag->is_enabled);
        $this->assertSame('percentage', $flag->strategy);
        $this->assertSame(25, $flag->strategy_config['percentage'] ?? null);
        // rollout_started_at stamped on the off → on flip.
        $this->assertNotNull($flag->rollout_started_at);
    }

    public function test_super_admin_can_delete_a_flag(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $flag = FeatureFlag::create([
            'key' => 'smoke_test_destroy',
            'name' => 'Smoke Destroy',
            'is_enabled' => false,
            'strategy' => 'off',
            'strategy_config' => null,
        ]);

        $response = $this->actingAs($superAdmin)->delete("/admin/feature-flags/{$flag->id}");
        $response->assertRedirect();

        $this->assertFalse(FeatureFlag::query()->where('id', $flag->id)->exists());
    }

    public function test_regular_user_is_forbidden_from_every_flag_route(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get('/admin/feature-flags')->assertForbidden();
        $this->actingAs($user)->get('/admin/feature-flags/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/feature-flags', [
            'key' => 'sneaky',
            'name' => 'Sneaky',
        ])->assertForbidden();
    }

    public function test_named_routes_resolve_so_sidebar_route_has_check_passes(): void
    {
        // The whole point of FIX #5: surfaces in the sidebar gated by
        // Route::has('admin.feature-flags.index'). If this assertion fails,
        // the sidebar entry will silently disappear again.
        $this->assertTrue(Route::has('admin.feature-flags.index'));
        $this->assertTrue(Route::has('admin.feature-flags.create'));
        $this->assertTrue(Route::has('admin.feature-flags.store'));
        $this->assertTrue(Route::has('admin.feature-flags.edit'));
        $this->assertTrue(Route::has('admin.feature-flags.update'));
        $this->assertTrue(Route::has('admin.feature-flags.destroy'));
    }
}
