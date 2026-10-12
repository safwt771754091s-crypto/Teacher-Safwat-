/* Resolves the API base URL for the SPA at runtime, so the same build works on
 * localhost and on the paired public demo hosts without hardcoding a host.
 * Order: explicit override -> paired prod-runtime host -> localhost port swap. */
(function () {
    var override = window.API_BASE_URL_OVERRIDE;
    if (override) {
        window.API_BASE_URL = String(override).replace(/\/$/, "") + "/api/v1";
        return;
    }

    var host = location.hostname;
    var paired = /^work-2-(.+)\.prod-runtime\.all-hands\.dev$/.exec(host);
    if (paired) {
        // Web is served on work-2; the API is paired on work-1 of the same id.
        window.API_BASE_URL = "https://work-1-" + paired[1] + ".prod-runtime.all-hands.dev/api/v1";
        return;
    }

    // Local demo: web on :12001, API on :12000.
    window.API_BASE_URL = location.origin.replace(/:\d+$/, ":12000") + "/api/v1";
})();
