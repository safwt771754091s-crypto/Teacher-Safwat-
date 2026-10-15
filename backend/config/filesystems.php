<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Supabase Storage (S3-compatible). Textbook PDFs/images live in a
         * Supabase bucket so every app instance (and the offline PWA) can pull
         * them from one place. Set TEXTBOOK_DISK=supabase in production and
         * provide the S3 credentials + bucket in the environment.
         */
        'supabase' => [
            'driver' => 's3',
            'key' => env('SUPABASE_S3_ACCESS_KEY_ID'),
            'secret' => env('SUPABASE_S3_SECRET_ACCESS_KEY'),
            'region' => env('SUPABASE_S3_REGION', env('SUPABASE_REGION', 'ap-south-1')),
            'bucket' => env('SUPABASE_S3_BUCKET', 'textbooks'),
            'endpoint' => env('SUPABASE_S3_ENDPOINT', 'https://'.env('SUPABASE_PROJECT_REF', 'jsrpfsybgeplciiqjzqr').'.storage.supabase.co/storage/v1/s3'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            // The S3 endpoint URL is not browsable, so give the disk its real
            // public object base. Book pages load straight from here (the CDN)
            // instead of being proxied by the API.
            'url' => env('SUPABASE_STORAGE_URL', 'https://'.env('SUPABASE_PROJECT_REF', 'jsrpfsybgeplciiqjzqr').'.supabase.co/storage/v1/object/public'),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
