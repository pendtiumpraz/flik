<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AbTestCrudTest
|--------------------------------------------------------------------------
|
| End-to-end smoke for the A/B test admin surface after FIX #3 brought
| the schema, model, service, controller, and view into alignment:
|
|   - `2026_05_10_240001_extend_ab_experiments_table` adds slug /
|     hypothesis / winner_variant.
|   - `2026_05_10_240002_extend_ab_assignments_table` adds assigned_at /
|     conversion_value.
|   - AbExperiment exposes STATUS_RUNNING / variantKeys / isRunning.
|   - AbAssignment includes conversion_value / assigned_at in fillable.
|   - AbTestFramework writes `ab_experiment_id` (not `experiment_id`)
|     and emits significance + weight + `conversions` alias keys.
|
| Skips when the database isn't available so CI workers without MySQL
| stay green.
|
*/

use App\Models\AbAssignment;
use App\Models\AbExperiment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Analytics\AbTestFramework;
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

// ── Index ──────────────────────────────────────────────────────────────

test('super_admin can view ab tests index on empty db', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/ab-tests');

    $response->assertOk();
    $response->assertViewIs('admin.ab.index');
});

// ── Create form ────────────────────────────────────────────────────────

test('super_admin can view the create form', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get('/admin/ab-tests/create');

    $response->assertOk();
    $response->assertSee('variant_keys', escape: false);
});

// ── Store ──────────────────────────────────────────────────────────────

test('super_admin can create and show experiment', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $payload = [
        'name'             => 'Home Hero Copy v2',
        'slug'             => 'home-hero-copy-v2',
        'hypothesis'       => 'A gold CTA outperforms the white one.',
        'primary_metric'   => 'home_hero_ctr',
        'variant_keys'     => ['control', 'gold_cta'],
        'variant_weights'  => [50, 50],
        'start_now'        => '1',
    ];

    $response = $this->actingAs($admin)->post('/admin/ab-tests', $payload);

    $experiment = AbExperiment::query()->where('slug', 'home-hero-copy-v2')->first();
    expect($experiment)->not->toBeNull();
    expect($experiment->name)->toBe('Home Hero Copy v2');
    expect($experiment->hypothesis)->toContain('gold CTA');
    expect($experiment->status)->toBe(AbExperiment::STATUS_RUNNING);
    expect($experiment->variantKeys())->toBe(['control', 'gold_cta']);
    expect($experiment->isRunning())->toBeTrue();

    $response->assertRedirect(route('admin.ab-tests.show', $experiment));
});

// ── Show ───────────────────────────────────────────────────────────────

test('super_admin can view a created experiment report', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $exp = AbExperiment::create([
        'slug'           => 'show-smoke-test',
        'name'           => 'Show Smoke Test',
        'variants'       => [['key' => 'control', 'weight' => 1], ['key' => 'variant_a', 'weight' => 1]],
        'traffic_split'  => [0.5, 0.5],
        'primary_metric' => 'conversion',
        'status'         => AbExperiment::STATUS_RUNNING,
        'started_at'     => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.ab-tests.show', $exp));

    $response->assertOk();
    $response->assertViewIs('admin.ab.show');
    $response->assertViewHas('report');
    $response->assertViewHas('experiment');
});

// ── Lifecycle (start/pause/resume/conclude) ───────────────────────────

test('super_admin can pause and resume a running experiment', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $exp = AbExperiment::create([
        'slug'           => 'lifecycle-smoke',
        'name'           => 'Lifecycle Smoke',
        'variants'       => [['key' => 'a', 'weight' => 1], ['key' => 'b', 'weight' => 1]],
        'traffic_split'  => [0.5, 0.5],
        'primary_metric' => 'conversion',
        'status'         => AbExperiment::STATUS_RUNNING,
        'started_at'     => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.ab-tests.act', [$exp, 'pause']))
        ->assertRedirect();

    expect($exp->fresh()->status)->toBe(AbExperiment::STATUS_PAUSED);

    $this->actingAs($admin)
        ->post(route('admin.ab-tests.act', [$exp, 'resume']))
        ->assertRedirect();

    expect($exp->fresh()->status)->toBe(AbExperiment::STATUS_RUNNING);
});

test('super_admin can conclude with a winner', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $exp = AbExperiment::create([
        'slug'           => 'conclude-smoke',
        'name'           => 'Conclude Smoke',
        'variants'       => [['key' => 'a', 'weight' => 1], ['key' => 'b', 'weight' => 1]],
        'traffic_split'  => [0.5, 0.5],
        'primary_metric' => 'conversion',
        'status'         => AbExperiment::STATUS_RUNNING,
        'started_at'     => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.ab-tests.act', [$exp, 'conclude']), ['winner_variant' => 'b'])
        ->assertRedirect();

    $fresh = $exp->fresh();
    expect($fresh->status)->toBe(AbExperiment::STATUS_COMPLETED);
    expect($fresh->winner_variant)->toBe('b');
    expect($fresh->ended_at)->not->toBeNull();
});

// ── Framework: assign + track + report ─────────────────────────────────

test('framework can assign, track, and report a conversion', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $exp = AbExperiment::create([
        'slug'           => 'framework-smoke',
        'name'           => 'Framework Smoke',
        'variants'       => [['key' => 'control', 'weight' => 1], ['key' => 'variant_a', 'weight' => 1]],
        'traffic_split'  => [0.5, 0.5],
        'primary_metric' => 'conversion',
        'status'         => AbExperiment::STATUS_RUNNING,
        'started_at'     => now(),
    ]);

    $user = User::factory()->create();
    $framework = app(AbTestFramework::class);

    // assign() should mint a bucket on the correct FK column.
    $variant = $framework->assign($user, 'framework-smoke');
    expect($variant)->toBeIn(['control', 'variant_a']);

    $assignment = AbAssignment::query()
        ->where('ab_experiment_id', $exp->id)
        ->where('user_id', $user->id)
        ->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->variant)->toBe($variant);
    expect($assignment->assigned_at)->not->toBeNull();

    // Sticky lookup — second call returns the same variant.
    expect($framework->assign($user, 'framework-smoke'))->toBe($variant);

    // track() persists the conversion value.
    $framework->track($user, 'framework-smoke', 49.95);
    $assignment->refresh();
    expect((float) $assignment->conversion_value)->toBe(49.95);
    expect($assignment->converted_at)->not->toBeNull();

    // report() shape — both `converted` and `conversions` alias, `weight`.
    $report = $framework->report('framework-smoke');
    expect($report['variants'])->toHaveCount(2);
    foreach ($report['variants'] as $v) {
        expect($v)->toHaveKeys(['variant', 'weight', 'assigned', 'converted', 'conversions', 'conversion_rate']);
    }
    expect($report['total_assigned'])->toBe(1);
    expect($report['total_converted'])->toBe(1);
});

// ── Auth ───────────────────────────────────────────────────────────────

test('regular user is forbidden from ab tests index', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get('/admin/ab-tests')
        ->assertForbidden();
});
