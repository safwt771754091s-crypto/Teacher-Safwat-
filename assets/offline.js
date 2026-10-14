/* Offline support for معلمي الذكي اليمني.
 *
 * "Save for offline" pulls a book's lessons (and each lesson's exercises) from
 * the API and stores the JSON in a dedicated cache, so the installed app keeps
 * working with no network. Book PDFs live in Supabase Storage; the offline HTML
 * copy can also be saved. Nothing is bundled into the app, so it stays light.
 */
(function () {
    const BOOKS_CACHE = 'ts-books-v1';
    const META_KEY = 'ts_offline_books';

    const saved = () => {
        try { return JSON.parse(localStorage.getItem(META_KEY) || '{}'); } catch (e) { return {}; }
    };
    const persist = (map) => localStorage.setItem(META_KEY, JSON.stringify(map));

    function isSaved(bookId) { return !!saved()[String(bookId)]; }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        });
    }

    // Fetch a URL and cache the response body under its own URL (same key the
    // service worker looks up when offline).
    async function cacheUrl(cache, url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (res.ok) await cache.put(url, res.clone());
        return res.ok;
    }

    async function saveBook(bookId, onProgress, context) {
        if (!('caches' in window)) throw new Error('المتصفح لا يدعم الحفظ بدون إنترنت');
        const cache = await caches.open(BOOKS_CACHE);
        const base = window.API.API_BASE;
        const ctx = context || {};

        onProgress && onProgress('تحميل دروس الكتاب…');
        const lessonsUrl = `${base}/textbooks/${bookId}/lessons`;
        const lessonsRes = await fetch(lessonsUrl, { headers: { 'Accept': 'application/json' } });
        const data = await lessonsRes.json();
        const lessons = data.lessons || [];
        await cache.put(lessonsUrl, new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } }));

        // Warm the whole browse path the app walks when offline: the grade list,
        // the grade's subjects, the subject's textbooks, then every lesson with
        // its exercises. Without this the offline user would hit a dead end.
        const urls = [`${base}/grades`];
        if (ctx.gradeId) urls.push(`${base}/grades/${ctx.gradeId}/subjects`);
        if (ctx.subjectId) urls.push(`${base}/subjects/${ctx.subjectId}/textbooks`);
        for (const lesson of lessons) {
            urls.push(`${base}/lessons/${lesson.id}`);
            urls.push(`${base}/lessons/${lesson.id}/exercises`);
        }

        let done = 0;
        for (const url of urls) {
            try { await cacheUrl(cache, url); } catch (e) { /* keep going */ }
            done++;
            onProgress && onProgress(`حفظ المحتوى… ${done}/${urls.length}`);
        }

        const map = saved();
        map[String(bookId)] = { title: data.textbook ? data.textbook.title : '', at: Date.now(), lessons: lessons.length };
        persist(map);
        return { lessons: lessons.length };
    }

    // The offline cache is shared across books and refreshed on each save, so
    // removing a book just clears its marker (its lessons are dropped on the
    // next refresh of another book, or by clearing site data).
    async function removeBook(bookId) {
        const map = saved();
        delete map[String(bookId)];
        persist(map);
    }

    function list() { return saved(); }

    window.Offline = { registerServiceWorker, saveBook, removeBook, isSaved, list };
})();
