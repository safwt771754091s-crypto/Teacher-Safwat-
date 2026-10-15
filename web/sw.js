/* معلمي الذكي اليمني — service worker.
 *
 * Makes the installed app lightweight and usable offline:
 *   - keeps the app shell (HTML/CSS/JS) fresh via network-first with a cached
 *     fallback, so a new deploy is picked up instead of being pinned by an old
 *     cache-first worker;
 *   - caches curriculum API GETs (network-first) so lessons/exercises already
 *     viewed stay readable offline;
 *   - keeps books the user saved for offline in the "ts-books-v1" cache.
 * Book files themselves live in Supabase Storage and are pulled on demand, so
 * the app never ships the whole curriculum to every device.
 */
const SHELL_CACHE = 'ts-shell-v5';
const RUNTIME_CACHE = 'ts-runtime-v5';
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

// Cache keys ignore the ?v= cache-buster so the same asset is not stored twice.
function cacheKey(request) {
    const url = new URL(request.url);
    url.search = '';
    return url.href;
}

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
    const key = cacheKey(request);
    try {
        const response = await fetch(request);
        if (response && response.ok) {
            const cache = await caches.open(names[0]);
            cache.put(key, response.clone());
        }
        return response;
    } catch (err) {
        for (const name of names) {
            const cache = await caches.open(name);
            const cached = (await cache.match(key)) || (await cache.match(request));
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

    // Same-origin static assets (shell): network-first so a fresh deploy always
    // wins, falling back to the cached copy when the network is unavailable.
    if (url.origin === self.location.origin) {
        event.respondWith(networkFirst(request, SHELL_CACHE));
    }
});
