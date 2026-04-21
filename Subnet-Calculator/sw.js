// Subnet Calculator — Service Worker
// Caches the app shell for offline access. Bump CACHE_NAME on each release.
const CACHE_NAME   = 'sc-v2.7.0';
const CACHE_PREFIX = 'sc-v';

// Scope-relative paths — work in both root and subdir installs
const ASSETS_PATH  = new URL('assets/', self.registration.scope).pathname;
const SHELL_URL    = new URL('./', self.registration.scope).toString();

// Assets to pre-cache at install time
const PRECACHE_URLS = [
    SHELL_URL,
    new URL('assets/app.css',     self.registration.scope).toString(),
    new URL('assets/app.js',      self.registration.scope).toString(),
    new URL('assets/logo.webp',   self.registration.scope).toString(),
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE_URLS))
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
    if (url.pathname.startsWith(ASSETS_PATH)) {
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

    // Normalize to the shell URL so query strings don't create unbounded cache entries
    const shellRequest = new Request(SHELL_URL);

    event.respondWith(
        fetch(request)
            .then(response => {
                if (response.ok) {
                    event.waitUntil(
                        caches.open(CACHE_NAME)
                            .then(cache => cache.put(shellRequest, response.clone()))
                    );
                }
                return response;
            })
            .catch(() => caches.match(shellRequest))
    );
});
