// FLiK Service Worker — Offline Cache + Web Push
//
// Two named caches:
//   • flik-static-v1   — long-lived, pre-cached at install. Logo, offline
//                        fallback page, manifest, core JS/CSS. We deliberately
//                        keep this list small; large assets are populated
//                        lazily by the runtime cache.
//   • flik-runtime-v3  — everything fetched at runtime that's safe to cache
//                        (GET, same-origin, non-admin, non-API). Bumped from
//                        flik-v2 → flik-runtime-v3 to drop stale entries.
//
// Strategy:
//   • Navigation requests (mode === 'navigate'): network-first, falling back
//     to /offline.html (pre-cached) when offline.
//   • Static assets (image, script, style, font): cache-first with network
//     fallback (refreshes the cache opportunistically).
//   • Everything else: network-only (no caching), so admin/API/livewire
//     never see stale data.

const STATIC_CACHE = 'flik-static-v1';
const RUNTIME_CACHE = 'flik-runtime-v3';
const OFFLINE_URL = '/offline.html';

// Pre-cached at install — must succeed in bulk for the install to complete,
// so keep this list to genuinely-always-present URLs. Anything optional goes
// into the runtime cache instead.
const PRECACHE_URLS = [
    '/',
    OFFLINE_URL,
    '/manifest.json',
    '/img/flik-logo.png',
    '/favicon.png',
];

// Install: pre-cache core assets into the named static cache. .addAll is
// atomic — if any URL 404s the whole install fails — so we wrap individual
// puts to survive missing optional assets without losing the rest.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(async (cache) => {
            await Promise.all(
                PRECACHE_URLS.map((url) =>
                    cache.add(url).catch(() => {
                        // Silently swallow individual failures so install can complete
                        // even if one optional asset is missing.
                    })
                )
            );
        })
    );
    self.skipWaiting();
});

// Activate: delete any cache that isn't on the current allow-list. Keeps the
// storage quota from blowing up over multiple SW versions.
self.addEventListener('activate', (event) => {
    const allow = new Set([STATIC_CACHE, RUNTIME_CACHE]);
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => !allow.has(name))
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Helpers ─────────────────────────────────────────────────────────────────

function isCacheableStatic(request) {
    // Only same-origin GETs. Cross-origin (Google Fonts, CDN scripts) bypass
    // the SW entirely so they can rely on their own cache headers.
    if (request.method !== 'GET') return false;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return false;
    const dest = request.destination;
    return dest === 'image' || dest === 'script' || dest === 'style' || dest === 'font';
}

function shouldBypass(url) {
    return (
        url.includes('/admin') ||
        url.includes('/api') ||
        url.includes('/livewire') ||
        url.includes('/sanctum') ||
        url.includes('/_debugbar') ||
        url.includes('/horizon') ||
        url.includes('/telescope')
    );
}

// Fetch ───────────────────────────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    if (shouldBypass(request.url)) return;

    // Navigation requests — HTML page loads. Network-first, fall back to
    // cached page if available, else the pre-cached /offline.html.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Cache successful navigations for offline replay.
                    if (response && response.status === 200) {
                        const clone = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => {
                            cache.put(request, clone).catch(() => {});
                        });
                    }
                    return response;
                })
                .catch(async () => {
                    const cached = await caches.match(request);
                    if (cached) return cached;
                    const offline = await caches.match(OFFLINE_URL);
                    if (offline) return offline;
                    // Last-resort minimal response so the browser doesn't show
                    // its own offline page (which is uglier than nothing).
                    return new Response(
                        '<!doctype html><meta charset=utf-8><title>FLiK — Offline</title><body style="background:#0a0a0a;color:#C5A55A;font-family:sans-serif;text-align:center;padding:4rem">You are offline.</body>',
                        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                })
        );
        return;
    }

    // Static assets — cache-first with background refresh. Serves instantly
    // from cache; the network response (if it arrives) refreshes the entry
    // for next time.
    if (isCacheableStatic(request)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const networkFetch = fetch(request)
                    .then((response) => {
                        if (response && response.status === 200) {
                            const clone = response.clone();
                            caches.open(RUNTIME_CACHE).then((cache) => {
                                cache.put(request, clone).catch(() => {});
                            });
                        }
                        return response;
                    })
                    .catch(() => cached);
                return cached || networkFetch;
            })
        );
        return;
    }

    // Everything else: pure network. We don't cache POSTs, signed URLs,
    // or unknown destinations.
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
