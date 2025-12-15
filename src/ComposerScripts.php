<?php

namespace PalakRajput\DataEncryption;

class ComposerScripts
{
    public static function postInstall($event)
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';
        
        $app = self::getLaravelApp();
        
        if ($app && self::isConsole()) {
            self::runPostInstallation($app);
        }
    }
    
    public static function postUpdate($event)
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';
        
        $app = self::getLaravelApp();
        
        if ($app && self::isConsole()) {
            self::checkForBreakingChanges();
        }
    }
    
    private static function getLaravelApp()
    {
        // Try to get Laravel application instance
        if (function_exists('app')) {
            try {
                return app();
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }
    
    private static function isConsole()
    {
        return php_sapi_name() === 'cli';
    }
    
    private static function runPostInstallation($app)
    {
        // Only show message if running in console (not in Composer script)
        if (self::isConsole() && !isset($_SERVER['COMPOSER_DEV_MODE'])) {
            echo "\n";
            echo "🎉 Laravel Data Encryption Package Installed!\n";
            echo "===========================================\n";
            echo "Run ONE of these commands to setup:\n";
            echo "\n";
            echo "1. Interactive setup (recommended):\n";
            echo "   php artisan data-encryption:install\n";
            echo "\n";
            echo "2. Automatic setup (with backups):\n";
            echo "   php artisan data-encryption:install --auto --backup\n";
            echo "\n";
            echo "3. Silent automatic setup (no backups):\n";
            echo "   php artisan data-encryption:install --auto --yes\n";
            echo "\n";
            echo "⚠️  WARNING: This package encrypts data IN-PLACE!\n";
            echo "   Backup your database before running encryption!\n";
            echo "\n";
        }
    }
    
    private static function checkForBreakingChanges()
    {
        // Get current package version
        $packageJson = file_get_contents(__DIR__ . '/../composer.json');
        $packageData = json_decode($packageJson, true);
        $currentVersion = $packageData['version'] ?? '1.0.0';
        
        // Store version for future updates
        $versionFile = storage_path('app/data-encryption.version');
        
        if (file_exists($versionFile)) {
            $installedVersion = file_get_contents($versionFile);
            
            if (version_compare($currentVersion, $installedVersion, '>')) {
                echo "\n🔄 Data Encryption Package Updated to v{$currentVersion}\n";
                echo "Please run: php artisan data-encryption:install --auto\n\n";
            }
        }
        
        // Always update version file
        @file_put_contents($versionFile, $currentVersion);
    }
}