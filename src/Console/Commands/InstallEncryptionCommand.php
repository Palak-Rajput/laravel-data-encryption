<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use Illuminate\Support\Str;

class InstallEncryptionCommand extends Command
{
    protected $signature = 'data-encryption:install 
                            {--auto : Run all commands automatically (migrate + encrypt)}
                            {--yes : Skip all confirmation prompts (use with --auto)}
                            {--models= : Comma-separated list of models to encrypt}
                            {--fields= : Comma-separated list of fields to encrypt}
                            {--backup : Include backup columns in migration}
                            {--table= : Specific table to encrypt}
                            {--all-tables : Encrypt all tables with common fields}';
    
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
        
        // Step 2: Create and publish migrations for selected tables
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
            
            // Step 7: Ask which tables to encrypt
            $this->setupEncryptionForTables($skipConfirm);
            
            $this->info('✅ Installation COMPLETE! All steps done automatically.');
        } else {
            $this->showNextSteps();
        }
    }
    
    /**
     * Create migration file for selected tables
     */
    protected function createAndPublishMigrations()
    {
        // Get tables to encrypt
        $tablesToEncrypt = $this->getTablesToEncrypt();
        
        if (empty($tablesToEncrypt)) {
            $this->info('ℹ️  No tables selected for encryption. You can add encryption later.');
            return;
        }
        
        foreach ($tablesToEncrypt as $tableName => $fields) {
            $this->createMigrationForTable($tableName, $fields);
        }
        
        // Now publish all migrations
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'migrations',
            '--force' => true
        ]);
        
        $this->info('✅ Migrations created for selected tables');
    }
    
    /**
     * Get tables to encrypt from user input
     */
    protected function getTablesToEncrypt()
    {
        $tables = [];
        
        // Check if specific table is provided
        $specificTable = $this->option('table');
        if ($specificTable) {
            $this->info("📊 Table specified via option: {$specificTable}");
            $fields = $this->getFieldsForTable($specificTable);
            if (!empty($fields)) {
                $tables[$specificTable] = $fields;
            }
            return $tables;
        }
        
        // Check if all tables option is set
        if ($this->option('all-tables')) {
            return $this->getAllTablesWithCommonFields();
        }
        
        // Interactive mode: Ask user which tables to encrypt
        if ($this->confirm('Do you want to encrypt specific tables?', true)) {
            $allTables = $this->getAllTables();
            
            if (empty($allTables)) {
                $this->warn('⚠️  No tables found in the database.');
                return $tables;
            }
            
            $this->info('📊 Available tables in database:');
            foreach ($allTables as $index => $table) {
                $this->line("  " . ($index + 1) . ". {$table}");
            }
            
            while (true) {
                $tableName = $this->ask('Enter table name to encrypt (or press Enter to finish)');
                
                if (empty($tableName)) {
                    break;
                }
                
                if (!in_array($tableName, $allTables)) {
                    $this->error("❌ Table '{$tableName}' not found in database.");
                    if ($this->confirm('Show available tables again?')) {
                        $this->info('📊 Available tables: ' . implode(', ', $allTables));
                    }
                    continue;
                }
                
                $fields = $this->getFieldsForTable($tableName);
                if (empty($fields)) {
                    $this->warn("⚠️  No encryptable fields found in table '{$tableName}'");
                    continue;
                }
                
                $this->info("📝 Fields in '{$tableName}': " . implode(', ', $fields));
                
                if ($this->confirm("Encrypt all fields in '{$tableName}'?", true)) {
                    $selectedFields = $fields;
                } else {
                    $selectedFields = $this->selectFields($tableName, $fields);
                }
                
                if (!empty($selectedFields)) {
                    $tables[$tableName] = $selectedFields;
                    $this->info("✅ Added '{$tableName}' with fields: " . implode(', ', $selectedFields));
                }
            }
        }
        
        return $tables;
    }
    
    /**
     * Get all tables from database
     */
    protected function getAllTables()
    {
        try {
            $connection = DB::connection();
            $databaseName = $connection->getDatabaseName();
            
            // Different SQL for different database drivers
            $driver = $connection->getDriverName();
            
            switch ($driver) {
                case 'mysql':
                    $tables = DB::select('SHOW TABLES');
                    $tableField = 'Tables_in_' . $databaseName;
                    return array_map(function($table) use ($tableField) {
                        return $table->$tableField;
                    }, $tables);
                    
                case 'pgsql':
                    $tables = DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
                    return array_map(function($table) {
                        return $table->tablename;
                    }, $tables);
                    
                case 'sqlite':
                    $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                    return array_map(function($table) {
                        return $table->name;
                    }, $tables);
                    
                case 'sqlsrv':
                    $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
                    return array_map(function($table) {
                        return $table->TABLE_NAME;
                    }, $tables);
                    
                default:
                    $this->warn("⚠️  Unsupported database driver: {$driver}");
                    return [];
            }
        } catch (\Exception $e) {
            $this->error("❌ Error fetching tables: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get encryptable fields for a table
     */
    protected function getFieldsForTable($tableName)
    {
        try {
            $columns = Schema::getColumnListing($tableName);
            
            // Filter for columns that might contain sensitive data
            $sensitiveFieldPatterns = [
                'email', 'phone', 'ssn', 'password', 'token', 'secret',
                'address', 'birth', 'credit', 'card', 'pin', 'passport',
                'tax', 'national_id', 'driver_license', 'medical',
                'bank', 'account', 'salary', 'income', 'social_security'
            ];
            
            $encryptableFields = [];
            foreach ($columns as $column) {
                $columnLower = strtolower($column);
                
                // Skip certain columns
                if (in_array($columnLower, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'])) {
                    continue;
                }
                
                // Check if column contains sensitive data
                foreach ($sensitiveFieldPatterns as $pattern) {
                    if (str_contains($columnLower, $pattern)) {
                        // Check if it's a string/text column
                        $columnType = Schema::getColumnType($tableName, $column);
                        if (in_array($columnType, ['string', 'text', 'varchar', 'char', 'mediumtext', 'longtext'])) {
                            $encryptableFields[] = $column;
                            break;
                        }
                    }
                }
            }
            
            return array_unique($encryptableFields);
        } catch (\Exception $e) {
            $this->error("❌ Error fetching columns for table '{$tableName}': " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Let user select specific fields from a table
     */
    protected function selectFields($tableName, $availableFields)
    {
        $this->info("📝 Select fields to encrypt in '{$tableName}':");
        
        $choices = [];
        foreach ($availableFields as $field) {
            $choices[$field] = $field;
        }
        
        $selected = $this->choice(
            'Choose fields (comma-separated, or "all" for all fields):',
            array_merge(['all'], array_values($choices)),
            0,
            null,
            true
        );
        
        if (in_array('all', $selected)) {
            return $availableFields;
        }
        
        return $selected;
    }
    
    /**
     * Get all tables with common sensitive fields
     */
    protected function getAllTablesWithCommonFields()
    {
        $allTables = $this->getAllTables();
        $tables = [];
        
        $this->info('🔍 Scanning all tables for sensitive fields...');
        
        foreach ($allTables as $table) {
            $fields = $this->getFieldsForTable($table);
            if (!empty($fields)) {
                $tables[$table] = $fields;
                $this->line("  📋 {$table}: " . implode(', ', $fields));
            }
        }
        
        if (empty($tables)) {
            $this->warn('⚠️  No tables with sensitive fields found.');
            return [];
        }
        
        if ($this->confirm('Encrypt all detected tables?', true)) {
            return $tables;
        }
        
        // Let user select from detected tables
        $this->info('📋 Detected tables with sensitive fields:');
        $tableChoices = [];
        foreach ($tables as $table => $fields) {
            $tableChoices[] = $table;
            $this->line("  • {$table} (" . implode(', ', $fields) . ")");
        }
        
        $selectedTables = $this->choice(
            'Select tables to encrypt (comma-separated):',
            $tableChoices,
            null,
            null,
            true
        );
        
        $result = [];
        foreach ($selectedTables as $table) {
            if (isset($tables[$table])) {
                $result[$table] = $tables[$table];
            }
        }
        
        return $result;
    }
    
    /**
     * Create migration for a specific table
     */
    protected function createMigrationForTable($tableName, $fields)
    {
        $vendorDir = base_path('vendor/palak-rajput/laravel-data-encryption');
        
        // Check if package is installed via composer
        if (!File::exists($vendorDir)) {
            $vendorDir = base_path('vendor/palak-rajput/laravel-data-encryption');
            
            if (!File::exists($vendorDir)) {
                // Create migration directly in project
                $this->createMigrationDirectly($tableName, $fields);
                return;
            }
        }
        
        // Create database/migrations directory if it doesn't exist
        $migrationsDir = $vendorDir . '/database/migrations';
        if (!File::exists($migrationsDir)) {
            File::makeDirectory($migrationsDir, 0755, true);
        }
        
        $safeTableName = Str::snake($tableName);
        $migrationFile = $migrationsDir . "/add_hash_columns_to_{$safeTableName}_table.php";
        
        $this->createMigrationFileContent($migrationFile, $tableName, $fields);
        $this->info("✅ Created migration for table '{$tableName}'");
    }
    
    /**
     * Create migration directly in project
     */
    protected function createMigrationDirectly($tableName, $fields)
    {
        $timestamp = date('Y_m_d_His');
        $safeTableName = Str::snake($tableName);
        $migrationFile = database_path("migrations/{$timestamp}_add_hash_columns_to_{$safeTableName}_table.php");
        
        $this->createMigrationFileContent($migrationFile, $tableName, $fields);
        $this->info("✅ Created migration for table '{$tableName}' directly in project");
    }
    
    /**
     * Create migration file content
     */
    protected function createMigrationFileContent($filePath, $tableName, $fields)
    {
        $backupOption = $this->option('backup') ? 'true' : 'false';
        
        $content = '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table(\'' . $tableName . '\', function (Blueprint $table) {
            // Add hash columns for encrypted fields';
        
        foreach ($fields as $field) {
            $content .= '
            if (Schema::hasColumn(\'' . $tableName . '\', \'' . $field . '\')) {
                // Add hash column for searching
                $table->string(\'' . $field . '_hash\', 64)
                       ->nullable()
                       ->index()
                       ->after(\'' . $field . '\');
                
                // Add backup column if requested
                if (' . $backupOption . ' || config(\'data-encryption.migration.backup_columns\', false)) {
                    $table->string(\'' . $field . '_backup\', 255)
                           ->nullable()
                           ->after(\'' . $field . '_hash\');
                }
            }';
        }
        
        $content .= '
        });
    }

    public function down()
    {
        Schema::table(\'' . $tableName . '\', function (Blueprint $table) {';
        
        foreach ($fields as $field) {
            $content .= '
            if (Schema::hasColumn(\'' . $tableName . '\', \'' . $field . '_hash\')) {
                $table->dropColumn(\'' . $field . '_hash\');
            }
            
            if (Schema::hasColumn(\'' . $tableName . '\', \'' . $field . '_backup\')) {
                $table->dropColumn(\'' . $field . '_backup\');
            }';
        }
        
        $content .= '
        });
    }
};';
        
        File::put($filePath, $content);
    }
    
    /**
     * Setup encryption for selected tables
     */
    protected function setupEncryptionForTables($skipConfirm = false)
    {
        // Get the tables that were selected for migration creation
        $tablesToEncrypt = $this->getTablesToEncrypt();
        
        if (empty($tablesToEncrypt)) {
            $this->info('ℹ️  No tables selected for encryption setup.');
            return;
        }
        
        $this->info('🤖 Setting up encryption for selected tables...');
        
        foreach ($tablesToEncrypt as $tableName => $fields) {
            $this->setupTableEncryption($tableName, $fields, $skipConfirm);
        }
    }
    
    /**
     * Setup encryption for a specific table
     */
    protected function setupTableEncryption($tableName, $fields, $skipConfirm = false)
    {
        $this->info("\n📊 Setting up encryption for table: {$tableName}");
        
        // Check if model exists for this table
        $modelClass = $this->getModelClassForTable($tableName);
        
        if ($modelClass && class_exists($modelClass)) {
            $this->info("✅ Model found: {$modelClass}");
            $this->setupModelForEncryption($modelClass, $fields);
            
            // Ask if we should encrypt existing data
            if ($skipConfirm || $this->confirm("Encrypt existing data in '{$tableName}' now?", true)) {
                $this->encryptTableData($tableName, $modelClass, $fields);
            }
        } else {
            $this->warn("⚠️  No model found for table '{$tableName}'. Creating a basic model...");
            
            if ($this->confirm("Create a basic model for '{$tableName}'?", true)) {
                $modelClass = $this->createBasicModel($tableName, $fields);
                $this->setupModelForEncryption($modelClass, $fields);
                
                if ($skipConfirm || $this->confirm("Encrypt existing data in '{$tableName}' now?", true)) {
                    $this->encryptTableData($tableName, $modelClass, $fields);
                }
            } else {
                $this->info("ℹ️  You'll need to manually encrypt data for table '{$tableName}' using the command:");
                $this->line("   php artisan data-encryption:encrypt-table {$tableName} --fields=" . implode(',', $fields));
            }
        }
    }
    
    /**
     * Get model class for table
     */
    protected function getModelClassForTable($tableName)
    {
        // Try common model names
        $modelName = Str::studly(Str::singular($tableName));
        
        // Check in different namespaces
        $possibleClasses = [
            'App\\Models\\' . $modelName,
            'App\\' . $modelName,
            'App\\Models\\' . Str::studly($tableName),
            'App\\' . Str::studly($tableName),
        ];
        
        foreach ($possibleClasses as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
        
        return null;
    }
    
    /**
     * Setup model for encryption by adding trait and properties
     */
    protected function setupModelForEncryption($modelClass, $fields)
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            $modelPath = $reflection->getFileName();
            
            if (!File::exists($modelPath)) {
                $this->error("❌ Model file not found: {$modelPath}");
                return false;
            }
            
            $content = File::get($modelPath);
            
            // Add trait import if missing
            if (!str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;')) {
                $content = preg_replace(
                    '/^(namespace\s+[^;]+;)/m',
                    "$1\n\nuse PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;",
                    $content
                );
            }
            
            // Add trait inside class if missing
            if (!preg_match('/use\s+HasEncryptedFields\s*;/', $content)) {
                $content = preg_replace(
                    '/(class\s+\w+\s+extends\s+[^{]+\{)/',
                    "$1\n    use HasEncryptedFields;",
                    $content
                );
            }
            
            // Add encrypted fields properties
            $fieldsString = "['" . implode("', '", $fields) . "']";
            
            if (!str_contains($content, 'protected static $encryptedFields')) {
                $content = preg_replace(
                    '/(class\s+\w+\s+extends\s+[^{]+\{)/',
                    "$1\n    protected static \$encryptedFields = {$fieldsString};\n    protected static \$searchableHashFields = {$fieldsString};",
                    $content
                );
            }
            
            File::put($modelPath, $content);
            $this->info("✅ Updated {$modelClass} with encryption properties");
            return true;
            
        } catch (\Exception $e) {
            $this->error("❌ Error setting up model {$modelClass}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a basic model for a table
     */
    protected function createBasicModel($tableName, $fields)
    {
        $modelName = Str::studly(Str::singular($tableName));
        $modelPath = app_path("Models/{$modelName}.php");
        
        if (File::exists($modelPath)) {
            $this->info("ℹ️  Model already exists at: {$modelPath}");
            return "App\\Models\\{$modelName}";
        }
        
        $fieldsString = "['" . implode("', '", $fields) . "']";
        
        $content = '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields;

class ' . $modelName . ' extends Model
{
    use HasEncryptedFields;
    
    protected $table = \'' . $tableName . '\';
    
    protected $fillable = [
        // Add fillable fields here
    ];
    
    protected static $encryptedFields = ' . $fieldsString . ';
    protected static $searchableHashFields = ' . $fieldsString . ';
}';
        
        File::put($modelPath, $content);
        $this->info("✅ Created basic model at: {$modelPath}");
        
        return "App\\Models\\{$modelName}";
    }
    
    /**
     * Encrypt data in a table
     */
    protected function encryptTableData($tableName, $modelClass, $fields)
    {
        $backup = $this->option('backup') ? true : false;
        
        $this->info("🔐 Encrypting data in '{$tableName}'...");
        
        try {
            // Initialize Meilisearch index if enabled
            if (config('data-encryption.meilisearch.enabled', true)) {
                $this->info("🔧 Initializing Meilisearch index for '{$tableName}'...");
                $meilisearch = app(MeilisearchService::class);
                $model = new $modelClass();
                $indexName = $model->getMeilisearchIndexName();
                
                if ($meilisearch->initializeIndex($indexName)) {
                    $this->info("✅ Meilisearch index '{$indexName}' initialized");
                }
            }
            
            // Encrypt the data
            $this->call('data-encryption:encrypt', [
                '--model'  => $modelClass,
                '--backup' => $backup,
                '--chunk'  => 1000,
                '--force'  => true,
            ]);
            
            // Reindex to Meilisearch
            if (config('data-encryption.meilisearch.enabled', true)) {
                $this->info("🔍 Reindexing encrypted data to Meilisearch...");
                $this->call('data-encryption:reindex', [
                    '--model' => $modelClass,
                    '--force' => true,
                ]);
            }
            
            $this->info("✅ Successfully encrypted '{$tableName}' table");
            
        } catch (\Exception $e) {
            $this->error("❌ Error encrypting '{$tableName}': " . $e->getMessage());
        }
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
            
            if ($this->confirm('Setup encryption for tables now?', true)) {
                $this->setupEncryptionForTables(false);
            }
        }
        
        $this->newLine();
        $this->info('💡 For automatic setup, run: php artisan data-encryption:install --auto');
        $this->info('💡 To encrypt a specific table: php artisan data-encryption:install --table=table_name');
        $this->info('💡 To encrypt all tables: php artisan data-encryption:install --all-tables');
    }
}