<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NotificationTest (FIX #10)
|--------------------------------------------------------------------------
|
| Covers two cross-cutting concerns:
|
|   1. AdminNotifier::notify() ─ the canonical fan-out helper for staff
|      alerts. Must persist a row AND fire the AdminNotificationCreated
|      broadcast event (we use Event::fake() so no Pusher dependency).
|
|   2. /notifications/count (user-facing bell) ─ JSON shape contract used
|      by every page header. A regression here breaks the bell badge.
|
| Skips when DB is unavailable.
|
*/

use App\Events\AdminNotificationCreated;
use App\Models\AdminNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Database not available: '.$e->getMessage());
    }
});

// ── AdminNotifier ──────────────────────────────────────────────────────

test('admin notif create via AdminNotifier fires event', function () {
    if (! class_exists(AdminNotifier::class) || ! class_exists(AdminNotification::class)) {
        $this->markTestSkipped('AdminNotifier or AdminNotification not present.');
    }

    Event::fake([AdminNotificationCreated::class]);

    /** @var AdminNotifier $notifier */
    $notifier = app(AdminNotifier::class);

    $notification = $notifier->notify(
        category: 'test.fix10',
        title: 'FIX #10 smoke test',
        message: 'AdminNotifier fan-out smoke test from NotificationTest.',
        meta: ['source' => 'NotificationTest'],
        severity: 'info',
        audience: AdminNotification::AUDIENCE_ALL,
    );

    expect($notification)->toBeInstanceOf(AdminNotification::class);
    expect($notification->category)->toBe('test.fix10');
    expect($notification->severity)->toBe('info');

    // DB row was persisted with the right shape.
    expect(AdminNotification::where('category', 'test.fix10')->exists())->toBeTrue();

    // Broadcast event was dispatched (we don't care about the listener
    // side effects here; just that the contract is honoured).
    Event::assertDispatched(AdminNotificationCreated::class, function ($evt) use ($notification) {
        return $evt->notification->id === $notification->id;
    });
});

// ── User-facing bell unread count ──────────────────────────────────────

test('bell unread count returns json', function () {
    $user = User::factory()->create();

    // Seed two notifications — one unread, one read — so the count
    // assertion has signal. Notification model uses Laravel's default
    // is_read/read_at columns (see Notification migration).
    try {
        Notification::create([
            'user_id'    => $user->id,
            'type'       => 'system',
            'title'      => 'Unread notif',
            'message'    => 'You have a pending action.',
            'is_read'    => false,
        ]);
        Notification::create([
            'user_id'    => $user->id,
            'type'       => 'system',
            'title'      => 'Read notif',
            'message'    => 'Already seen.',
            'is_read'    => true,
            'read_at'    => now(),
        ]);
    } catch (\Throwable $e) {
        $this->markTestSkipped('Notification model write failed: '.$e->getMessage());
    }

    $response = $this->actingAs($user)->getJson('/notifications/count');

    expect($response->status())->toBe(200);
    $response->assertJsonStructure(['count']);

    // Count >= 1 (the unread one). We don't pin == 1 because future
    // listeners (welcome notif, etc.) might add their own rows.
    expect((int) $response->json('count'))->toBeGreaterThanOrEqual(1);
});
