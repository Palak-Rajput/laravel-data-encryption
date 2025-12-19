<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption Settings
    |--------------------------------------------------------------------------
    */
    
    'encryption' => [
        'cipher' => env('ENCRYPTION_CIPHER', 'AES-256-CBC'),
        'key' => env('ENCRYPTION_KEY', env('APP_KEY')),
    ],
    
    'encrypted_fields' => [
        'App\Models\User' => ['email', 'phone'],
    ],
    
    'searchable_fields' => [
        'App\Models\User' => ['email', 'phone'],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Hash Settings for Search
    |--------------------------------------------------------------------------
    */
    
    'hashing' => [
        'algorithm' => env('HASH_ALGORITHM', 'sha256'),
        'salt' => env('HASH_SALT', 'laravel-data-encryption'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    */
    
    'meilisearch' => [
        'enabled' => env('MEILISEARCH_ENABLED', true),
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index_prefix' => env('MEILISEARCH_INDEX_PREFIX', 'encrypted_'),
        // Default index settings
        'index_settings' => [
            'searchableAttributes' => ['name'],
            'filterableAttributes' => ['email_hash', 'phone_hash'],
            'sortableAttributes' => ['created_at', 'name'],
            'typoTolerance' => ['enabled' => true],
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Partial Search Settings
    |--------------------------------------------------------------------------
    */
    
    'partial_search' => [
        'enabled' => true,
        'include_full_email' => false,
        'min_part_length' => 3, // Minimum length for n-grams
        'email_separators' => ['@', '.', '-', '_', '+'],
    ],
     'disable_console_logs' => env('DISABLE_FRONTEND_CONSOLE_LOG', false),
];