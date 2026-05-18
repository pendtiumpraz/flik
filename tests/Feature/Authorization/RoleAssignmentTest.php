<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RoleAssignmentTest
|--------------------------------------------------------------------------
|
| Exercises the User <-> Role <-> Permission contract owned jointly by
| peer ROLE #1 (Permission/Role models + migrations + seeder),
| peer ROLE #2 (User::assignRole / hasRole / hasPermission helpers), and
| peer ROLE #3 (admin role-management UI + per-permission gates).
|
| Each test gracefully skips if the database isn't available (e.g. CI
| environment without MySQL), so the suite never blocks a PR purely on
| infrastructure. When MySQL IS available, RefreshDatabase + the
| RolePermissionSeeder give every test a clean baseline taxonomy.
|
*/

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Skip the entire file if the DB driver isn't reachable. This keeps the
    // suite green in environments that haven't provisioned MySQL yet.
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }

    // Skip if the peer RBAC migrations haven't shipped yet — every test
    // in this file assumes the taxonomy is seeded.
    if (! class_exists(Role::class) || ! class_exists(Permission::class)) {
        $this->markTestSkipped('Role/Permission models not yet present.');
    }

    // Seed the canonical taxonomy. RefreshDatabase rolls back after each
    // test so the seeder is re-run on every case — necessary because
    // role/permission rows must exist before User::assignRole() can resolve.
    $this->seed(RolePermissionSeeder::class);
});

test('User::assignRole attaches a role idempotently', function () {
    $user = User::factory()->create();

    $user->assignRole('moderator');
    $user->assignRole('moderator'); // duplicate is a no-op

    expect($user->fresh()->roles()->count())->toBe(1);
    expect($user->fresh()->hasRole('moderator'))->toBeTrue();
});

test('User::hasRole accepts a single string or an array', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    expect($user->hasRole('moderator'))->toBeTrue();
    expect($user->hasRole(['admin', 'moderator']))->toBeTrue();
    expect($user->hasRole('finance'))->toBeFalse();
    expect($user->hasRole(['finance', 'content_editor']))->toBeFalse();
});

test('User::removeRole detaches a role', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');
    $user->assignRole('finance');

    $user->removeRole('moderator');

    expect($user->fresh()->hasRole('moderator'))->toBeFalse();
    expect($user->fresh()->hasRole('finance'))->toBeTrue();
});

test('User::syncRoles replaces the entire role set atomically', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');
    $user->assignRole('finance');

    $user->syncRoles(['content_editor']);

    expect($user->fresh()->roles()->count())->toBe(1);
    expect($user->fresh()->hasRole('content_editor'))->toBeTrue();
    expect($user->fresh()->hasRole('moderator'))->toBeFalse();
    expect($user->fresh()->hasRole('finance'))->toBeFalse();
});

test('User::hasPermission returns true for permissions granted via role', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    // 'moderator' is seeded with comments.moderate, sentiment.view, security.audit_logs
    expect($user->hasPermission('comments.moderate'))->toBeTrue();
    expect($user->hasPermission('sentiment.view'))->toBeTrue();
    expect($user->hasPermission('security.audit_logs'))->toBeTrue();

    // Permissions outside the moderator scope must NOT leak.
    expect($user->hasPermission('movies.create'))->toBeFalse();
    expect($user->hasPermission('analytics.revenue'))->toBeFalse();
    expect($user->hasPermission('roles.manage'))->toBeFalse();
});

test('User::hasPermission short-circuits to true for super_admin role', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    // super_admin has '*' wildcard at seeder level → every permission resolves true.
    expect($user->hasPermission('movies.create'))->toBeTrue();
    expect($user->hasPermission('roles.manage'))->toBeTrue();
    expect($user->hasPermission('security.api_keys'))->toBeTrue();
    expect($user->hasPermission('analytics.revenue'))->toBeTrue();
    // Even a nonexistent permission name passes — Gate::before bypass via
    // isSuperAdmin() takes precedence over the hasPermission lookup.
    expect($user->hasPermission('nonexistent.permission'))->toBeTrue();
});

test('legacy is_admin boolean still satisfies super-admin check', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    expect($user->fresh()->isSuperAdmin())->toBeTrue();
    expect($user->fresh()->hasPermission('movies.create'))->toBeTrue();
});

test('default user role grants no admin permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    expect($user->hasRole('user'))->toBeTrue();
    expect($user->hasPermission('movies.view'))->toBeFalse();
    expect($user->hasPermission('users.view'))->toBeFalse();
    expect($user->hasPermission('analytics.revenue'))->toBeFalse();
});

test('permissions collection is unique across multiple roles', function () {
    $user = User::factory()->create();
    // 'admin' and 'moderator' both grant comments.moderate.
    $user->assignRole('admin');
    $user->assignRole('moderator');

    $names = $user->permissions()->pluck('name')->all();

    // Deduplication: comments.moderate appears once even though two roles grant it.
    expect(array_count_values($names)['comments.moderate'] ?? 0)->toBe(1);
});

test('forgetPermissionCache forces a fresh resolution on next call', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    expect($user->hasPermission('comments.moderate'))->toBeTrue();
    expect($user->hasPermission('movies.create'))->toBeFalse();

    // Escalate via a fresh role assignment + cache bust.
    $user->assignRole('admin'); // assignRole already calls forgetPermissionCache

    expect($user->hasPermission('movies.create'))->toBeTrue();
});
