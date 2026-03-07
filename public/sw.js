// FLiK Service Worker — Basic Offline Cache
const CACHE_NAME = 'flik-v1';
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
