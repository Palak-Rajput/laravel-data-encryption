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
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index_prefix' => env('MEILISEARCH_INDEX_PREFIX', 'encrypted_'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Fields to Encrypt by Default
    |--------------------------------------------------------------------------
    */
    
    'default_fields' => [
        'email',
        'phone',
        'ssn',
        'credit_card',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Models to Auto-Encrypt
    |--------------------------------------------------------------------------
    */
    
    'models' => [
        // 'App\Models\User' => ['email', 'phone'],
    ],
];