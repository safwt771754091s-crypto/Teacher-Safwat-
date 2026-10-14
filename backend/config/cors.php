<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The static web client is served from GitHub Pages (and the paired demo
| hosts), so it calls this API cross-origin. Only the origins listed in
| CORS_ALLOWED_ORIGINS (comma-separated) are allowed in production; the
| wildcard is never used together with credentials.
|
| Default (no env): local development origins so `php artisan serve` works
| out of the box. Always set CORS_ALLOWED_ORIGINS explicitly in production.
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

if (empty($allowedOrigins)) {
    $allowedOrigins = [
        'http://localhost:12001',
        'http://127.0.0.1:12001',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:5173',
    ];
}

/*
| Origins that are safe to allow by pattern (bearer tokens, no cookies):
| - the paired Agent Canvas demo host (web on work-2 calls the API on work-1),
| - GitHub Pages project/user sites (the published static client).
| Production deployments should still set CORS_ALLOWED_ORIGINS explicitly.
*/
$allowedOriginPatterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
)));

if (empty($allowedOriginPatterns)) {
    $allowedOriginPatterns = [
        '#^https://work-2-[a-z0-9]+\.prod-runtime\.all-hands\.dev$#',
        '#^https://[a-z0-9-]+\.github\.io$#',
    ];
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer tokens are used (not cookies), so credentials can stay off; this
    // keeps the CORS surface as small as possible.
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];
