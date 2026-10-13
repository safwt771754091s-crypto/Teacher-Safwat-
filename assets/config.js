/* Resolves the API base URL for the SPA at runtime, so the same build works on
 * localhost, on the paired public demo hosts, and on GitHub Pages without
 * rebuilding. Order: explicit override -> GitHub Pages -> paired prod-runtime
 * host -> localhost port swap.
 *
 * To point the published site at a permanent API server, set PROD_API_ORIGIN
 * below (origin only, no trailing slash, no /api/v1). */
(function () {
    // Origin of the deployed API (e.g. "https://api.example.com").
    var PROD_API_ORIGIN = "https://work-1-uiupswnlbcdgccgq.prod-runtime.all-hands.dev";

    var override = window.API_BASE_URL_OVERRIDE;
    if (override) {
        window.API_BASE_URL = String(override).replace(/\/$/, "") + "/api/v1";
        return;
    }

    var host = location.hostname;

    // Published static site (GitHub Pages): talk to the configured API origin.
    if (/\.github\.io$/.test(host) && PROD_API_ORIGIN) {
        window.API_BASE_URL = PROD_API_ORIGIN.replace(/\/$/, "") + "/api/v1";
        return;
    }

    var paired = /^work-2-(.+)\.prod-runtime\.all-hands\.dev$/.exec(host);
    if (paired) {
        // Web is served on work-2; the API is paired on work-1 of the same id.
        window.API_BASE_URL = "https://work-1-" + paired[1] + ".prod-runtime.all-hands.dev/api/v1";
        return;
    }

    // Local demo: web on :12001, API on :12000.
    window.API_BASE_URL = location.origin.replace(/:\d+$/, ":12000") + "/api/v1";
})();
