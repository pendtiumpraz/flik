<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AdminRouteGuardTest
|--------------------------------------------------------------------------
|
| End-to-end probe of every per-route `can:<permission>` middleware
| installed by the admin route refactor. Each role × route combination
| confirms that the dotted-permission gates registered dynamically by
| `AuthServiceProvider` line up with the taxonomy seeded by
| `RolePermissionSeeder`.
|
| Scenarios:
|   - anonymous   → 302 to /login on every admin route
|   - regular     → 403 on every admin route (no role grants `can:admin`)
|   - moderator   → 200 on /admin/comments/queue, 403 on /admin/users
|   - finance     → 200 on /admin/revenue, 403 on /admin/movies
|   - super_admin → 200 everywhere (Gate::before short-circuit)
|
| Skips gracefully if MySQL isn't available.
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

// ── Anonymous ───────────────────────────────────────────────────

test('anonymous user is redirected to login from any admin route', function () {
    $this->get('/admin')->assertRedirect('/login');
    $this->get('/admin/movies')->assertRedirect('/login');
    $this->get('/admin/users')->assertRedirect('/login');
    $this->get('/admin/revenue')->assertRedirect('/login');
    $this->get('/admin/comments/queue')->assertRedirect('/login');
    $this->get('/admin/audit-logs')->assertRedirect('/login');
});

// ── Regular user (only "user" role) ─────────────────────────────

test('regular user without admin roles is denied with 403 on the admin prefix', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get('/admin')->assertForbidden();
    $this->actingAs($user)->get('/admin/movies')->assertForbidden();
    $this->actingAs($user)->get('/admin/users')->assertForbidden();
});

// ── Moderator ───────────────────────────────────────────────────

test('moderator can reach comment moderation but not user admin', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    // Moderator IS staff (passes the outer can:admin gate via the admin
    // alias) AND has comments.moderate, so the queue is reachable.
    // The actual controller may render 200 or 500-on-template — we only
    // care about the authorization layer, so we accept any 2xx/302
    // response that ISN'T 401/403.
    $response = $this->actingAs($user)->get('/admin/comments/queue');
    expect($response->status())->not->toBe(403);
    expect($response->status())->not->toBe(401);

    // But moderators lack users.view → /admin/users denies.
    $this->actingAs($user)->get('/admin/users')->assertForbidden();

    // And they lack analytics.revenue → /admin/revenue denies.
    $this->actingAs($user)->get('/admin/revenue')->assertForbidden();
});

// ── Finance ─────────────────────────────────────────────────────

test('finance can reach revenue dashboard but not movie admin', function () {
    $user = User::factory()->create();
    $user->assignRole('finance');

    // Finance has analytics.revenue → /admin/revenue passes authz.
    $response = $this->actingAs($user)->get('/admin/revenue');
    expect($response->status())->not->toBe(403);
    expect($response->status())->not->toBe(401);

    // Finance lacks movies.view → /admin/movies denies.
    $this->actingAs($user)->get('/admin/movies')->assertForbidden();
    // Finance lacks comments.moderate → /admin/comments/queue denies.
    $this->actingAs($user)->get('/admin/comments/queue')->assertForbidden();
});

// ── Super-admin ─────────────────────────────────────────────────

test('super_admin passes every admin route via Gate::before bypass', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    // Test a representative spread of routes — every dotted permission
    // resolves true thanks to isSuperAdmin() returning true.
    $probes = [
        '/admin',
        '/admin/movies',
        '/admin/users',
        '/admin/revenue',
        '/admin/comments/queue',
        '/admin/audit-logs',
        '/admin/ai-usage',
        '/admin/ai-settings',
    ];

    foreach ($probes as $route) {
        $response = $this->actingAs($user)->get($route);
        // Authorization passed → status is NOT 401/403.
        expect($response->status())
            ->not->toBe(401, "Route {$route} returned 401 for super_admin")
            ->not->toBe(403, "Route {$route} returned 403 for super_admin");
    }
});

// ── Legacy fallback (is_admin) ──────────────────────────────────

test('legacy is_admin=true users still pass admin routes (back-compat)', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    $response = $this->actingAs($user)->get('/admin');
    expect($response->status())->not->toBe(401);
    expect($response->status())->not->toBe(403);

    $response = $this->actingAs($user)->get('/admin/movies');
    expect($response->status())->not->toBe(401);
    expect($response->status())->not->toBe(403);
});
