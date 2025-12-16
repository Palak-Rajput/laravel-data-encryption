<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use PalakRajput\DataEncryption\Services\MeilisearchService;

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
    

protected function autoSetupModels($skipConfirm = false)
{
    $this->info('🤖 Auto-configuring models...');

    if (class_exists('App\Models\User')) {
        $this->setupUserModel();

        if ($this->option('auto') || ($skipConfirm && $this->confirm('Encrypt existing User data now?', true))) {
            $this->info('🔐 Encrypting User data...');
            $backup = $this->option('backup') ? true : false;

            $this->call('data-encryption:encrypt', [
                '--model' => 'App\Models\User',
                '--backup' => $backup,
                '--chunk' => 1000,
                '--force' => $this->option('auto'),
            ]);
            
            // Reindex to Meilisearch for partial search
            $this->info('🔍 Indexing to Meilisearch for partial search...');
            $this->call('data-encryption:reindex', [
                '--model' => 'App\Models\User',
            ]);

            // 🔧 Configure Meilisearch for partial search (ADD THIS SECTION)
            $this->info('🔧 Configuring Meilisearch for partial search...');
            
            // Add the necessary imports at the top of the file
            // use PalakRajput\DataEncryption\Services\MeilisearchService;
            // use App\Models\User;
            
            $meilisearch = app(MeilisearchService::class);
            $model = new User();
            $indexName = $model->getMeilisearchIndexName();

            // Force reinitialize index with proper settings
            if ($meilisearch->initializeIndex($indexName)) {
                $this->info("✅ Meilisearch index '{$indexName}' configured!");
                
                // Wait a bit for settings to apply
                sleep(2);
                
                // Test search with sample data
                $this->info("🧪 Testing search functionality...");
                $testUsers = User::take(3)->get();
                foreach ($testUsers as $user) {
                    $user->indexToMeilisearch();
                }
                
                $this->info("✅ Test data indexed. Partial search should now work!");
            } else {
                $this->error("❌ Failed to configure Meilisearch index");
            }

            $this->info('✅ Setup complete! Partial search is now enabled.');
            $this->info('   Try searching for: gmail, user, @example.com, etc.');
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

    // Add trait import if missing
    if (!str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;')) {
        $content = preg_replace(
            '/^(namespace App\\\\Models;)/m',
            "$1\n\nuse PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;",
            $content
        );
    }

    // Add trait inside class if missing
    if (!preg_match('/use\s+HasEncryptedFields\s*;/', $content)) {
        $content = preg_replace(
            '/(class User extends [^{]+\{)/',
            "$1\n    use HasEncryptedFields;",
            $content
        );
    }

    // Add encrypted fields properties if missing
    if (!str_contains($content, 'protected static $encryptedFields') &&
        !str_contains($content, 'protected static $searchableHashFields')) {

        $content = preg_replace(
            '/(class User extends [^{]+\{)/',
            "$1\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];",
            $content,
            1
        );
    }

    File::put($userModelPath, $content);
    $this->info('✅ Updated User model with HasEncryptedFields trait and properties');
}


protected function addPropertiesToUserModel(&$content, $filePath)
{
    // Only add if properties are missing
    if (str_contains($content, 'protected static $encryptedFields') &&
        str_contains($content, 'protected static $searchableHashFields')) {
        return;
    }

    // Insert properties **inside the class**, after "use HasEncryptedFields;"
    $content = preg_replace_callback(
        '/(class\s+\w+\s+extends\s+[^{]+\{)/',
        function ($matches) {
            return $matches[1] . "\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];";
        },
        $content,
        1
    );

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