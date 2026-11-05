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

// Push: Nachricht anzeigen, Tippen öffnet die Seite oder holt sie in den Vordergrund
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'Erinnerung', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(
        self.registration.showNotification(data.title || 'Erinnerung', {
            body: data.body || '',
            tag: data.tag || undefined,
            icon: '/assets/icons/icon-192.png',
            badge: '/assets/icons/icon-192.png',
            data: { url: typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate?.(target);
                    return client.focus();
                }
            }
            return self.clients.openWindow(target);
        }),
    );
});
