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
                        caches.open(CACHE_NAME)
                            .then(cache => cache.put(request, response.clone()));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // HTML (navigations + shareable GET URLs): network-first, fall back to cached shell
    event.respondWith(
        fetch(request)
            .then(response => {
                if (response.ok) {
                    caches.open(CACHE_NAME)
                        .then(cache => cache.put(request, response.clone()));
                }
                return response;
            })
            .catch(() => caches.match('./'))
    );
});
