// Asterisko PWA Service Worker (Offline-First Cache Engine)
const CACHE_NAME = 'asterisko-cache-v3';
const PRECACHE_ASSETS = [
    '/manifest.json',
    '/assets/logo.png',
    '/assets/app-enhancements.css',
    '/assets/js/offline-engine.js',
    '/pages/orders.php',
    '/pages/dashboard.php',
    '/pages/count.php',
    '/pages/waste.php',
    '/pages/delivery_intake.php',
    'https://cdn.jsdelivr.net/npm/dexie@3.2.4/dist/dexie.min.js'
];

// Install: Pre-cache core application shell safely
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async cache => {
            console.log('[SW] Pre-caching core application assets safely (v3)');
            for (const asset of PRECACHE_ASSETS) {
                try {
                    const response = await fetch(asset, { cache: 'no-cache' });
                    if (response && response.status === 200) {
                        await cache.put(asset, response);
                    }
                } catch (e) {
                    console.warn('[SW] Could not precache asset:', asset);
                }
            }
        }).then(() => self.skipWaiting())
    );
});

// Activate: Clean up older cache versions and take immediate control
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

    // Navigation requests (HTML pages - e.g. switching tabs or clicking sidebar links)
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(networkResponse => {
                    // Cache fresh copy when online
                    if (networkResponse && networkResponse.status === 200) {
                        const copy = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(request, copy);
                            cache.put(url.pathname, networkResponse.clone());
                        });
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    // Offline: serve matching page from cache
                    const cache = await caches.open(CACHE_NAME);
                    let cached = await cache.match(request, { ignoreSearch: true });
                    if (cached) return cached;

                    // Match by pathname (e.g. /pages/orders.php, /pages/count.php)
                    cached = await cache.match(url.pathname, { ignoreSearch: true });
                    if (cached) return cached;

                    // Fallback to orders or dashboard
                    cached = await cache.match('/pages/orders.php') || await cache.match('/pages/dashboard.php');
                    if (cached) return cached;

                    return new Response(
                        `<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Offline - Asterisko</title><style>body{font-family:sans-serif;background:#0C2340;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:20px;box-sizing:border-box;text-align:center;}a{color:#F28C28;font-weight:bold;text-decoration:none;}</style></head><body><div><h2>⚠️ Offline Mode</h2><p>You are currently offline. Return to <a href="/pages/orders.php">Orders POS</a> to continue recording orders locally.</p></div></body></html>`,
                        { headers: { 'Content-Type': 'text/html' } }
                    );
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

    // Default fetch fallback
    event.respondWith(
        fetch(request).catch(() => caches.match(request))
    );
});

// Background Sync Event
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
