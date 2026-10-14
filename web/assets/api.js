/* API client + local session state for معلمي الذكي اليمني.
 *
 * Auth model: Laravel Sanctum bearer tokens. The token is stored in
 * localStorage and sent as `Authorization: Bearer …` on every request. A 401
 * means the token is missing/expired/revoked, so we clear the session and let
 * the app show the login screen again.
 */
(function () {
    const baseMeta = document.querySelector('meta[name="api-base"]');
    const API_BASE = (window.API_BASE_URL || (baseMeta && baseMeta.content) || '')
        .replace(/\/$/, '') || (location.origin + '/api/v1');

    const TOKEN_KEY = 'ts_token';
    const USER_KEY = 'ts_user';

    const state = {
        token: localStorage.getItem(TOKEN_KEY) || null,
        user: JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
    };

    // Called by the app when a session expires so it can return to the login
    // screen without a full reload. Kept as a hook to avoid a hard dependency
    // on app.js from this low-level module.
    let onUnauthorized = null;

    function clearSession() {
        state.user = null;
        state.token = null;
        localStorage.removeItem(USER_KEY);
        localStorage.removeItem(TOKEN_KEY);
    }

    async function request(path, { method = 'GET', body = null, auth = true } = {}) {
        const headers = { 'Accept': 'application/json' };
        if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
        if (auth && state.token) headers['Authorization'] = 'Bearer ' + state.token;

        let res;
        try {
            res = await fetch(API_BASE + path, {
                method,
                headers,
                body: body ? (body instanceof FormData ? body : JSON.stringify(body)) : null,
            });
        } catch (networkError) {
            // fetch only rejects on a genuine network/connection failure.
            const err = new Error('تعذّر الاتصال بالخادم. تأكد من اتصالك بالإنترنت ثم أعد المحاولة.');
            err.isNetworkError = true;
            throw err;
        }

        let data = null;
        const text = await res.text();
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text }; }

        if (!res.ok) {
            if (res.status === 401 && auth) {
                clearSession();
                if (onUnauthorized) onUnauthorized();
            }
            const firstError = data && data.errors ? Object.values(data.errors)[0] : null;
            const message = (data && data.message)
                || (firstError && firstError[0])
                || (res.status >= 500 ? 'حدث خطأ في الخادم. حاول مرة أخرى بعد قليل.' : 'حدث خطأ');
            const err = new Error(message);
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    function setSession(user, token) {
        state.user = user;
        state.token = token;
        localStorage.setItem(USER_KEY, JSON.stringify(user));
        localStorage.setItem(TOKEN_KEY, token);
    }

    function logout() {
        clearSession();
        location.reload();
    }

    window.API = {
        API_BASE,
        state,
        setSession,
        logout,
        setUnauthorizedHandler: (fn) => { onUnauthorized = fn; },
        get: (p, opts) => request(p, { method: 'GET', ...opts }),
        post: (p, body, opts) => request(p, { method: 'POST', body, ...opts }),
        patch: (p, body, opts) => request(p, { method: 'PATCH', body, ...opts }),
    };
})();
