<?php

namespace PalakRajput\DataEncryption;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ComposerScripts
{
    public static function postInstall($event)
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';
        
        $app = self::getLaravelApp();
        
        if ($app && self::isConsole()) {
            echo "\n🎉 Laravel Data Encryption Package Installed!\n";
            echo "===========================================\n";
            
            // Ask user if they want automatic setup
            if (self::isInteractive()) {
                echo "Do you want to run automatic setup now? (yes/no) [yes]: ";
                $handle = fopen("php://stdin", "r");
                $line = fgets($handle);
                fclose($handle);
                
                if (trim(strtolower($line)) === 'yes' || trim($line) === '') {
                    self::runAutoSetup($app);
                } else {
                    echo "\nYou can run setup manually:\n";
                    echo "php artisan data-encryption:install --auto --backup\n";
                }
            } else {
                // Non-interactive mode (CI/CD, scripts)
                echo "Run this command to setup:\n";
                echo "php artisan data-encryption:install --auto --backup\n";
            }
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
    
    private static function isInteractive()
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
    
    private static function runAutoSetup($app)
    {
        echo "\n🚀 Starting automatic setup...\n";
        
        try {
            // Run migrations first
            echo "📊 Running migrations...\n";
            $process = new Process(['php', 'artisan', 'migrate', '--force']);
            $process->setTimeout(300);
            $process->run();
            
            if ($process->isSuccessful()) {
                echo "✅ Migrations completed\n";
                
                // Run the installer
                echo "🔐 Running encryption setup...\n";
                $process = new Process([
                    'php', 'artisan', 
                    'data-encryption:install', 
                    '--auto', 
                    '--backup',
                    '--yes'
                ]);
                $process->setTimeout(300);
                $process->run();
                
                if ($process->isSuccessful()) {
                    echo "\n✅ Setup completed successfully!\n";
                    echo "Your email/phone data is now encrypted.\n";
                } else {
                    echo "\n⚠️  Setup had issues:\n";
                    echo $process->getErrorOutput();
                }
            } else {
                echo "\n❌ Migrations failed:\n";
                echo $process->getErrorOutput();
            }
        } catch (\Exception $e) {
            echo "\n❌ Error during setup: " . $e->getMessage() . "\n";
            echo "Run manually: php artisan data-encryption:install --auto --backup\n";
        }
    }
    
    private static function checkForBreakingChanges()
    {
        // Get current package version
        $packageJson = @file_get_contents(__DIR__ . '/../composer.json');
        if ($packageJson) {
            $packageData = json_decode($packageJson, true);
            $currentVersion = $packageData['version'] ?? '1.0.0';
            
            echo "\n🔄 Data Encryption Package Updated to v{$currentVersion}\n";
            echo "Run: php artisan data-encryption:install --auto\n\n";
        }
    }
}