<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MovieBulkActionTest
|--------------------------------------------------------------------------
|
| Covers the `POST /admin/movies/bulk` endpoint backed by
| App\Http\Controllers\Admin\MovieBulkController.
|
| Skips gracefully when MySQL isn't available so the suite stays green on
| CI workers that don't run the DB migrations (mirrors the pattern used by
| AdminRouteGuardTest).
|
| Scenarios:
|   - Authorised admin can bulk-delete 5 movies
|   - Regular user gets 403
|   - Bulk feature flips is_popular = true on selected
|   - Empty IDs (no search either) → 422
|   - CSV export returns text/csv with headers
|
*/

use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }
});

/**
 * Build an admin user the bulk endpoint will accept. Prefers the RBAC
 * super_admin role when seeded; otherwise falls back to the legacy
 * `is_admin = true` flag which the Gate::before short-circuit honours.
 */
function bulk_make_admin(): User
{
    $u = User::factory()->create();

    try {
        if (method_exists($u, 'assignRole') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
            if (app('db')->table('roles')->count() === 0
                && class_exists(\Database\Seeders\RolePermissionSeeder::class)) {
                (new \Database\Seeders\RolePermissionSeeder)->run();
            }
            $u->assignRole('super_admin');

            return $u->fresh();
        }
    } catch (\Throwable) {
        // fall through to legacy flag
    }

    $u->forceFill(['is_admin' => true])->save();

    return $u->fresh();
}

function bulk_seed_movies(int $n): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $m = Movie::create([
            'title' => 'Bulk Test '.$i.'-'.uniqid(),
            'overview' => 'overview',
            'is_popular' => false,
            'is_trending' => false,
        ]);
        $ids[] = $m->id;
    }

    return $ids;
}

// ── Auth ───────────────────────────────────────────────────────────────

test('regular user receives 403 from bulk endpoint', function () {
    $user = User::factory()->create(); // no admin role / no is_admin

    $this->actingAs($user)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'feature',
            'ids' => [1, 2, 3],
        ])
        ->assertForbidden();
});

test('anonymous user is redirected away from bulk endpoint', function () {
    $this->post(route('admin.movies.bulk'), [
        'action' => 'feature',
        'ids' => [1, 2, 3],
    ])->assertRedirect('/login');
});

// ── Delete ─────────────────────────────────────────────────────────────

test('authorized admin can bulk delete 5 movies', function () {
    $admin = bulk_make_admin();
    $ids = bulk_seed_movies(5);

    $this->actingAs($admin)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'delete',
            'ids' => $ids,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'affected' => 5,
        ]);

    expect(Movie::whereIn('id', $ids)->count())->toBe(0);
});

// ── Feature flag flip ──────────────────────────────────────────────────

test('bulk feature flips is_popular=true on selected', function () {
    $admin = bulk_make_admin();
    $ids = bulk_seed_movies(3);

    // Sanity: all start at false
    expect(Movie::whereIn('id', $ids)->where('is_popular', true)->count())->toBe(0);

    $this->actingAs($admin)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'feature',
            'ids' => $ids,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'affected' => 3,
        ]);

    expect(Movie::whereIn('id', $ids)->where('is_popular', true)->count())->toBe(3);
});

// ── Validation ─────────────────────────────────────────────────────────

test('bulk endpoint refuses an empty selection', function () {
    $admin = bulk_make_admin();

    $this->actingAs($admin)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'feature',
            'ids' => [],
        ])
        ->assertStatus(422);
});

test('bulk endpoint refuses an unknown action', function () {
    $admin = bulk_make_admin();

    $this->actingAs($admin)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'nuke-from-orbit',
            'ids' => [1],
        ])
        ->assertStatus(422);
});

// ── CSV ────────────────────────────────────────────────────────────────

test('csv export returns text/csv with header row', function () {
    $admin = bulk_make_admin();
    $ids = bulk_seed_movies(2);

    $response = $this->actingAs($admin)
        ->post(route('admin.movies.bulk'), [
            'action' => 'export-csv',
            'ids' => $ids,
        ]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();
    expect($body)->toContain('id,title,year,genres,rating,status');
});

// ── Queueing ───────────────────────────────────────────────────────────

test('ai-tags action dispatches one job per movie on the ai-batch queue', function () {
    Queue::fake();

    $admin = bulk_make_admin();
    $ids = bulk_seed_movies(4);

    $this->actingAs($admin)
        ->postJson(route('admin.movies.bulk'), [
            'action' => 'ai-tags',
            'ids' => $ids,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'queued' => 4,
        ]);

    Queue::assertPushed(\App\Jobs\TagMovie::class, 4);
});
