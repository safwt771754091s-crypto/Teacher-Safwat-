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

    'game' => [
        'points_per_correct' => 10,
        'stars_perfect' => 3,
    ],
];
