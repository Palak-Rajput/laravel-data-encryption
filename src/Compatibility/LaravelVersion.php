<?php

namespace PalakRajput\DataEncryption\Compatibility;

class LaravelVersion
{
    /**
     * Check Laravel version and apply compatibility fixes
     */
    public static function applyCompatibilityFixes()
    {
        $version = app()->version();
        $majorVersion = (int) substr($version, 0, strpos($version, '.'));
        
        // For Laravel 6-8, we need to adjust some configurations
        if ($majorVersion < 9) {
            self::fixForLaravel6To8();
        }
        
        // For Laravel 9+, everything should work as is
    }
    
    /**
     * Apply fixes for Laravel 6, 7, and 8
     */
    private static function fixForLaravel6To8()
    {
        // Ensure config exists
        if (!config('data-encryption')) {
            config(['data-encryption' => require __DIR__ . '/../../config/data-encryption.php']);
        }
        
        // Fix for different service provider registration in older Laravel
        if (!app()->bound(EncryptionService::class)) {
            app()->singleton(EncryptionService::class, function ($app) {
                return new EncryptionService($app['config']['data-encryption']);
            });
        }
    }
    
    /**
     * Get appropriate Meilisearch client version
     */
    public static function getMeilisearchClient()
    {
        $version = app()->version();
        $majorVersion = (int) substr($version, 0, strpos($version, '.'));
        
        $host = config('data-encryption.meilisearch.host', 'http://localhost:7700');
        $key = config('data-encryption.meilisearch.key', '');
        
        // Laravel 6-8 work better with older Meilisearch client
        if ($majorVersion < 9) {
            // Use older client instantiation method
            return new \Meilisearch\Client($host, $key);
        }
        
        // Laravel 9+ can use newer client
        return new \Meilisearch\Client($host, $key);
    }
    
    /**
     * Handle middleware registration for different Laravel versions
     */
    public static function registerMiddleware($kernel, $middleware)
    {
        $version = app()->version();
        $majorVersion = (int) substr($version, 0, strpos($version, '.'));
        
        if ($majorVersion >= 5) {
            $kernel->pushMiddleware($middleware);
        }
    }
}