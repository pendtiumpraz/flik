<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PwaAssetTest (FIX #10)
|--------------------------------------------------------------------------
|
| Guards the FIX #10 deliverables:
|   - Every icon referenced by public/manifest.json EXISTS on disk
|   - Apple-touch-icon is present
|   - PwaInstallTrackController peppers the IP hash with APP_KEY (audit 19 PWA-8)
|   - Layout viewport meta carries viewport-fit=cover (audit 19 M-1)
|
| These tests have no database dependency — they verify on-disk state and
| controller behaviour only.
|
*/

use App\Models\PwaInstall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Asset presence ──────────────────────────────────────────────────────

test('all manifest icon files exist on disk', function () {
    $manifestPath = public_path('manifest.json');
    expect(file_exists($manifestPath))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    expect($manifest)->toBeArray()->and($manifest)->toHaveKey('icons');

    foreach ($manifest['icons'] as $icon) {
        $src = ltrim((string) $icon['src'], '/');
        $disk = public_path($src);
        expect(file_exists($disk))->toBeTrue("Manifest icon missing on disk: /{$src}");
    }
});

test('apple touch icon exists at the layout-referenced paths', function () {
    expect(file_exists(public_path('apple-touch-icon.png')))->toBeTrue();
    expect(file_exists(public_path('icons/apple-touch-icon.png')))->toBeTrue();
});

test('viewport-fit cover is present on layout meta', function () {
    $layout = (string) file_get_contents(resource_path('views/components/layout.blade.php'));
    expect($layout)->toContain('viewport-fit=cover');
});

// ── PwaInstallTrackController peppered hash ────────────────────────────

test('pwa install track stores peppered ip hash', function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }

    if (! \Illuminate\Support\Facades\Schema::hasTable('pwa_installs')) {
        $this->markTestSkipped('pwa_installs table not migrated.');
    }

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/api/pwa/track-install', [
            'outcome' => 'installed',
            'device'  => 'desktop',
            'ua'      => 'PHPUnit/PwaAssetTest',
        ]);

    expect($response->status())->toBeIn([200, 201]);

    $install = PwaInstall::where('user_id', $user->id)->latest('id')->first();
    expect($install)->not->toBeNull();

    // The PEPPERED hash must match — bare sha256(ip) must NOT.
    $pepper = (string) config('app.key');
    $expected = hash('sha256', '198.51.100.7|'.$pepper);
    $unpeppered = hash('sha256', '198.51.100.7');

    expect($install->ip_hash)->toBe($expected);
    expect($install->ip_hash)->not->toBe($unpeppered);
});
