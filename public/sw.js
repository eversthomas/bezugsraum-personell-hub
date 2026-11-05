// Service Worker: macht die App installierbar, hält statische Dateien bereit und
// zeigt offline die Erfassungsseite. Authentifizierte Seiten und die API werden nie zwischengespeichert.
const CACHE = 'ph-static-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE = [
    OFFLINE_URL,
    '/assets/css/tokens.css',
    '/assets/css/base.css',
    '/assets/css/components.css',
    '/assets/css/views.css',
    '/assets/js/theme.js',
    '/assets/js/capture.js',
    '/assets/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2',
    '/assets/fonts/atkinson-hyperlegible-next-latin-ext-wght-normal.woff2',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Statische Dateien: Netz zuerst, bei Ausfall aus dem Zwischenspeicher
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request)),
        );
        return;
    }

    // Seitenaufrufe: nie zwischenspeichern; offline kommt die Erfassungsseite
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
});
