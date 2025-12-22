<?php

namespace PalakRajput\DataEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Contracts\Http\Kernel;
use PalakRajput\DataEncryption\Http\Middleware\InjectDisableConsole;

class DataEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Laravel version detection
     */
    private function getLaravelMajorVersion()
    {
        $version = app()->version();
        // Extract major version from string like "Laravel Framework 8.83.27"
        if (preg_match('/(\d+)\./', $version, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Check if running Laravel 8 or higher
     */
    private function isLaravel8OrHigher()
    {
        return $this->getLaravelMajorVersion() >= 8;
    }

    /**
     * Check if running Laravel 9 or higher
     */
    private function isLaravel9OrHigher()
    {
        return $this->getLaravelMajorVersion() >= 9;
    }

    /**
     * Get appropriate Meilisearch client based on Laravel version
     */
    private function getMeilisearchClient()
    {
        $host = config('data-encryption.meilisearch.host', 'http://localhost:7700');
        $key = config('data-encryption.meilisearch.key', '');
        
        // Laravel 6-8: Use older client method
        if ($this->getLaravelMajorVersion() < 9) {
            // For older Laravel versions, we need to be careful with client instantiation
            return new \Meilisearch\Client($host, $key);
        }
        
        // Laravel 9+: Can use newer client
        return new \Meilisearch\Client($host, $key);
    }

    public function register()
    {
        // Ensure config exists before merging
        $configPath = __DIR__.'/../../config/data-encryption.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'data-encryption');
        } else {
            // Fallback config for older Laravel versions
            $this->app['config']->set('data-encryption', $this->getDefaultConfig());
        }

        // Bind services with version compatibility
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService($app['config']->get('data-encryption', []));
        });

        $this->app->singleton(MeilisearchService::class, function ($app) {
            $config = $app['config']->get('data-encryption', []);
            
            // Inject compatible Meilisearch client
            $service = new MeilisearchService($config);
            
            // Use reflection to set the client if needed
            if ($this->getLaravelMajorVersion() < 9) {
                try {
                    $reflection = new \ReflectionClass($service);
                    $clientProperty = $reflection->getProperty('client');
                    $clientProperty->setAccessible(true);
                    $clientProperty->setValue($service, $this->getMeilisearchClient());
                } catch (\Exception $e) {
                    // Fallback - service will create its own client
                }
            }
            
            return $service;
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
        // Middleware registration with version check
        if (!$this->app->runningInConsole()) {
            try {
                $kernel = $this->app->make(Kernel::class);
                
                // Check if middleware can be pushed (Laravel 5.1+)
                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(InjectDisableConsole::class);
                }
            } catch (\Exception $e) {
                // Silently fail for older Laravel versions
            }
        }
        
        // Publish config and migrations
        $this->publishes([
            __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
        ], 'config');

        // Publish migrations with version-aware naming
        $this->publishMigrations();

        // Load views if they exist
        if (file_exists(__DIR__.'/../../resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../../resources/views', 'data-encryption');
        }

        // Blade directive only if Blade facade exists (Laravel 5.0+)
        if (class_exists('Illuminate\Support\Facades\Blade') && 
            method_exists('Illuminate\Support\Facades\Blade', 'directive')) {
            Blade::directive('disableConsole', function () {
                return "<?php echo view('data-encryption::disable-console')->render(); ?>";
            });
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

        // Auto-detect and configure User model based on Laravel version
        $this->autoConfigureModels();
    }

    /**
     * Publish migrations with compatibility
     */
    private function publishMigrations()
    {
        $migrationsPath = __DIR__.'/../../database/migrations';
        
        if (!file_exists($migrationsPath)) {
            return;
        }
        
        $timestamp = date('Y_m_d_His');
        
        $this->publishes([
            // Main migration
            $migrationsPath . '/add_hash_columns_to_users_table.php' => 
                database_path("migrations/{$timestamp}_add_hash_columns_to_users_table.php"),
        ], 'migrations');
    }

    /**
     * Auto-configure models based on Laravel version
     */
    private function autoConfigureModels()
    {
        $laravelVersion = $this->getLaravelMajorVersion();
        
        // Laravel 8+ uses App\Models\User, older versions use App\User
        $userModel = $laravelVersion >= 8 ? 'App\Models\User' : 'App\User';
        
        // Update config if it exists
        $config = config('data-encryption', []);
        
        if (!isset($config['encrypted_fields'][$userModel])) {
            config([
                'data-encryption.encrypted_fields' => [
                    $userModel => ['email', 'phone']
                ],
                'data-encryption.searchable_fields' => [
                    $userModel => ['email', 'phone']
                ]
            ]);
        }
    }

    /**
     * Default configuration for older Laravel versions
     */
    private function getDefaultConfig()
    {
        $laravelVersion = $this->getLaravelMajorVersion();
        $userModel = $laravelVersion >= 8 ? 'App\Models\User' : 'App\User';
        
        return [
            'encryption' => [
                'cipher' => 'AES-256-CBC',
                'key' => env('ENCRYPTION_KEY', env('APP_KEY')),
            ],
            'encrypted_fields' => [
                $userModel => ['email', 'phone'],
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

    /**
     * Get the services provided by the provider.
     */
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