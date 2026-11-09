<?php

use App\Support\DemoAccounts;

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
    | "password") keep the platform usable the moment it boots - a login screen
    | nobody can pass is not a working deployment. See App\Support\DemoAccounts
    | for the decision: always in development, and in production only when the
    | database cannot keep data (SQLite on an ephemeral host). Set
    | SEED_DEMO_USERS explicitly to override.
    */
    'seed_demo_users' => DemoAccounts::shouldSeed(
        env('APP_ENV', 'production'),
        env('DB_CONNECTION', 'sqlite'),
        env('SEED_DEMO_USERS') === null ? null : (bool) env('SEED_DEMO_USERS'),
    ),

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
        // Free tiers answer at a limited rate; retry rather than fail the run.
        'retries' => (int) env('LLM_RETRIES', 3),
        'max_context_pages' => 4,
        'daily_free_questions' => (int) env('AI_DAILY_FREE_QUESTIONS', 20),

        // OpenAI-compatible endpoints. Choosing a provider picks its base URL, so
        // the owner never has to know one, and can switch to a free provider when
        // an OpenAI account runs out of credit.
        'endpoints' => [
            'pollinations' => 'https://text.pollinations.ai/openai',
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'groq' => 'https://api.groq.com/openai/v1',
            'together' => 'https://api.together.xyz/v1',
            'deepseek' => 'https://api.deepseek.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'xai' => 'https://api.x.ai/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        ],

        // Providers that answer without a key. The teacher is "ready" for these
        // even with no key stored, so the owner can run free with no signup.
        'keyless' => ['pollinations'],

        'models' => [
            'pollinations' => 'openai',
            'openai' => 'gpt-4o-mini',
            'openrouter' => 'google/gemma-4-31b-it:free',
            'groq' => 'llama-3.3-70b-versatile',
            'gemini' => 'gemini-2.0-flash',
            'deepseek' => 'deepseek-chat',
            'mistral' => 'mistral-small-latest',
            'together' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
            'xai' => 'grok-2-latest',
        ],
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
        // Narration pace. Slightly below 1.0 sounds like a teacher explaining and
        // is easier to follow than the provider's default speed.
        'speed' => (float) env('TTS_SPEED', 0.9),
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
        // Mirror every snapshot to a remote disk (Supabase Storage by default)
        // so a backup survives a local wipe. Enabled by setting
        // DB_SNAPSHOT_REMOTE=1 (and providing the disk's credentials).
        'remote' => [
            'enabled' => (bool) env('DB_SNAPSHOT_REMOTE', false),
            'disk' => env('DB_SNAPSHOT_REMOTE_DISK', 'supabase'),
            'path' => env('DB_SNAPSHOT_REMOTE_PATH', 'db-snapshots'),
            'keep' => (int) env('DB_SNAPSHOT_REMOTE_KEEP', 0),
        ],
    ],
];
