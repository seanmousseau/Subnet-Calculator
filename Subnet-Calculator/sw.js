// Subnet Calculator — Service Worker
// Caches the app shell for offline access. Bump CACHE_NAME on each release.
const CACHE_NAME   = 'sc-v2.7.0';
const CACHE_PREFIX = 'sc-v';

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.add('./'))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k.startsWith(CACHE_PREFIX) && k !== CACHE_NAME)
                    .map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const { request } = event;

    // Only intercept same-origin GET requests
    if (request.method !== 'GET') return;
    let url;
    try { url = new URL(request.url); } catch { return; }
    if (url.origin !== self.location.origin) return;

    // Static assets: cache-first, populate cache on network hit
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    if (response.ok) {
                        // Attach cache write to event lifetime so the SW isn't
                        // terminated before the write completes
                        event.waitUntil(
                            caches.open(CACHE_NAME)
                                .then(cache => cache.put(request, response.clone()))
                        );
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Only intercept navigation requests for the offline shell fallback —
    // non-navigation GETs (API calls, XHR) should fail normally when offline
    if (request.mode !== 'navigate') return;

    event.respondWith(
        fetch(request)
            .then(response => {
                if (response.ok) {
                    event.waitUntil(
                        caches.open(CACHE_NAME)
                            .then(cache => cache.put(request, response.clone()))
                    );
                }
                return response;
            })
            .catch(() => caches.match('./'))
    );
});
