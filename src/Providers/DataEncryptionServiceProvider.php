<?php

namespace PalakRajput\DataEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;

class DataEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../../config/data-encryption.php', 
            'data-encryption'
        );

        // Register services with version-specific bindings
        $this->registerServices();
        
        // Register facades if they exist
        $this->registerFacades();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Only publish config and migrations in console mode
        if ($this->app->runningInConsole()) {
            $this->publishResources();
            $this->registerCommands();
        }
        
        // Register Blade directives if Laravel version supports it
        $this->registerBladeDirectives();
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * Register package services.
     *
     * @return void
     */
    protected function registerServices()
    {
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService($app['config']['data-encryption']);
        });

        $this->app->singleton(MeilisearchService::class, function ($app) {
            return new MeilisearchService($app['config']['data-encryption']);
        });

        $this->app->singleton(HashService::class, function ($app) {
            return new HashService($app['config']['data-encryption']);
        });
        
        // Alias for easier access
        $this->app->alias(EncryptionService::class, 'encryption.service');
        $this->app->alias(MeilisearchService::class, 'meilisearch.service');
        $this->app->alias(HashService::class, 'hash.service');
    }

    /**
     * Register facades if they exist.
     *
     * @return void
     */
    protected function registerFacades()
    {
        // Only register facade if the class exists
        if (class_exists('PalakRajput\\DataEncryption\\Facades\\DataEncryption')) {
            $this->app->alias(
                'PalakRajput\\DataEncryption\\Facades\\DataEncryption',
                'DataEncryption'
            );
        }
    }

    /**
     * Publish package resources.
     *
     * @return void
     */
    protected function publishResources()
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
        ], 'data-encryption-config');

        // Publish migrations (with version checking)
        $this->publishMigrations();

        // Publish views if they exist
        if (file_exists(__DIR__.'/../../resources/views')) {
            $this->publishes([
                __DIR__.'/../../resources/views' => resource_path('views/vendor/data-encryption'),
            ], 'data-encryption-views');
        }
    }

    /**
     * Publish migrations with version compatibility.
     *
     * @return void
     */
    protected function publishMigrations()
    {
        $migrationsPath = __DIR__.'/../../database/migrations';
        
        // Check Laravel version to determine migration path
        $laravelVersion = (int) app()->version();
        
        // Laravel 5.6+ uses different timestamp format than earlier versions
        $timestamp = date('Y_m_d_His');
        
        $this->publishes([
            // Hash columns migration
            $migrationsPath . '/add_hash_columns_to_users_table.php.stub' => 
                database_path("migrations/{$timestamp}_add_hash_columns_to_users_table.php"),
            
            // Optional: Domain columns for better search
            $migrationsPath . '/add_domain_columns_to_users_table.php.stub' =>
                database_path("migrations/{$timestamp}_add_domain_columns_to_users_table.php"),
        ], 'data-encryption-migrations');
    }

    /**
     * Register package commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        $this->commands([
            \PalakRajput\DataEncryption\Console\Commands\InstallEncryptionCommand::class,
            \PalakRajput\DataEncryption\Console\Commands\EncryptDataCommand::class,
            \PalakRajput\DataEncryption\Console\Commands\ReindexMeilisearch::class,
            \PalakRajput\DataEncryption\Console\Commands\DebugSearchCommand::class,
        ]);
    }

    /**
     * Register Blade directives if supported.
     *
     * @return void
     */
    protected function registerBladeDirectives()
    {
        // Check if Blade facade exists (Laravel 5.0+)
        if (class_exists('Illuminate\Support\Facades\Blade')) {
            // Safe to use Blade directives
            \Illuminate\Support\Facades\Blade::directive('encrypted', function ($expression) {
                return "<?php echo app('encryption.service')->encrypt($expression); ?>";
            });
            
            \Illuminate\Support\Facades\Blade::directive('decrypted', function ($expression) {
                return "<?php echo app('encryption.service')->decrypt($expression); ?>";
            });
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
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