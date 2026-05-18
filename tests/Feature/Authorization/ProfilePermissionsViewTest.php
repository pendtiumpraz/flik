<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ProfilePermissionsViewTest
|--------------------------------------------------------------------------
|
| Renders the self-service "My Roles & Permissions" page (route
| `profile.permissions`) for each role tier and verifies the right
| sections appear without crashing when role/permission tables are
| missing or empty.
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
});

test('unauthenticated user is redirected to login', function () {
    $this->get('/profile/permissions')->assertRedirect('/login');
});

test('regular user without any role can still load the page (no crash)', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/permissions');

    $response->assertOk();
    $response->assertSee('My Roles &amp; Permissions', false);
});

test('user with assigned roles sees their roles listed', function () {
    if (! class_exists(Role::class) || ! class_exists(Permission::class)) {
        $this->markTestSkipped('Role/Permission models not yet present.');
    }

    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('moderator');

    $response = $this->actingAs($user)->get('/profile/permissions');

    $response->assertOk();
    $response->assertSee('Moderator');
    // The seeded moderator role has comments.moderate permission.
    $response->assertSee('comments.moderate');
});

test('super_admin sees the gold "all permissions granted" sentinel banner', function () {
    if (! class_exists(Role::class) || ! class_exists(Permission::class)) {
        $this->markTestSkipped('Role/Permission models not yet present.');
    }

    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)->get('/profile/permissions');

    $response->assertOk();
    $response->assertSee('Super Admin');
    $response->assertSee('all permissions granted');
});

test('legacy is_admin=true is treated as super_admin in the view', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    $response = $this->actingAs($user)->get('/profile/permissions');

    $response->assertOk();
    $response->assertSee('all permissions granted');
});
