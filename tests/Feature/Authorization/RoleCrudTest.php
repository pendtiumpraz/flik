<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RoleCrudTest
|--------------------------------------------------------------------------
|
| Exercises the admin role-management UI (peer ROLE #3):
|   - super_admin can create / update / delete custom roles
|   - admin (role) cannot manage roles → 403
|   - system roles cannot be deleted → 403
|
| The CSRF check is bypassed by `withoutMiddleware(VerifyCsrfToken::class)`
| so the tests focus purely on authorization. Skips gracefully if MySQL
| is unavailable or peer ROLE #1's Role/Permission models haven't shipped.
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

    if (! class_exists(\App\Http\Controllers\Admin\RoleController::class)) {
        $this->markTestSkipped('Admin\\RoleController (peer ROLE #3) not present yet.');
    }

    $this->seed(RolePermissionSeeder::class);

    // Avoid CSRF noise — this file only cares about authorization.
    $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
});

test('super_admin can create a custom role via POST /admin/roles', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $response = $this->actingAs($superAdmin)->post('/admin/roles', [
        'name' => 'devops',
        'display_name' => 'DevOps Engineer',
        'description' => 'Manages CI/CD pipelines and infra dashboards.',
        'priority' => 60,
    ]);

    // Successful create redirects to the edit page.
    $response->assertRedirect();
    expect(Role::query()->where('name', 'devops')->exists())->toBeTrue();

    $role = Role::where('name', 'devops')->first();
    expect($role->is_system)->toBeFalse(); // controller forces is_system=false for new roles
    expect($role->display_name)->toBe('DevOps Engineer');
});

test('super_admin can update a custom role via PUT /admin/roles/{role}', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $role = Role::create([
        'name' => 'analyst',
        'display_name' => 'Data Analyst',
        'description' => 'Initial description',
        'is_system' => false,
        'priority' => 70,
    ]);

    $response = $this->actingAs($superAdmin)->put("/admin/roles/{$role->id}", [
        'name' => 'analyst', // unchanged
        'display_name' => 'Senior Data Analyst',
        'description' => 'Updated description',
        'priority' => 65,
        'permissions' => [], // empty matrix is valid
    ]);

    $response->assertRedirect();

    $role->refresh();
    expect($role->display_name)->toBe('Senior Data Analyst');
    expect($role->description)->toBe('Updated description');
    expect($role->priority)->toBe(65);
});

test('super_admin can delete a custom role via DELETE /admin/roles/{role}', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $role = Role::create([
        'name' => 'temp_role',
        'display_name' => 'Temporary',
        'is_system' => false,
        'priority' => 100,
    ]);

    $response = $this->actingAs($superAdmin)->delete("/admin/roles/{$role->id}");
    $response->assertRedirect();

    expect(Role::query()->where('id', $role->id)->exists())->toBeFalse();
});

test('admin role cannot manage roles — POST /admin/roles is 403', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    // Sanity — admin lacks roles.manage in the seeded taxonomy.
    expect($admin->hasPermission('roles.manage'))->toBeFalse();

    $this->actingAs($admin)->get('/admin/roles')->assertForbidden();
    $this->actingAs($admin)->get('/admin/roles/create')->assertForbidden();
    $this->actingAs($admin)
        ->post('/admin/roles', [
            'name' => 'sneaky',
            'display_name' => 'Sneaky',
            'priority' => 50,
        ])
        ->assertForbidden();
});

test('moderator role cannot manage roles — every CRUD action is 403', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $existingRole = Role::query()->where('name', 'moderator')->first();

    $this->actingAs($moderator)->get('/admin/roles')->assertForbidden();
    $this->actingAs($moderator)->get('/admin/roles/create')->assertForbidden();
    $this->actingAs($moderator)->get("/admin/roles/{$existingRole->id}/edit")->assertForbidden();
    $this->actingAs($moderator)
        ->put("/admin/roles/{$existingRole->id}", ['display_name' => 'Hacked'])
        ->assertForbidden();
    $this->actingAs($moderator)->delete("/admin/roles/{$existingRole->id}")->assertForbidden();
});

test('system role cannot be deleted even by super_admin — 403 abort', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $systemRole = Role::query()->where('is_system', true)->first();
    expect($systemRole)->not->toBeNull();

    $response = $this->actingAs($superAdmin)->delete("/admin/roles/{$systemRole->id}");
    $response->assertForbidden();

    // And the role is still there.
    expect(Role::query()->where('id', $systemRole->id)->exists())->toBeTrue();
});

test('regular user gets 403 on every admin role route (outer can:admin guard)', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get('/admin/roles')->assertForbidden();
    $this->actingAs($user)->get('/admin/roles/create')->assertForbidden();
    $this->actingAs($user)
        ->post('/admin/roles', ['name' => 'x', 'display_name' => 'X', 'priority' => 1])
        ->assertForbidden();
});
