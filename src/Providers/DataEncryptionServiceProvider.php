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
    public function register()
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../../config/data-encryption.php', 'data-encryption'
        );

        // Bind services as singletons
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService($app['config']['data-encryption']);
        });

        $this->app->singleton(MeilisearchService::class, function ($app) {
            return new MeilisearchService($app['config']['data-encryption']);
        });

        $this->app->singleton(HashService::class, function ($app) {
            return new HashService($app['config']['data-encryption']);
        });
    }

    public function boot()
    {
        if (!$this->app->runningInConsole()) {
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(InjectDisableConsole::class);
    }
        // Publish config and migrations
        $this->publishes([
            __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'data-encryption');

        // Blade directive (optional)
        Blade::directive('disableConsole', function () {
            return "<?php echo view('data-encryption::disable-console')->render(); ?>";
        });

        // Register package Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \PalakRajput\DataEncryption\Console\Commands\InstallEncryptionCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\EncryptDataCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\ReindexMeilisearch::class,
                \PalakRajput\DataEncryption\Console\Commands\DebugSearchCommand::class,
            ]);
        }

       
    }
}
