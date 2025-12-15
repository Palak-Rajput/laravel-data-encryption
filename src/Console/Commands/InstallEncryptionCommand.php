<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class InstallEncryptionCommand extends Command
{
    protected $signature = 'data-encryption:install 
                            {--auto : Run all commands automatically (migrate + encrypt)}
                            {--yes : Skip all confirmation prompts (use with --auto)}
                            {--models= : Comma-separated list of models to encrypt}
                            {--fields= : Comma-separated list of fields to encrypt}
                            {--backup : Include backup columns in migration}';
    
    protected $description = 'Install and setup Data Encryption package automatically';
    
    public function handle()
    {
        $this->info('🔐 Installing Laravel Data Encryption Package...');
        $this->warn('⚠️  This package will ENCRYPTS DATA IN-PLACE in your existing columns!');
        
        $auto = $this->option('auto');
        $skipConfirm = $this->option('yes');
        
        // Ask for database backup confirmation
        if (!$skipConfirm && !$this->confirm('Have you backed up your database?', false)) {
            $this->error('Installation cancelled. Please backup your database first.');
            return 1;
        }
        
        // Step 1: Publish config
        $this->info('📄 Publishing configuration...');
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'config',
            '--force' => true
        ]);
        
        // Step 2: Publish migrations
        $this->info('📊 Publishing migrations...');
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'migrations',
            '--force' => true
        ]);
        
        // Step 3: Add environment variables
        $this->info('🔧 Setting up environment...');
        $this->addEnvironmentVariables();
        
        // Step 4: Generate encryption key
        $this->generateEncryptionKey();
        
        // Step 5: Setup Meilisearch (optional)
        if ($auto || $skipConfirm || $this->confirm('Setup Meilisearch for encrypted data search?', false)) {
            $this->setupMeilisearch();
        }
        
        // Step 6: Run migrations automatically if --auto flag
        if ($auto || $skipConfirm) {
            $this->info('🚀 Running migrations...');
            $this->call('migrate');
            
            // Step 7: Auto-detect models and encrypt
            $this->autoSetupModels($skipConfirm);
            
            $this->info('✅ Installation COMPLETE! All steps done automatically.');
        } else {
            $this->showNextSteps();
        }
    }
    
  // In InstallEncryptionCommand.php autoSetupModels() method:
protected function autoSetupModels($skipConfirm = false)
{
    $this->info('🤖 Auto-configuring models...');
    
    // Check for User model (most common)
    if (class_exists('App\Models\User')) {
        $this->setupUserModel();
        
        // Auto-encrypt if --auto flag
        if ($this->option('auto') || ($skipConfirm && $this->confirm('Encrypt existing User data now?', true))) {
            $this->info('🔐 Encrypting User data...');
            
            $backup = $this->option('backup') ? true : false;
            
            // Use --force when --auto is used
            $force = $this->option('auto') ? '--force' : '';
            
            $this->call('data-encryption:encrypt', [
                'model' => 'App\Models\User',
                '--backup' => $backup,
                '--chunk' => 1000,
                '--force' => $this->option('auto'), // Add this
            ]);
            
            $this->info('✅ User data encrypted successfully!');
        }
    } else {
        $this->warn('⚠️  User model not found. You need to add HasEncryptedFields trait manually.');
    }
}
    
    protected function setupUserModel()
    {
        $userModelPath = app_path('Models/User.php');
        
        if (!File::exists($userModelPath)) {
            $this->warn('⚠️  User model not found at: ' . $userModelPath);
            return;
        }
        
        $content = File::get($userModelPath);
        
        // Check if trait already added
        if (str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields')) {
            $this->info('✅ User model already imports HasEncryptedFields trait');
            
            // Check if properties exist
            if (!str_contains($content, 'protected static $encryptedFields')) {
                $this->addPropertiesToUserModel($content, $userModelPath);
                $this->info('✅ Added encrypted fields properties to User model');
            } else {
                $this->info('✅ User model already has encrypted fields properties');
            }
            return;
        }
        
        // Add the trait use statement after namespace
        $traitUse = "use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;";
        
        // Add after namespace
        $content = preg_replace(
            '/^(namespace App\\\\Models;)/m',
            "$1\n\n{$traitUse}",
            $content
        );
        
        // Add trait to the use statement in class
        // Find existing traits line (like: use HasApiTokens, HasFactory, Notifiable;)
        if (preg_match('/(use (?:[A-Za-z\\\\_,\s]+);)/', $content, $matches)) {
            // Add HasEncryptedFields to existing traits
            $newTraits = rtrim($matches[1], ';') . ', HasEncryptedFields;';
            $content = str_replace($matches[1], $newTraits, $content);
        } else {
            // Add new traits line after class opening
            $content = preg_replace(
                '/(class User extends \w+\s*\{)/',
                "$1\n    use HasEncryptedFields;",
                $content
            );
        }
        
        // Add encrypted fields properties
        $this->addPropertiesToUserModel($content, $userModelPath);
        
        $this->info('✅ Updated User model with HasEncryptedFields trait and properties');
    }
    
    protected function addPropertiesToUserModel(&$content, $filePath)
    {
        // Check if properties already exist
        if (str_contains($content, 'protected static $encryptedFields') && 
            str_contains($content, 'protected static $searchableHashFields')) {
            return;
        }
        
        // Find where to add properties (after the class opening or traits)
        $lines = explode("\n", $content);
        $newLines = [];
        $added = false;
        
        foreach ($lines as $line) {
            $newLines[] = $line;
            
            // Add properties after class opening brace or after traits
            if (!$added && (
                trim($line) === '{' || 
                str_contains($line, 'use HasApiTokens') ||
                str_contains($line, 'use HasEncryptedFields')
            )) {
                // Skip if next line is already a property
                $nextLine = next($lines) ?: '';
                if (!str_contains($nextLine, 'protected static $')) {
                    $newLines[] = '';
                    $newLines[] = '    protected static $encryptedFields = [\'email\', \'phone\'];';
                    $newLines[] = '    protected static $searchableHashFields = [\'email\', \'phone\'];';
                    $added = true;
                }
                continue;
            }
        }
        
        // If we still haven't added properties, add before fillable
        if (!$added) {
            $newContent = [];
            foreach ($lines as $line) {
                $newContent[] = $line;
                if (str_contains($line, 'protected $fillable')) {
                    array_splice($newContent, -1, 0, [
                        '',
                        '    protected static $encryptedFields = [\'email\', \'phone\'];',
                        '    protected static $searchableHashFields = [\'email\', \'phone\'];',
                    ]);
                    $added = true;
                }
            }
            $content = implode("\n", $newContent);
        } else {
            $content = implode("\n", $newLines);
        }
        
        File::put($filePath, $content);
    }
    
    protected function addEnvironmentVariables()
    {
        $envPath = base_path('.env');
        
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            
            $variables = [
                '# Data Encryption Package - ENCRYPTS DATA IN-PLACE',
                'ENCRYPTION_CIPHER=AES-256-CBC',
                'ENCRYPTION_KEY=' . (env('APP_KEY') ?: 'base64:' . base64_encode(random_bytes(32))),
                'HASH_ALGORITHM=sha256',
                'HASH_SALT=laravel-data-encryption-' . uniqid(),
                '# Meilisearch Configuration',
                'MEILISEARCH_HOST=http://localhost:7700',
                'MEILISEARCH_KEY=',
                'MEILISEARCH_INDEX_PREFIX=encrypted_',
            ];
            
            $added = [];
            foreach ($variables as $variable) {
                if (str_starts_with($variable, '#')) {
                    if (!str_contains($envContent, $variable)) {
                        File::append($envPath, PHP_EOL . $variable);
                    }
                } else {
                    $key = explode('=', $variable)[0];
                    if (!str_contains($envContent, $key)) {
                        File::append($envPath, PHP_EOL . $variable);
                        $added[] = $key;
                    }
                }
            }
            
            if (!empty($added)) {
                $this->info('✅ Added environment variables: ' . implode(', ', $added));
            }
        }
    }
    
    protected function generateEncryptionKey()
    {
        if (empty(env('ENCRYPTION_KEY')) && !empty(env('APP_KEY'))) {
            $this->info('✅ Using APP_KEY as encryption key');
        } elseif (empty(env('ENCRYPTION_KEY'))) {
            $key = 'base64:' . base64_encode(random_bytes(32));
            $this->warn('⚠️  ENCRYPTION_KEY was not set. Generated new key.');
            $this->line('Add this to your .env file:');
            $this->line("ENCRYPTION_KEY={$key}");
        } else {
            $this->info('✅ Encryption key already configured');
        }
    }
    
    protected function setupMeilisearch()
    {
        $this->info('📊 Setting up Meilisearch...');
        
        // Check if Meilisearch is running
        $host = env('MEILISEARCH_HOST', 'http://localhost:7700');
        
        $this->line("Meilisearch host: {$host}");
        $this->line('📖 Documentation: https://www.meilisearch.com/docs');
        
        // Test connection (optional)
        try {
            $client = new \Meilisearch\Client($host);
            $health = $client->health();
            $this->info('✅ Meilisearch connection successful');
        } catch (\Exception $e) {
            $this->warn('⚠️  Cannot connect to Meilisearch. Please ensure it\'s running.');
        }
    }
    
    protected function showNextSteps()
    {
        $this->newLine();
        $this->info('📝 Installation Steps Remaining:');
        
        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
            
            if ($this->confirm('Add HasEncryptedFields trait to User model automatically?', true)) {
                $this->setupUserModel();
                
                if ($this->confirm('Encrypt existing User data now?', false)) {
                    $backup = $this->option('backup') ? true : false;
                    
                    $this->call('data-encryption:encrypt', [
                        'model' => 'App\Models\User',
                        '--backup' => $backup,
                        '--chunk' => 1000,
                    ]);
                    
                    $this->info('✅ All steps completed!');
                    return;
                }
            }
        }
        
        $this->newLine();
        $this->info('Manual steps if skipped:');
        $this->line('1. Run migrations: php artisan migrate');
        $this->line('2. Add trait to User.php:');
        $this->line('   use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;');
        $this->line('   protected static $encryptedFields = [\'email\', \'phone\'];');
        $this->line('   protected static $searchableHashFields = [\'email\', \'phone\'];');
        $this->line('3. Encrypt data: php artisan data-encryption:encrypt "App\Models\User" --backup');
        $this->newLine();
        
        $this->info('💡 For automatic setup, run: php artisan data-encryption:install --auto');
    }
}