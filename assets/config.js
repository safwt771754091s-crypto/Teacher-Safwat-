/* Resolves the API base URL for the SPA at runtime, so the same build works on
 * localhost, on the paired public demo hosts, and on GitHub Pages without
 * rebuilding. Order:
 *   1. window.API_BASE_URL_OVERRIDE  (set inline, or by the host page)
 *   2. <meta name="api-base">        (set in index.html for a fixed deployment)
 *   3. PROD_API_ORIGIN               (the published API, used on *.github.io)
 *   4. paired prod-runtime host      (work-2 web -> work-1 API)
 *   5. localhost port swap           (web :12001 -> API :12000)
 *
 * To point the published site at a permanent API server, set PROD_API_ORIGIN
 * below (origin only, no trailing slash, no /api/v1). Never hardcode a
 * localhost URL there.
 */
(function () {
    // Origin of the deployed API. Leave empty until the API host is known; the
    // app then falls back to the meta tag / paired host / localhost.
    var PROD_API_ORIGIN = "https://yemeni-smart-teacher-api.onrender.com";

    function withApi(origin) {
        return String(origin).replace(/\/+$/, "") + "/api/v1";
    }

    // Supabase client coordinates (public/publishable — safe in the browser).
    // GET /api/v1/config returns these so the SPA needs no rebuild to point at
    // a different project. Values are filled in lazily by fetchSupabaseConfig().
    window.SUPABASE_URL = window.SUPABASE_URL || "";
    window.SUPABASE_PUBLISHABLE_KEY = window.SUPABASE_PUBLISHABLE_KEY || "";
    window.fetchSupabaseConfig = function () {
        return fetch(window.API_BASE_URL + "/config", {
            headers: { "Accept": "application/json" },
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (cfg) {
                if (cfg && cfg.supabase) {
                    if (cfg.supabase.url) window.SUPABASE_URL = cfg.supabase.url;
                    if (cfg.supabase.publishable_key) {
                        window.SUPABASE_PUBLISHABLE_KEY = cfg.supabase.publishable_key;
                    }
                }
                return window.SUPABASE_URL;
            })
            .catch(function () { return null; });
    };

    var override = window.API_BASE_URL_OVERRIDE;
    if (override) {
        window.API_BASE_URL = withApi(override);
        return;
    }

    var meta = document.querySelector('meta[name="api-base"]');
    if (meta && meta.content) {
        window.API_BASE_URL = withApi(meta.content);
        return;
    }

    var host = location.hostname;

    // Published static site (GitHub Pages): talk to the configured API origin.
    if (/\.github\.io$/.test(host) && PROD_API_ORIGIN) {
        window.API_BASE_URL = withApi(PROD_API_ORIGIN);
        return;
    }

    var paired = /^work-2-(.+)\.prod-runtime\.all-hands\.dev$/.exec(host);
    if (paired) {
        // Web is served on work-2; the API is paired on work-1 of the same id.
        window.API_BASE_URL = withApi("https://work-1-" + paired[1] + ".prod-runtime.all-hands.dev");
        return;
    }

    // Local demo: web on :12001, API on :12000.
    window.API_BASE_URL = location.origin.replace(/:\d+$/, ":12000") + "/api/v1";
})();
