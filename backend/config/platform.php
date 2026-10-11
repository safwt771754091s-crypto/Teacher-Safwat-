<?php

return [
    'name' => 'معلمي الذكي اليمني',

    'owner' => [
        'name' => env('PLATFORM_OWNER', 'المعلم صفوت'),
        'title' => env('PLATFORM_OWNER_TITLE', 'معلم المنصة'),
        'email' => env('PLATFORM_OWNER_EMAIL', 'teacher@safwat.ye'),
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
        'dpi' => (int) env('OCR_DPI', 160),
        'workers' => (int) env('OCR_WORKERS', 4),
        'threads' => (int) env('OCR_THREADS', 1),
    ],

    'game' => [
        'points_per_correct' => 10,
        'stars_perfect' => 3,
    ],
];
