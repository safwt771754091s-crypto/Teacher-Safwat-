/* معلمي الذكي اليمني — service worker.
 *
 * Makes the installed app lightweight and usable offline:
 *   - precaches the app shell (HTML/CSS/JS) so it opens with no network;
 *   - caches curriculum API GETs (network-first) so lessons/exercises already
 *     viewed stay readable offline;
 *   - keeps books the user saved for offline in the "ts-books-v1" cache.
 * Book files themselves live in Supabase Storage and are pulled on demand, so
 * the app never ships the whole curriculum to every device.
 */
const SHELL_CACHE = 'ts-shell-v3';
const RUNTIME_CACHE = 'ts-runtime-v3';
const BOOKS_CACHE = 'ts-books-v1';
const SHELL_ASSETS = [
    './',
    'index.html',
    'manifest.webmanifest',
    'assets/styles.css',
    'assets/config.js',
    'assets/api.js',
    'assets/app.js',
    'assets/offline.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);
        // Cache shell assets individually so one missing file cannot block the
        // whole install (addAll is atomic and would reject the entire batch).
        await Promise.all(SHELL_ASSETS.map((asset) => cache.add(asset).catch(() => {})));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keep = [SHELL_CACHE, RUNTIME_CACHE, BOOKS_CACHE];
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => !keep.includes(k)).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

async function networkFirst(request, cacheNames, fallbackKey) {
    const names = Array.isArray(cacheNames) ? cacheNames : [cacheNames];
    try {
        const response = await fetch(request);
        if (response && response.ok) {
            const cache = await caches.open(names[0]);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        for (const name of names) {
            const cache = await caches.open(name);
            const cached = await cache.match(request);
            if (cached) return cached;
        }
        if (fallbackKey) {
            const shell = await caches.open(SHELL_CACHE);
            const fallback = await shell.match(fallbackKey);
            if (fallback) return fallback;
        }
        throw err;
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (response && response.ok) cache.put(request, response.clone());
    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Curriculum API: always try the network, fall back to the last copy (the
    // runtime cache, or a book the user saved for offline).
    if (url.pathname.includes('/api/v1/')) {
        event.respondWith(networkFirst(request, [RUNTIME_CACHE, BOOKS_CACHE]));
        return;
    }

    // Page navigations: network-first with the cached shell as offline fallback.
    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request, RUNTIME_CACHE, 'index.html'));
        return;
    }

    // Same-origin static assets (shell): cache-first.
    if (url.origin === self.location.origin) {
        event.respondWith(cacheFirst(request));
    }
});
