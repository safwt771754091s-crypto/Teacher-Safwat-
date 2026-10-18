<?php

return [
    'name' => 'معلمي الذكي اليمني',

    'owner' => [
        'name' => env('PLATFORM_OWNER', 'المعلم صفوت'),
        'title' => env('PLATFORM_OWNER_TITLE', 'معلم المنصة'),
        'email' => env('PLATFORM_OWNER_EMAIL', 'teacher@safwat.ye'),
    ],

    'repo' => [
        'name' => env('PLATFORM_REPO', 'Teacher-Safwat-'),
        'url' => env('PLATFORM_REPO_URL', 'https://github.com/safwt771754091s-crypto/Teacher-Safwat-'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    | Shared demo accounts (student/teacher/parent/owner with the password
    | "password") are a development convenience. They are never created in
    | production; set SEED_DEMO_USERS=true to force them on (e.g. a demo host).
    */
    'seed_demo_users' => (bool) env('SEED_DEMO_USERS', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Supabase (managed PostgreSQL)
    |--------------------------------------------------------------------------
    | The database is hosted on Supabase. Only non-secret coordinates live here;
    | the connection password is provided through the environment (DB_PASSWORD or
    | the full DB_URL) and never committed. Supabase Auth is intentionally NOT
    | used: authentication is Laravel Sanctum, so there is a single identity
    | system shared by the API and the web client.
    */
    'supabase' => [
        'project_ref' => env('SUPABASE_PROJECT_REF', 'jsrpfsybgeplciiqjzqr'),
        'url' => env('SUPABASE_URL', 'https://jsrpfsybgeplciiqjzqr.supabase.co'),
        'region' => env('SUPABASE_REGION', 'ap-south-1'),
        // Host of the Supabase connection pooler for the region above. The DB
        // user is "postgres.<project_ref>" when connecting through the pooler.
        'pooler_host' => env('SUPABASE_POOLER_HOST', 'aws-0-ap-south-1.pooler.supabase.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grounded AI teacher
    |--------------------------------------------------------------------------
    | The AI teacher never answers from model memory alone. Every explanation is
    | built from the lesson's approved textbook content (retrieval). When an LLM
    | provider is configured it may rephrase/structure that retrieved content,
    | but it is instructed to refuse outside the supplied source. When no provider
    | is configured the platform still explains lessons by composing the source
    | content directly, so nothing is ever invented.
    */
    'ai' => [
        'provider' => env('LLM_PROVIDER', 'none'), // none|openai|gemini
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'gpt-4o-mini'),
        'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('LLM_TIMEOUT', 30),
        'max_context_pages' => 4,
        'daily_free_questions' => (int) env('AI_DAILY_FREE_QUESTIONS', 20),
    ],

    'storage' => [
        'textbook_disk' => env('TEXTBOOK_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Textbook page images (in-app viewer)
    |--------------------------------------------------------------------------
    | When a book has no stored page image, its source PDF page is rasterised on
    | demand for the viewer. A moderate DPI keeps pages crisp on phones while
    | staying small enough for slow connections.
    */
    'page_image' => [
        'dpi' => (int) env('PAGE_IMAGE_DPI', 130),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-side Arabic text-to-speech
    |--------------------------------------------------------------------------
    | Fallback narration for devices with no Arabic TTS voice installed. The
    | client uses its on-device SpeechSynthesis first (free, offline) and only
    | calls the server when no Arabic voice is available, so the audio works on
    | every device. The spoken text is always the approved lesson content.
    */
    'tts' => [
        'enabled' => (bool) env('TTS_ENABLED', true),
        'base_url' => env('TTS_BASE_URL', 'https://translate.google.com/translate_tts'),
        'client' => env('TTS_CLIENT', 'tw-ob'),
        'timeout' => (int) env('TTS_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Arabic OCR (for scanned textbook pages)
    |--------------------------------------------------------------------------
    | Many official Yemeni textbook PDFs are scanned images with no embedded
    | text. When OCR is available the platform rasterises each PDF page and runs
    | tesseract (Arabic) to fill textbook_pages.extracted_text, which a human then
    | reviews and approves. OCR is never trusted blindly — output is marked for
    | review, and when the text is unclear the source image remains the record.
    */
    'ocr' => [
        'enabled' => (bool) env('OCR_ENABLED', true),
        'tesseract' => env('OCR_TESSERACT', 'tesseract'),
        'pdftoppm' => env('OCR_PDFTOPPM', 'pdftoppm'),
        'language' => env('OCR_LANGUAGE', 'ara'),
        // 300 dpi is noticeably cleaner for Arabic OCR than 160–220, which
        // matters because the official editions ship a broken text layer.
        'dpi' => (int) env('OCR_DPI', 300),
        'workers' => (int) env('OCR_WORKERS', 4),
        'threads' => (int) env('OCR_THREADS', 1),
    ],

    'game' => [
        'points_per_correct' => 10,
        'stars_perfect' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database snapshots
    |--------------------------------------------------------------------------
    | Portable SQL dumps written by `php artisan platform:db-snapshot` and the
    | owner dashboard. Kept on the app's private disk by default; point
    | DB_SNAPSHOT_PATH elsewhere (e.g. a mounted volume) to persist them.
    */
    'db_snapshots' => [
        'path' => env('DB_SNAPSHOT_PATH', storage_path('app/db-snapshots')),
        'keep' => (int) env('DB_SNAPSHOT_KEEP', 0),
        // Snapshot automatically before migrate:fresh / db:seed etc., keeping
        // only the newest few "auto pre-*" snapshots so backups cannot pile up.
        'before_destructive' => (bool) env('DB_SNAPSHOT_BEFORE', true),
        'pre_command_keep' => (int) env('DB_SNAPSHOT_PRE_KEEP', 5),
    ],
];
