<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use ReflectionClass;

class InstallEncryptionCommand extends Command
{
    protected $signature = 'data-encryption:install 
                            {--auto : Run all commands automatically (migrate + encrypt)}
                            {--yes : Skip all confirmation prompts (use with --auto)}
                            {--models= : Comma-separated list of models to encrypt}
                            {--fields= : Comma-separated list of fields to encrypt}
                            {--searchable= : Comma-separated list of fields for searchable hashes}
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
        
        // Step 2: Get models to process
        $modelsInput = $this->option('models');
        if ($modelsInput) {
            $models = array_map('trim', explode(',', $modelsInput));
        } else {
            $models = $this->getDefaultModels();
        }
        
        // Get fields to encrypt
        $fieldsInput = $this->option('fields');
        $fields = $fieldsInput ? array_map('trim', explode(',', $fieldsInput)) : ['email', 'phone'];
        
        // Get searchable fields
        $searchableInput = $this->option('searchable');
        $searchableFields = $searchableInput ? array_map('trim', explode(',', $searchableInput)) : $fields;
        
        // Step 3: Create and publish migrations for each model
        $this->info('📊 Creating migrations...');
        foreach ($models as $modelClass) {
            $this->createMigrationForModel($modelClass, $fields);
        }
        
        // Step 4: Add environment variables
        $this->info('🔧 Setting up environment...');
        $this->addEnvironmentVariables();
        
        // Step 5: Generate encryption key
        $this->generateEncryptionKey();
        
        // Step 6: Setup Meilisearch (optional)
        if ($auto || $skipConfirm || $this->confirm('Setup Meilisearch for encrypted data search?', false)) {
            $this->setupMeilisearch();
        }
        
        // Step 7: Run migrations automatically if --auto flag
        if ($auto || $skipConfirm) {
            $this->info('🚀 Running migrations...');
            $this->call('migrate');
            
            // Step 8: Configure models and encrypt
            foreach ($models as $modelClass) {
                $this->setupAndEncryptModel($modelClass, $fields, $searchableFields, $skipConfirm);
            }
            
            $this->info('✅ Installation COMPLETE! All steps done automatically.');
        } else {
            $this->showNextSteps($models, $fields);
        }
    }
    
    /**
     * Get default models to encrypt
     */
    protected function getDefaultModels(): array
    {
        $defaultModels = [];
        
        // Check for User model
        if (class_exists('App\Models\User')) {
            $defaultModels[] = 'App\Models\User';
        }
        
        // Check for other common models
        $commonModels = [
            'App\Models\Customer',
            'App\Models\Employee',
            'App\Models\Client',
            'App\Models\Patient',
            'App\Models\Member',
        ];
        
        foreach ($commonModels as $model) {
            if (class_exists($model)) {
                $defaultModels[] = $model;
            }
        }
        
        // If no models found, ask user
        if (empty($defaultModels)) {
            $modelInput = $this->ask('Enter model classes to encrypt (comma-separated, e.g., App\Models\User,App\Models\Customer):', 'App\Models\User');
            $defaultModels = array_map('trim', explode(',', $modelInput));
        }
        
        return $defaultModels;
    }
    
    /**
     * Create migration for specific model
     */
    protected function createMigrationForModel($modelClass, $fields = ['email', 'phone'])
    {
        if (!class_exists($modelClass)) {
            $this->warn("⚠️  Model {$modelClass} not found! Skipping migration creation.");
            return;
        }
        
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            
            $timestamp = date('Y_m_d_His');
            $migrationName = "add_hash_columns_to_{$table}_table";
            $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
            
            // Check if migration already exists
            if (File::exists($migrationFile)) {
                $this->info("✅ Migration for {$modelClass} already exists");
                return;
            }
            
            $fieldsStr = var_export($fields, true);
            
            $content = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table(\'' . $table . '\', function (Blueprint $table) {
            // Add hash columns for encrypted fields
            $columns = ' . $fieldsStr . ';
            
            foreach ($columns as $column) {
                if (Schema::hasColumn(\'' . $table . '\', $column)) {
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
        Schema::table(\'' . $table . '\', function (Blueprint $table) {
            $columns = ' . $fieldsStr . ';
            
            foreach ($columns as $column) {
                if (Schema::hasColumn(\'' . $table . '\', $column . \'_hash\')) {
                    $table->dropColumn($column . \'_hash\');
                }
                
                if (Schema::hasColumn(\'' . $table . '\', $column . \'_backup\')) {
                    $table->dropColumn($column . \'_backup\');
                }
            }
        });
    }
};';
            
            File::put($migrationFile, $content);
            $this->info("✅ Created migration for {$modelClass} (table: {$table})");
        } catch (\Exception $e) {
            $this->error("Failed to create migration for {$modelClass}: " . $e->getMessage());
        }
    }
    
    /**
     * Setup and encrypt a model
     */
    protected function setupAndEncryptModel($modelClass, $fields, $searchableFields, $skipConfirm = false)
    {
        $this->info("\n🤖 Configuring {$modelClass}...");
        
        if (!class_exists($modelClass)) {
            $this->warn("⚠️  Model {$modelClass} not found. Skipping.");
            return;
        }
        
        // Auto-add HasEncryptedFields trait
        $encryptCommand = new EncryptDataCommand();
        $encryptCommand->setLaravel($this->laravel);
        
        // Add trait to model
        $modelUpdated = $encryptCommand->addHasEncryptedFieldsTrait($modelClass);
        
        if (!$modelUpdated) {
            $this->error("Failed to add HasEncryptedFields trait to {$modelClass}");
            return;
        }
        
        // Update model with fields configuration
        $encryptCommand->updateModelWithFields($modelClass, $fields, $searchableFields);
        
        // Check if we should encrypt data
        if ($skipConfirm || $this->confirm("Encrypt existing data for {$modelClass} now?", true)) {
            $backup = $this->option('backup') ? true : false;
            
            $this->info("🔐 Encrypting data for {$modelClass}...");
            
            // Use the encrypt command to handle encryption
            $this->call('data-encryption:encrypt', [
                'model' => $modelClass,
                '--backup' => $backup,
                '--fields' => implode(',', $fields),
                '--searchable' => implode(',', $searchableFields),
                '--chunk' => 1000,
                '--force' => true,
            ]);
        }
    }
    
    protected function addEnvironmentVariables()
    {
        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            $this->warn('⚠️  .env file not found');
            return;
        }
        
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
                if (!str_contains($envContent, $key . '=')) {
                    File::append($envPath, PHP_EOL . $variable);
                    $added[] = $key;
                }
            }
        }
        
        if (!empty($added)) {
            $this->info('✅ Added environment variables: ' . implode(', ', $added));
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
    
    protected function showNextSteps($models, $fields)
    {
        $this->newLine();
        $this->info('📝 Installation Steps Remaining:');
        
        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
            
            foreach ($models as $modelClass) {
                if ($this->confirm("Add HasEncryptedFields trait to {$modelClass} automatically?", true)) {
                    $this->setupAndEncryptModel($modelClass, $fields, $fields, false);
                }
            }
            
            $this->info('✅ All steps completed!');
            return;
        }
        
        $this->newLine();
        $this->info('Manual steps if skipped:');
        $this->line('1. Run migrations: php artisan migrate');
        
        foreach ($models as $modelClass) {
            $this->line("\nFor {$modelClass}:");
            $this->line('   Add to your model:');
            $this->line('   use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;');
            $this->line('   protected static $encryptedFields = ' . var_export($fields, true) . ';');
            $this->line('   protected static $searchableHashFields = ' . var_export($fields, true) . ';');
            $this->line('   Encrypt data: php artisan data-encryption:encrypt "' . $modelClass . '" --backup');
        }
        
        $this->newLine();
        $this->info('💡 For automatic setup, run: php artisan data-encryption:install --auto');
    }
}