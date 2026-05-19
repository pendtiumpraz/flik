// FLiK Service Worker — Offline Cache + Web Push
const CACHE_NAME = 'flik-v2';
const OFFLINE_URL = '/offline';

// Assets to pre-cache
const PRECACHE_URLS = [
    '/',
    '/css/app.css',
    '/js/app.js',
    '/js/content-protection.js',
    '/img/flik-logo.png',
    '/favicon.png',
];

// Install: pre-cache core assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_URLS).catch(() => {
                // Silently fail individual assets
            });
        })
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch: Network-first with cache fallback
self.addEventListener('fetch', (event) => {
    // Skip non-GET and admin/API
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes('/admin') ||
        event.request.url.includes('/api') ||
        event.request.url.includes('/livewire')) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Cache successful responses
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // Return cached version
                return caches.match(event.request).then((response) => {
                    return response || caches.match('/');
                });
            })
    );
});

// ─────────────────────────────────────────────────────────────
// Web Push
// ─────────────────────────────────────────────────────────────

// Push event — fires when our server sends a push (RFC 8030).
// Payload is encrypted aes128gcm but the browser decrypts before handing
// us a PushMessageData. We expect JSON per WebPushSender::toPayload().
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        // Some push services deliver an empty "wake-up" push — fall back
        // to a generic banner rather than crashing the listener.
        data = {
            title: 'FLiK',
            body: 'You have a new notification.',
        };
    }

    const title = data.title || 'FLiK';
    const options = {
        body: data.body || '',
        icon: data.icon || '/img/flik-logo.png',
        badge: data.badge || '/img/flik-logo.png',
        tag: data.tag || 'flik-notification',
        data: {
            action_url: data.action_url || data.url || '/',
            message_id: data.data && data.data.message_id ? data.data.message_id : null,
        },
        // renotify=true makes a same-tag push re-alert the user (otherwise
        // browsers replace silently). Off by default — most pushes shouldn't
        // be aggressive.
        renotify: !!data.renotify,
        // requireInteraction keeps the toast on screen until clicked —
        // reserve for high-importance alerts (set by the sender via payload).
        requireInteraction: !!data.requireInteraction,
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Notification click — open or focus the action URL.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = (event.notification.data && event.notification.data.action_url) || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Try to focus an existing FLiK tab and navigate it.
            for (const client of clientList) {
                try {
                    const url = new URL(client.url);
                    if (url.origin === self.location.origin && 'focus' in client) {
                        client.focus();
                        if ('navigate' in client && targetUrl) {
                            return client.navigate(targetUrl);
                        }
                        return client;
                    }
                } catch (e) {
                    // ignore URL parse errors and continue scanning
                }
            }
            // No existing tab → open a new one.
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// pushsubscriptionchange — browsers fire this when the push service
// rotates its keys or our subscription expires. We re-subscribe with the
// same applicationServerKey and POST the new endpoint to our backend so
// delivery keeps working without user intervention.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            try {
                // Best-effort: read the old subscription's applicationServerKey
                // so we can re-subscribe even when self.registration.pushManager
                // has lost the cached options.
                const oldSub = event.oldSubscription;
                const applicationServerKey =
                    (oldSub && oldSub.options && oldSub.options.applicationServerKey) || null;

                if (!applicationServerKey) {
                    // Without the VAPID public key we can't re-subscribe here —
                    // the next page load will run the JS module and retry.
                    return;
                }

                const newSub = await self.registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey,
                });

                // Inform the backend. CSRF token is unavailable inside a SW,
                // so the endpoint accepts unauthenticated POSTs (it's already
                // idempotent + endpoint-keyed).
                await fetch('/api/push/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint: newSub.endpoint,
                        keys: {
                            p256dh: arrayBufferToBase64Url(newSub.getKey('p256dh')),
                            auth: arrayBufferToBase64Url(newSub.getKey('auth')),
                        },
                    }),
                });
            } catch (e) {
                // Swallow — the next time the user lands on the site, the
                // page-side JS will retry the subscribe handshake from scratch.
            }
        })()
    );
});

function arrayBufferToBase64Url(buffer) {
    if (!buffer) return '';
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
