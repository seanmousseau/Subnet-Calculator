// /admin/sw.js — Tombstone (v3.2.3, hotfix).
//
// Earlier releases shipped assets/app.js with an unconditional
// navigator.serviceWorker.register('sw.js') call. On admin pages that
// URL resolved to /admin/sw.js, which did not exist — the browser
// parked the registration in a permanent "trying to install" state
// (404 on the script fetch never lets install complete) and DevTools
// could not unregister it because there was no installed worker yet.
//
// This file exists solely to let those stuck registrations finally
// install — and immediately unregister themselves so the admin scope
// is left clean. New admin sessions never register this file, because
// app.js (v3.2.3+) skips registration on /admin/* paths.

self.addEventListener('install', event => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        try {
            await self.registration.unregister();
            const clients = await self.clients.matchAll({ includeUncontrolled: true });
            for (const client of clients) {
                if (typeof client.navigate === 'function') {
                    try {
                        await client.navigate(client.url);
                    } catch (_) {
                        // Top-level documents may refuse navigation; ignore.
                    }
                }
            }
        } catch (_) {
            // Swallow — there is no recovery path beyond logging, and
            // the worker is about to be torn down anyway.
        }
    })());
});

// Intentionally no fetch listener: with no controller after unregister,
// requests fall through to the network — which is what we want for the
// dynamic, no-store admin surface.
