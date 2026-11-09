/* Service worker — makes the platform installable and usable offline.
 *
 * Android (and desktop Chrome/Edge) only offer "تثبيت التطبيق" when the page
 * has a manifest *and* an active service worker with a fetch handler, so this
 * file is what turns the web app into something a learner can pin to their
 * home screen and open without a network.
 *
 * Strategy:
 *   - the app shell (HTML, CSS, JS, icons) is pre-cached and served
 *     stale-while-revalidate, so a new deploy lands on the next open without
 *     ever blocking a lesson on the network;
 *   - API GETs are network-first with a cache fallback, so a lesson a learner
 *     already opened stays readable offline;
 *   - everything else (POST/PUT/DELETE, cross-origin) goes straight to the
 *     network and is never cached.
 *
 * The shell list is versioned: bump CACHE when the shell files change, and the
 * old cache is dropped on activate.
 */
'use strict';

var CACHE = 'safwat-shell-v3';
var SHELL = [
    './',
    './index.html',
    './manifest.webmanifest',
    './assets/styles.css?v=11',
    './assets/config.js?v=11',
    './assets/api.js?v=6',
    './assets/interactive.js?v=2',
    './assets/interactive3d.js?v=1',
    './assets/interactive-lessons.js?v=1',
    './assets/interactive-science.js?v=1',
    './assets/app.js?v=19',
    './assets/pwa.js?v=2',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/icon-maskable-512.png',
    './assets/icons/apple-touch-icon-180.png',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE)
            // addAll rejects as a unit, so cache one by one and tolerate a
            // single missing file instead of losing the whole install.
            .then(function (cache) {
                return Promise.all(SHELL.map(function (url) {
                    return cache.add(new Request(url, { cache: 'reload' })).catch(function () { /* optional file */ });
                }));
            })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.map(function (k) {
                    return k === CACHE ? null : caches.delete(k);
                }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') return;

    var url = new URL(req.url);
    var sameOrigin = url.origin === self.location.origin;
    var isApi = url.pathname.indexOf('/api/') !== -1;

    // API reads: fresh when online, last-known answer when offline.
    if (isApi) {
        event.respondWith(
            fetch(req)
                .then(function (res) {
                    var copy = res.clone();
                    caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
                    return res;
                })
                .catch(function () {
                    return caches.match(req).then(function (hit) {
                        return hit || new Response(
                            JSON.stringify({ message: 'أنت غير متصل بالإنترنت.' }),
                            { status: 503, headers: { 'Content-Type': 'application/json' } }
                        );
                    });
                })
        );
        return;
    }

    // Same-origin shell: instant from cache, refreshed in the background.
    if (sameOrigin) {
        event.respondWith(
            caches.match(req).then(function (hit) {
                var network = fetch(req).then(function (res) {
                    if (res && res.status === 200 && res.type === 'basic') {
                        var copy = res.clone();
                        caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
                    }
                    return res;
                }).catch(function () { return hit; });
                return hit || network;
            })
        );
        return;
    }
    // Cross-origin (fonts, the API host on another domain): network only.
});

// Let the page ask to activate a waiting worker immediately after an update.
self.addEventListener('message', function (event) {
    if (event.data === 'skip-waiting') self.skipWaiting();
});
