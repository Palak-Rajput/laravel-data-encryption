<?php

namespace PalakRajput\DataEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Contracts\Http\Kernel;

class DataEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Laravel version detection
     */
    private function getLaravelMajorVersion()
    {
        $version = app()->version();
        if (preg_match('/(\d+)\./', $version, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    public function register()
    {
        // Merge config if file exists
        $configPath = __DIR__.'/../../config/data-encryption.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'data-encryption');
        } else {
            // Fallback config
            $this->app['config']->set('data-encryption', $this->getDefaultConfig());
        }

        // Bind services
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService($app['config']->get('data-encryption', []));
        });

        $this->app->singleton(MeilisearchService::class, function ($app) {
            return new MeilisearchService($app['config']->get('data-encryption', []));
        });

        $this->app->singleton(HashService::class, function ($app) {
            return new HashService($app['config']->get('data-encryption', []));
        });

        // Alias for easier access
        $this->app->alias(EncryptionService::class, 'encryption.service');
        $this->app->alias(MeilisearchService::class, 'meilisearch.service');
        $this->app->alias(HashService::class, 'hash.service');
    }

    public function boot()
    {
        // IMPORTANT: Do NOT register middleware globally for login routes
        // This was causing login issues
        
        // Publish config if it exists
        if (file_exists(__DIR__.'/../../config/data-encryption.php')) {
            $this->publishes([
                __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
            ], 'config');
        }

        // Publish migrations
        $this->publishMigrations();

        // Load views if they exist
        if (file_exists(__DIR__.'/../../resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../../resources/views', 'data-encryption');
        }

        // Register package Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \PalakRajput\DataEncryption\Console\Commands\InstallEncryptionCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\EncryptDataCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\ReindexMeilisearch::class,
                \PalakRajput\DataEncryption\Console\Commands\DebugSearchCommand::class,
            ]);
        }

        // Auto-detect and configure User model
        $this->autoConfigureModels();
    }

    /**
     * Publish migrations
     */
    private function publishMigrations()
    {
        $migrationsPath = __DIR__.'/../../database/migrations';
        
        if (!file_exists($migrationsPath)) {
            return;
        }
        
        $migrationFiles = glob($migrationsPath . '/*.php');
        
        foreach ($migrationFiles as $migrationFile) {
            $filename = basename($migrationFile);
            $timestamp = date('Y_m_d_His', time() + rand(1, 10));
            
            $this->publishes([
                $migrationFile => database_path("migrations/{$timestamp}_{$filename}"),
            ], 'migrations');
        }
    }

    /**
     * Auto-configure models
     */
    private function autoConfigureModels()
    {
        $laravelVersion = $this->getLaravelMajorVersion();
        
        // Determine User model based on Laravel version
        if ($laravelVersion >= 8) {
            $userModel = 'App\Models\User';
        } elseif ($laravelVersion >= 5) {
            $userModel = 'App\User';
        } else {
            $userModel = 'User';
        }
        
        // Get existing config
        $config = config('data-encryption', []);
        
        // Set default encrypted fields EXCEPT password
        if (!isset($config['encrypted_fields'][$userModel])) {
            config([
                'data-encryption.encrypted_fields' => [
                    $userModel => ['email', 'phone'] // DO NOT encrypt password field
                ]
            ]);
        }
        
        // Set default searchable fields
        if (!isset($config['searchable_fields'])) {
            config([
                'data-encryption.searchable_fields' => [
                    $userModel => ['email', 'phone']
                ]
            ]);
        }
    }

    /**
     * Default configuration
     */
    private function getDefaultConfig()
    {
        $laravelVersion = $this->getLaravelMajorVersion();
        
        if ($laravelVersion >= 8) {
            $userModel = 'App\Models\User';
        } elseif ($laravelVersion >= 5) {
            $userModel = 'App\User';
        } else {
            $userModel = 'User';
        }
        
        return [
            'encryption' => [
                'cipher' => 'AES-256-CBC',
                'key' => env('ENCRYPTION_KEY', env('APP_KEY')),
            ],
            'encrypted_fields' => [
                $userModel => ['email', 'phone'], // DO NOT include password
            ],
            'searchable_fields' => [
                $userModel => ['email', 'phone'],
            ],
            'hashing' => [
                'algorithm' => 'sha256',
                'salt' => 'laravel-data-encryption',
            ],
            'meilisearch' => [
                'enabled' => true,
                'host' => 'http://localhost:7700',
                'key' => '',
                'index_prefix' => 'encrypted_',
                'index_settings' => [
                    'searchableAttributes' => ['name', 'email_parts', 'phone_token'],
                    'filterableAttributes' => ['email_hash', 'phone_hash'],
                    'sortableAttributes' => ['created_at', 'name'],
                    'typoTolerance' => ['enabled' => true],
                ],
            ],
            'partial_search' => [
                'enabled' => true,
                'min_part_length' => 3,
                'email_separators' => ['@', '.', '-', '_', '+'],
            ],
        ];
    }

    public function provides()
    {
        return [
            EncryptionService::class,
            MeilisearchService::class,
            HashService::class,
            'encryption.service',
            'meilisearch.service',
            'hash.service',
        ];
    }
}