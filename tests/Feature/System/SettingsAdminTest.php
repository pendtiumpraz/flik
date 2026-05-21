<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smoke-tests the Settings registry admin surface wired up by FIX #5:
 *
 *   - super_admin can list every setting via GET /admin/settings
 *   - super_admin can update a setting via POST /admin/settings, and
 *     Setting::get() reads the freshly-saved value back through the
 *     cache-busting hook (covers both controller AND model invalidation)
 *   - boolean settings flip cleanly from true → false
 *   - regular user gets 403 on every action (outer can:admin guard)
 *   - named routes resolve so the sidebar stops silently hiding them
 *
 * Skips cleanly when MySQL isn't available or RBAC isn't seeded.
 */
class SettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Skip the entire class BEFORE RefreshDatabase fires its `migrate:fresh`
     * — RefreshDatabase runs during `setUpTraits()` which is invoked from
     * the parent setUp() before our own setUp() body, so a normal
     * markTestSkipped() inside setUp() comes too late and we'd error out
     * trying to migrate against an unreachable DB.
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

        if (! class_exists(\App\Http\Controllers\Admin\SettingsController::class)) {
            $this->markTestSkipped('Admin\\SettingsController not present yet.');
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            $this->markTestSkipped('settings table not yet migrated.');
        }

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_super_admin_can_render_the_settings_index_page(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->get('/admin/settings');

        $response->assertOk();
        $response->assertSee('Settings');
        // Seeded keys should appear.
        $response->assertSee('site.name');
    }

    public function test_super_admin_can_update_a_setting_and_get_reads_it_back(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        // Use the seeded `site.name` row so we exercise the real schema path.
        $row = Setting::query()->where('key', 'site.name')->first();
        $this->assertNotNull($row);

        $newName = 'FLiK Test ' . uniqid();

        $response = $this->actingAs($superAdmin)->post('/admin/settings', [
            'settings' => [
                $row->id => $newName,
            ],
        ]);

        $response->assertRedirect();

        // The model's cache-bust hook must have fired so the helper reads fresh.
        $this->assertSame($newName, Setting::get('site.name'));

        // And the DB row reflects the same.
        $row->refresh();
        $this->assertSame($newName, $row->value);
    }

    public function test_super_admin_can_flip_a_boolean_setting_from_true_to_false(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $row = Setting::query()->where('key', 'features.show_trending_shelf')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->value);

        // Send "0" explicitly to emulate the form's hidden-zero trick that
        // ensures an unchecked checkbox still POSTs a value.
        $response = $this->actingAs($superAdmin)->post('/admin/settings', [
            'settings' => [
                $row->id => '0',
            ],
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) Setting::get('features.show_trending_shelf'));
    }

    public function test_regular_user_is_forbidden_from_every_settings_route(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
        $this->actingAs($user)->post('/admin/settings', ['settings' => []])->assertForbidden();
    }

    public function test_named_settings_routes_resolve(): void
    {
        $this->assertTrue(Route::has('admin.settings.index'));
        $this->assertTrue(Route::has('admin.settings.update'));
        $this->assertTrue(Route::has('admin.settings.restore-defaults'));
    }
}
