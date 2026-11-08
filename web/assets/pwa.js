/* PWA install — offers "تثبيت التطبيق" on Android/desktop and guides iOS.
 *
 * Chrome fires `beforeinstallprompt` when the app is installable (manifest +
 * active service worker). We stash that event and show a button; tapping it
 * opens the native install sheet. On iOS there is no such event, so we show the
 * manual "Share → Add to Home Screen" hint instead. If the app is already
 * installed (running standalone) or was dismissed, nothing is shown.
 */
(function () {
    'use strict';

    var deferred = null;
    var DISMISS_KEY = 'pwa-install-dismissed';

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true;
    }

    function isIos() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    }

    function dismissed() {
        try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
    }

    function bar() {
        var el = document.getElementById('installBar');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'installBar';
        el.className = 'install-bar hidden';
        el.innerHTML =
            '<span class="install-bar-text">ثبّت المنصة على جهازك للوصول السريع دون إنترنت.</span>' +
            '<button class="btn primary small" id="installBtn">تثبيت التطبيق</button>' +
            '<button class="btn ghost small" id="installLater">لاحقًا</button>';
        document.body.appendChild(el);
        return el;
    }

    function show() {
        if (isStandalone() || dismissed()) return;
        var el = bar();
        el.classList.remove('hidden');

        var install = document.getElementById('installBtn');
        var later = document.getElementById('installLater');

        install.onclick = function () {
            if (deferred) {
                deferred.prompt();
                deferred.userChoice.then(function (choice) {
                    if (choice && choice.outcome === 'accepted') el.classList.add('hidden');
                    deferred = null;
                });
            } else if (isIos()) {
                alert('لتثبيت التطبيق على الآيفون:\nاضغط زر المشاركة ⤴ ثم «إضافة إلى الشاشة الرئيسية».\n\nلتثبيت التطبيق على أندرويد:\nافتح قائمة المتصفح ⋮ ثم «تثبيت التطبيق».');
            } else {
                alert('لتثبيت التطبيق:\nافتح قائمة المتصفح ⋮ ثم «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».');
            }
        };
        later.onclick = function () {
            el.classList.add('hidden');
            try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* private mode */ }
        };
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferred = event;
        show();
    });

    window.addEventListener('appinstalled', function () {
        var el = document.getElementById('installBar');
        if (el) el.classList.add('hidden');
        deferred = null;
    });

    // iOS never fires beforeinstallprompt; offer the manual route once the app
    // is ready, but only when it is not already installed.
    window.addEventListener('load', function () {
        if (isIos() && !isStandalone()) setTimeout(show, 2500);
    });

    // Register the service worker that makes installation possible at all.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () { /* http:// or unsupported */ });
        });
    }

    window.PWAInstall = { show: show, isStandalone: isStandalone };
})();
