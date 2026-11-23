/*
 * VVA service worker.
 *  - Built assets (/build/*, icons, fonts): cache-first (they are content-hashed).
 *  - Page navigations: network-first, falling back to the last cached copy, then /offline.html.
 *  - Livewire, API, broadcasting and any non-GET requests are never cached.
 */
const VERSION = 'vva-v1';
const STATIC_CACHE = `${VERSION}-static`;
const PAGE_CACHE = `${VERSION}-pages`;
const PRECACHE = ['/offline.html', '/favicon.svg', '/icons/icon-192.png', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

const isBypassed = (url) =>
    /^\/(livewire|api|broadcasting|logout|locale|sanctum)/.test(url.pathname) || url.origin !== self.location.origin;

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || isBypassed(url)) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(PAGE_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/offline.html'))),
        );

        return;
    }

    if (/^\/(build|icons|fonts)\//.test(url.pathname) || url.pathname === '/favicon.svg') {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));

                        return response;
                    }),
            ),
        );
    }
});
