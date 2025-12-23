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
        
        // Step 2: Create and publish migrations
        $this->info('📊 Publishing migrations...');
        $this->createAndPublishMigrations();
        
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
    
    /**
     * Create migration file if it doesn't exist and publish it
     */
    protected function createAndPublishMigrations()
    {
        // First, ensure the migration exists in the package
        $this->createMigrationIfMissing();
        
        // Now publish it
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'migrations',
            '--force' => true
        ]);
        
        $this->info('✅ Migration published successfully');
    }
    
    /**
     * Create migration file in vendor directory if it doesn't exist
     */
    protected function createMigrationIfMissing()
    {
        $vendorDir = base_path('vendor/palak-rajput/laravel-data-encryption');
        
        // Check if package is installed via composer
        if (!File::exists($vendorDir)) {
            // Try to find it in a different location
            $vendorDir = base_path('vendor/palak-rajput/laravel-data-encryption');
            
            if (!File::exists($vendorDir)) {
                $this->warn('⚠️  Package not found in vendor directory. Using direct migration creation.');
                $this->createMigrationDirectly();
                return;
            }
        }
        
        // Create database/migrations directory if it doesn't exist
        $migrationsDir = $vendorDir . '/database/migrations';
        if (!File::exists($migrationsDir)) {
            File::makeDirectory($migrationsDir, 0755, true);
        }
        
        $migrationFile = $migrationsDir . '/add_hash_columns_to_users_table.php';
        
        if (!File::exists($migrationFile)) {
            $this->createMigrationFile($migrationFile);
            $this->info('✅ Created missing migration file in package');
        }
    }
    
    /**
     * Create migration file directly in the project if package not found
     */
    protected function createMigrationDirectly()
    {
        $timestamp = date('Y_m_d_His');
        $migrationFile = database_path("migrations/{$timestamp}_add_hash_columns_to_users_table.php");
        
        if (!File::exists($migrationFile)) {
            $this->createMigrationFile($migrationFile);
            $this->info('✅ Created migration file directly in project');
        }
    }
    
    /**
     * Create the migration file content
     */
    protected function createMigrationFile($filePath)
    {
        $content = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table(\'users\', function (Blueprint $table) {
            // Add hash columns for encrypted fields
            $columns = [\'email\', \'phone\'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn(\'users\', $column)) {
                    // Add hash column for searching
                    $table->string($column . \'_hash\', 64)
                           ->nullable()
                           ->index()
                           ->after($column);
                    
                    // Add backup column if requested
                    if (config(\'data-encryption.migration.backup_columns\', false)) {
                        $table->string($column . \'_backup\', 255)
                               ->nullable()
                               ->after($column . \'_hash\');
                    }
                }
            }
        });
    }

    public function down()
    {
        Schema::table(\'users\', function (Blueprint $table) {
            $columns = [\'email\', \'phone\'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn(\'users\', $column . \'_hash\')) {
                    $table->dropColumn($column . \'_hash\');
                }
                
                if (Schema::hasColumn(\'users\', $column . \'_backup\')) {
                    $table->dropColumn($column . \'_backup\');
                }
            }
        });
    }
};';
        
        File::put($filePath, $content);
    }
    
    protected function autoSetupModels($skipConfirm = false)
    {
        $this->info('🤖 Auto-configuring models...');

        if (!class_exists('App\Models\User')) {
            $this->warn('⚠️  User model not found. You need to add HasEncryptedFields trait manually.');
            return;
        }

        // 1️⃣ Ensure trait & properties exist
        $this->setupUserModel();

        if (!($this->option('auto') || ($skipConfirm && $this->confirm('Encrypt existing User data now?', true)))) {
            return;
        }

        $backup = $this->option('backup') ? true : false;

        /*
        |--------------------------------------------------------------------------
        | STEP 1: Initialize Meilisearch index FIRST
        |--------------------------------------------------------------------------
        */
        $this->info('🔧 Initializing Meilisearch index...');

        $meilisearch = app(\PalakRajput\DataEncryption\Services\MeilisearchService::class);
        $model       = new \App\Models\User();
        $indexName   = $model->getMeilisearchIndexName();

        if (!$meilisearch->initializeIndex($indexName)) {
            $this->error("❌ Failed to initialize Meilisearch index: {$indexName}");
            return;
        }

        $this->info("✅ Meilisearch index '{$indexName}' initialized");

        /*
        |--------------------------------------------------------------------------
        | STEP 2: Encrypt existing data
        |--------------------------------------------------------------------------
        */
        $this->info('🔐 Encrypting User data...');

        $this->call('data-encryption:encrypt', [
            '--model'  => 'App\Models\User',
            '--backup' => $backup,
            '--chunk'  => 1000,
            '--force'  => true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | STEP 3: Reindex encrypted data
        |--------------------------------------------------------------------------
        */
        $this->info('🔍 Reindexing encrypted data to Meilisearch...');

        $this->call('data-encryption:reindex', [
            '--model' => 'App\Models\User',
            '--force' => true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | DONE
        |--------------------------------------------------------------------------
        */
        $this->info('✅ Setup complete! Partial search is now enabled.');
        $this->info('💡 Try searching for: gmail, user, @example.com');
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

        $host = env('MEILISEARCH_HOST', 'http://127.0.0.1:7700');
        $this->line("Meilisearch host: {$host}");

        // Package-specific data directory (VERY IMPORTANT)
        $dataDir = storage_path('data-encryption/meilisearch');

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        // 1️⃣ Check if already running
        try {
            $client = new \Meilisearch\Client($host);
            $client->health();
            $this->info('✅ Meilisearch is already running');
            return;
        } catch (\Throwable $e) {
            $this->warn('⚠️  Meilisearch not running. Installing...');
        }

        // 2️⃣ Detect OS
        $os = PHP_OS_FAMILY;

        $binaries = [
            'Windows' => [
                'url' => 'https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-windows-amd64.exe',
                'file' => 'meilisearch.exe',
            ],
            'Linux' => [
                'url' => 'https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-linux-amd64',
                'file' => 'meilisearch',
            ],
            'Darwin' => [
                'url' => 'https://github.com/meilisearch/meilisearch/releases/latest/download/meilisearch-macos-amd64',
                'file' => 'meilisearch',
            ],
        ];

        if (!isset($binaries[$os])) {
            $this->error("❌ Unsupported OS: {$os}");
            return;
        }

        $binaryPath = base_path($binaries[$os]['file']);

        // 3️⃣ Download binary if missing
        if (!file_exists($binaryPath)) {
            $this->info('⬇️ Downloading Meilisearch binary...');
            file_put_contents($binaryPath, fopen($binaries[$os]['url'], 'r'));

            if ($os !== 'Windows') {
                chmod($binaryPath, 0755);
            }

            $this->info('✅ Meilisearch downloaded');
        } else {
            $this->info('ℹ️ Meilisearch binary already exists');
        }

        // 4️⃣ Start Meilisearch WITH CUSTOM DATA DIR
        $this->info('🚀 Starting Meilisearch server...');

        $command = "\"{$binaryPath}\" --db-path=\"{$dataDir}\"";

        if ($os === 'Windows') {
            pclose(popen("start /B \"Meilisearch\" {$command}", 'r'));
        } else {
            exec($command . ' > /dev/null 2>&1 &');
        }

        // 5️⃣ Wait & verify
        sleep(3);

        try {
            $client = new \Meilisearch\Client($host);
            $client->health();
            $this->info('✅ Meilisearch started successfully');
            $this->line("📂 Data directory: {$dataDir}");
        } catch (\Throwable $e) {
            $this->error('❌ Failed to start Meilisearch');
            $this->warn('👉 If this persists, delete: ' . $dataDir);
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