// Asterisko PWA Service Worker (Offline-First Cache Engine)
const CACHE_NAME = 'asterisko-cache-v1';
const PRECACHE_ASSETS = [
    '/',
    '/manifest.json',
    '/assets/logo.png',
    '/assets/app-enhancements.css',
    '/pages/orders.php',
    '/pages/dashboard.php',
    '/pages/count.php'
];

// Install: Pre-cache core application shell
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('[SW] Pre-caching core application shell');
            return cache.addAll(PRECACHE_ASSETS).catch(err => {
                console.warn('[SW] Some assets could not be pre-cached:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate: Clean up older cache versions
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: Network-First with Cache Fallback for Pages, Cache-First for static assets
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Bypass non-GET requests (e.g. POST to api/sync.php handled by IndexedDB sync engine)
    if (request.method !== 'GET') {
        return;
    }

    // Navigation requests (HTML pages)
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(networkResponse => {
                    // Update the cache with latest copy if valid
                    if (networkResponse && networkResponse.status === 200) {
                        const copy = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    // Offline fallback: return cached page or fallback to orders.php
                    const cachedResponse = await caches.match(request);
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    return caches.match('/pages/orders.php');
                })
        );
        return;
    }

    // Static assets (CSS, JS, Images, Fonts) -> Stale-While-Revalidate
    if (
        request.destination === 'style' ||
        request.destination === 'script' ||
        request.destination === 'image' ||
        request.destination === 'font' ||
        url.hostname.includes('cdn') ||
        url.hostname.includes('fonts.googleapis.com') ||
        url.hostname.includes('fonts.gstatic.com')
    ) {
        event.respondWith(
            caches.match(request).then(cached => {
                const fetchPromise = fetch(request).then(networkResponse => {
                    if (networkResponse && networkResponse.status === 200) {
                        const copy = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
                    }
                    return networkResponse;
                }).catch(() => cached);

                return cached || fetchPromise;
            })
        );
        return;
    }

    // Default fetch
    event.respondWith(
        fetch(request).catch(() => caches.match(request))
    );
});

// Background Sync Event (Triggered when internet reconnects)
self.addEventListener('sync', event => {
    if (event.tag === 'asterisko-outbox-sync') {
        console.log('[SW] Background sync triggered');
        event.waitUntil(
            self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'TRIGGER_OUTBOX_SYNC' });
                });
            })
        );
    }
});
