/* API client + local state for معلمي الذكي اليمني */
(function () {
    const baseMeta = document.querySelector('meta[name="api-base"]');
    const API_BASE = (window.API_BASE_URL || (baseMeta && baseMeta.content) || '').replace(/\/$/, '') || (location.origin + '/api/v1');
    const TOKEN_KEY = 'ts_token';
    const USER_KEY = 'ts_user';

    const state = {
        token: localStorage.getItem(TOKEN_KEY) || null,
        user: JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
    };

    async function request(path, { method = 'GET', body = null, auth = true } = {}) {
        const headers = { 'Accept': 'application/json' };
        if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
        if (auth && state.token) headers['Authorization'] = 'Bearer ' + state.token;

        const res = await fetch(API_BASE + path, {
            method,
            headers,
            body: body ? (body instanceof FormData ? body : JSON.stringify(body)) : null,
        });

        let data = null;
        const text = await res.text();
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text }; }

        if (!res.ok) {
            if (res.status === 401) { logout(); }
            const err = new Error((data && (data.message || (data.errors && Object.values(data.errors)[0]?.[0]))) || 'حدث خطأ');
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
        state.user = null;
        state.token = null;
        localStorage.removeItem(USER_KEY);
        localStorage.removeItem(TOKEN_KEY);
        location.reload();
    }

    window.API = {
        API_BASE,
        state,
        setSession,
        logout,
        get: (p, opts) => request(p, { method: 'GET', ...opts }),
        post: (p, body, opts) => request(p, { method: 'POST', body, ...opts }),
        patch: (p, body, opts) => request(p, { method: 'PATCH', body, ...opts }),
    };
})();
