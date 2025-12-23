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
                            {--backup : Include backup columns in migration}
                            {--all-models : Detect and setup all models with email/phone fields}';
    
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
        
        // Step 2: Create dynamic migrations for models
        $this->info('📊 Creating migrations for models...');
        $this->createDynamicMigrations();
        
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
     * Create dynamic migrations based on models
     */
    protected function createDynamicMigrations()
    {
        // Get models from option or detect automatically
        $modelsInput = $this->option('models');
        
        if ($modelsInput) {
            $models = array_map('trim', explode(',', $modelsInput));
        } elseif ($this->option('all-models')) {
            $models = $this->detectModelsWithSensitiveFields();
        } else {
            $models = ['App\Models\User']; // Default
        }
        
        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                $this->warn("⚠️  Model {$modelClass} not found, skipping...");
                continue;
            }
            
            $model = new $modelClass;
            $table = $model->getTable();
            
            // Get fields to encrypt (from option, model property, or detect)
            $fieldsInput = $this->option('fields');
            
            if ($fieldsInput) {
                $fields = array_map('trim', explode(',', $fieldsInput));
            } else {
                // Try to get from model property
                try {
                    $reflection = new \ReflectionClass($modelClass);
                    $fields = $reflection->getStaticPropertyValue('encryptedFields');
                } catch (\ReflectionException $e) {
                    // Detect common sensitive fields
                    $fields = $this->detectSensitiveFields($table);
                }
            }
            
            if (empty($fields)) {
                $this->warn("⚠️  No sensitive fields detected for {$modelClass}, skipping...");
                continue;
            }
            
            $this->createMigrationForModel($table, $fields);
        }
    }
    
    /**
     * Detect models in the app that might have sensitive fields
     */
    protected function detectModelsWithSensitiveFields()
    {
        $models = [];
        $modelsPath = app_path('Models');
        
        if (!File::exists($modelsPath)) {
            return $models;
        }
        
        $files = File::allFiles($modelsPath);
        
        foreach ($files as $file) {
            $className = 'App\\Models\\' . $file->getBasename('.php');
            
            if (class_exists($className)) {
                $model = new $className;
                $table = $model->getTable();
                
                // Check if table has sensitive fields
                if (Schema::hasTable($table)) {
                    $columns = Schema::getColumnListing($table);
                    
                    $sensitiveColumns = array_intersect($columns, ['email', 'phone', 'ssn', 'credit_card', 'password']);
                    
                    if (!empty($sensitiveColumns)) {
                        $models[] = $className;
                    }
                }
            }
        }
        
        return $models;
    }
    
    /**
     * Detect sensitive fields in a table
     */
    protected function detectSensitiveFields($table)
    {
        if (!Schema::hasTable($table)) {
            return [];
        }
        
        $columns = Schema::getColumnListing($table);
        
        // Common sensitive field names
        $sensitivePatterns = [
            'email', 'phone', 'mobile', 'telephone',
            'ssn', 'social_security',
            'credit_card', 'card_number',
            'address', 'street', 'city', 'zip',
            'dob', 'birth_date',
            'passport', 'license'
        ];
        
        $detectedFields = [];
        
        foreach ($columns as $column) {
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($column, $pattern) !== false) {
                    $detectedFields[] = $column;
                    break;
                }
            }
        }
        
        return array_unique($detectedFields);
    }
    
    /**
     * Create migration for specific model
     */
    protected function createMigrationForModel($table, $fields)
    {
        $timestamp = date('Y_m_d_His', time() + rand(1, 10));
        $migrationName = "add_hash_columns_to_{$table}_table";
        $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
        
        if (File::exists($migrationFile)) {
            return; // Migration already exists
        }
        
        $fieldsCode = '';
        $dropCode = '';
        
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            $fieldsCode .= "
            if (Schema::hasColumn('{$table}', '{$field}') && !Schema::hasColumn('{$table}', '{$hashColumn}')) {
                \$table->string('{$hashColumn}', 64)->nullable()->index()->after('{$field}');
            }";
            
            $dropCode .= "
            if (Schema::hasColumn('{$table}', '{$hashColumn}')) {
                \$table->dropColumn('{$hashColumn}');
            }";
        }
        
        $content = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table(\'' . $table . '\', function (Blueprint $table) {' . $fieldsCode . '
        });
    }

    public function down()
    {
        Schema::table(\'' . $table . '\', function (Blueprint $table) {' . $dropCode . '
        });
    }
};';

        File::put($migrationFile, $content);
        $this->info("✅ Created migration for {$table} table");
    }
    
    protected function autoSetupModels($skipConfirm = false)
    {
        $this->info('🤖 Auto-configuring models...');
        
        // Get models to setup
        $models = [];
        
        if ($this->option('models')) {
            $models = array_map('trim', explode(',', $this->option('models')));
        } elseif ($this->option('all-models')) {
            $models = $this->detectModelsWithSensitiveFields();
        } else {
            $models = ['App\Models\User']; // Default
        }
        
        foreach ($models as $modelClass) {
            $this->setupModel($modelClass, $skipConfirm);
        }
    }
    
    protected function setupModel($modelClass, $skipConfirm = false)
    {
        if (!class_exists($modelClass)) {
            $this->warn("⚠️  Model {$modelClass} not found, skipping...");
            return;
        }
        
        $this->info("\n📝 Setting up {$modelClass}...");
        
        // 1. Ensure trait & properties exist
        $this->setupModelEncryption($modelClass);
        
        // 2. Encrypt data if confirmed
        if ($skipConfirm || $this->confirm("Encrypt existing {$modelClass} data now?", true)) {
            $backup = $this->option('backup') ? true : false;
            
            // Initialize Meilisearch index
            if (config('data-encryption.meilisearch.enabled', true)) {
                $this->info("🔧 Initializing Meilisearch index...");
                
                $meilisearch = app(\PalakRajput\DataEncryption\Services\MeilisearchService::class);
                $model = new $modelClass;
                $indexName = $model->getMeilisearchIndexName();
                
                if ($meilisearch->initializeIndex($indexName)) {
                    $this->info("✅ Meilisearch index '{$indexName}' initialized");
                } else {
                    $this->error("❌ Failed to initialize Meilisearch index: {$indexName}");
                }
            }
            
            // Encrypt data
            $this->info("🔐 Encrypting {$modelClass} data...");
            
            $this->call('data-encryption:encrypt', [
                'model' => $modelClass,
                '--backup' => $backup,
                '--chunk' => 1000,
                '--force' => true,
                '--add-columns' => true,
                '--add-trait' => true,
            ]);
            
            // Reindex to Meilisearch
            if (config('data-encryption.meilisearch.enabled', true)) {
                $this->info("🔍 Reindexing encrypted data to Meilisearch...");
                
                $this->call('data-encryption:reindex', [
                    '--model' => $modelClass,
                    '--force' => true,
                ]);
            }
        }
    }
    
    protected function setupModelEncryption($modelClass)
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!File::exists($modelPath)) {
            $this->warn("⚠️  Model file not found at: {$modelPath}");
            return;
        }
        
        $content = File::get($modelPath);
        
        // Add trait import if missing
        if (!str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;')) {
            $content = preg_replace(
                '/^(namespace [^;]+;)/m',
                "$1\n\nuse PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;",
                $content
            );
        }
        
        // Add trait inside class if missing
        if (!preg_match('/use\s+HasEncryptedFields\s*;/', $content)) {
            $content = preg_replace(
                '/(class\s+\w+\s+extends[^{]+\{)/',
                "$1\n    use HasEncryptedFields;",
                $content
            );
        }
        
        // Get table fields to determine what to encrypt
        $model = new $modelClass;
        $table = $model->getTable();
        $sensitiveFields = $this->detectSensitiveFields($table);
        
        // Add encrypted fields properties if missing
        if (!str_contains($content, 'protected static $encryptedFields')) {
            $fieldsArray = $this->formatArray($sensitiveFields);
            
            $content = preg_replace(
                '/(class\s+\w+\s+extends[^{]+\{)/',
                "$1\n    protected static \$encryptedFields = {$fieldsArray};\n    protected static \$searchableHashFields = {$fieldsArray};",
                $content,
                1
            );
        }
        
        File::put($modelPath, $content);
        $this->info("✅ Updated {$modelClass} with HasEncryptedFields trait and properties");
    }
    
    protected function getModelPath($modelClass)
    {
        // Convert namespace to file path
        $relativePath = str_replace('App\\', '', $modelClass);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        return app_path($relativePath . '.php');
    }
    
    protected function formatArray($fields)
    {
        if (empty($fields)) {
            return "[]";
        }
        
        $quoted = array_map(function($field) {
            return "'" . addslashes($field) . "'";
        }, $fields);
        
        return "[" . implode(', ', $quoted) . "]";
    }
    
    // ... [rest of the existing methods remain the same] ...
    
    protected function showNextSteps()
    {
        $this->newLine();
        $this->info('📝 Installation Steps Remaining:');
        
        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
            
            if ($this->confirm('Configure models automatically?', true)) {
                $models = $this->option('models') ? 
                    array_map('trim', explode(',', $this->option('models'))) : 
                    ['App\Models\User'];
                
                foreach ($models as $modelClass) {
                    $this->setupModelEncryption($modelClass);
                    
                    if ($this->confirm("Encrypt existing {$modelClass} data now?", false)) {
                        $backup = $this->option('backup') ? true : false;
                        
                        $this->call('data-encryption:encrypt', [
                            'model' => $modelClass,
                            '--backup' => $backup,
                            '--chunk' => 1000,
                            '--add-columns' => true,
                            '--add-trait' => true,
                        ]);
                    }
                }
                
                $this->info('✅ All steps completed!');
                return;
            }
        }
        
        $this->newLine();
        $this->info('Manual steps if skipped:');
        $this->line('1. Run migrations: php artisan migrate');
        $this->line('2. Add trait to your models:');
        $this->line('   use PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields;');
        $this->line('   protected static $encryptedFields = [\'email\', \'phone\'];');
        $this->line('   protected static $searchableHashFields = [\'email\', \'phone\'];');
        $this->line('3. Encrypt data: php artisan data-encryption:encrypt "App\Models\User" --backup --add-columns --add-trait');
        $this->newLine();
        
        $this->info('💡 For automatic setup, run: php artisan data-encryption:install --auto --all-models');
    }
}