<?php

namespace PalakRajput\DataEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;

class DataEncryptionServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/data-encryption.php', 'data-encryption'
        );
        
        $this->app->singleton('data-encryption', function ($app) {
            return new \PalakRajput\DataEncryption\DataEncryption(
                $app['config']['data-encryption']
            );
        });
        
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
        $this->publishes([
            __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
        ], 'config');
        
             $this->publishes([
            __DIR__.'/../../database/migrations/' => database_path('migrations'),
        ], 'migrations');


        
        if ($this->app->runningInConsole()) {
            $this->commands([
                \PalakRajput\DataEncryption\Console\Commands\InstallEncryptionCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\EncryptDataCommand::class,
            ]);
            
        }
    }
}