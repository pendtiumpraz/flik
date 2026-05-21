<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CohortExportTest
|--------------------------------------------------------------------------
|
| Smoke test for the `admin.cohorts.export` named route. Prior to FIX #3
| the route mapped to `CohortDashboardController@export` which did not
| exist publicly (the actual implementation was a protected `exportCsv()`
| helper invoked only from `index()` when `?export=csv` was passed).
|
| The fix adds a public `export()` method that builds the matrix, audits
| the action, and returns the streamed CSV via the same helper.
|
| Skips when the DB isn't available so CI workers without MySQL stay green.
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

test('admin can export cohort csv via dedicated route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/cohorts/export.csv');

    $response->assertOk();
    // Header includes a `; charset=UTF-8` suffix — assert prefix-match.
    expect($response->headers->get('Content-Type'))
        ->toStartWith('text/csv');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('cohort-retention-');
});

test('admin can export monthly cohort csv', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/cohorts/export.csv?type=monthly');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toStartWith('text/csv');
});

test('regular user is forbidden from exporting cohort csv', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get('/admin/cohorts/export.csv')
        ->assertForbidden();
});
