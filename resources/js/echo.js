/**
 * Laravel Echo + Pusher bootstrap (admin realtime).
 *
 * Loaded eagerly from `app.js`. We initialise Echo only when
 * `window.PUSHER_KEY` is present so the rest of the app continues
 * to work fine when broadcasting is not configured (polling fallback
 * in `admin-notifications.js` takes over).
 *
 * The admin layout blade exposes:
 *   window.PUSHER_KEY      — config('broadcasting.connections.pusher.key')
 *   window.PUSHER_CLUSTER  — config('broadcasting.connections.pusher.options.cluster')
 *   window.AUTH_USER_ROLES — auth()->user()->roles->pluck('name') (or [])
 *
 * When any of those are missing we silently no-op — consumers should
 * test `if (window.Echo) { ... }` before subscribing.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

if (typeof window !== 'undefined' && window.PUSHER_KEY) {
    window.Pusher = Pusher;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: window.PUSHER_KEY,
        cluster: window.PUSHER_CLUSTER || 'ap1',
        forceTLS: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });
}
