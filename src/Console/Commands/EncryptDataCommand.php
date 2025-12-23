<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\File;
use ReflectionClass;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                        {model? : Model class to encrypt (e.g., App\Models\User)}
                        {--backup : Create backup before encryption}
                        {--field= : Specific field to encrypt}
                        {--chunk=1000 : Number of records to process at once}
                        {--force : Skip confirmation prompts}
                        {--fields= : Comma-separated list of fields to encrypt (default: email,phone)}
                        {--searchable= : Comma-separated list of fields for searchable hashes}';
    
    protected $description = 'Encrypt existing data in the database and automatically setup model';
    
    public function handle()
    {
        $this->info('🔐 Starting data encryption...');
        
        // Get the model class from argument
        $modelClass = $this->argument('model');
        
        if (!$modelClass) {
            // If no model provided, ask for it
            $modelClass = $this->ask('Enter the model class (e.g., App\Models\User):');
            
            if (!$modelClass) {
                $this->error('Model is required.');
                return;
            }
        }
        
        // Check if model exists
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} not found");
            
            // Try to create the model file
            if ($this->confirm('Model file not found. Would you like to create it?', false)) {
                $modelPath = $this->createModelFile($modelClass);
                if (!$modelPath) {
                    return;
                }
            } else {
                return;
            }
        }
        
        // Auto-add HasEncryptedFields trait if not present
        $modelUpdated = $this->addHasEncryptedFieldsTrait($modelClass);
        
        if (!$modelUpdated) {
            $this->error("Failed to add HasEncryptedFields trait to model {$modelClass}");
            return;
        }
        
        // Get fields to encrypt (from option or ask)
        $fieldsInput = $this->option('fields');
        if ($fieldsInput) {
            $fields = explode(',', $fieldsInput);
        } else {
            // Ask for fields if not provided
            $fields = $this->askForFields($modelClass);
            if (empty($fields)) {
                $this->error('No fields specified for encryption.');
                return;
            }
        }
        
        // Get searchable fields
        $searchableInput = $this->option('searchable');
        if ($searchableInput) {
            $searchableFields = explode(',', $searchableInput);
        } else {
            $searchableFields = $fields; // Default to all fields
        }
        
        // Update model with fields configuration
        $this->updateModelWithFields($modelClass, $fields, $searchableFields);
        
        // Check if model uses HasEncryptedFields trait
        $model = new $modelClass;
        $traits = class_uses($model);
        $traitName = 'PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields';
        
        if (!in_array($traitName, $traits)) {
            $this->error("Model {$modelClass} does not use HasEncryptedFields trait");
            $this->line("Trying to add it automatically...");
            
            // Try to add trait again
            if (!$this->addHasEncryptedFieldsTrait($modelClass)) {
                $this->error("Failed to add HasEncryptedFields trait");
                $this->line("Add this to your model manually:");
                $this->line("use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;");
                $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                return;
            }
        }
        
        // Get encrypted fields from model's static property
        $reflection = new ReflectionClass($modelClass);
        
        // Check if encryptedFields property exists
        if (!$reflection->hasProperty('encryptedFields')) {
            $this->error("Model {$modelClass} doesn't have encryptedFields property configured");
            $this->line("Adding it now...");
            $this->updateModelWithFields($modelClass, $fields, $searchableFields);
        }
        
        $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');
        
        if (empty($encryptedFields)) {
            $this->error("Model {$modelClass} doesn't have encryptedFields property configured");
            $this->line("Adding default fields...");
            $this->updateModelWithFields($modelClass, $fields, $searchableFields);
            $encryptedFields = $fields;
        }

        $fields = $encryptedFields;
        
        // Create migration for hash columns if they don't exist
        $migrationCreated = $this->createMigrationIfNeeded($modelClass, $fields);
        
        if ($migrationCreated) {
            $this->info('🚀 Running migration to add hash columns...');
            $this->call('migrate');
        }
        
        // Check if we have the fields in database
        $table = $model->getTable();
        $existingColumns = Schema::getColumnListing($table);
        
        // Filter only fields that exist in the table
        $fields = array_filter($fields, function($field) use ($existingColumns) {
            return in_array($field, $existingColumns);
        });
        
        if (empty($fields)) {
            $this->warn("⚠️  No fields to encrypt in {$modelClass}");
            $this->line("Available columns: " . implode(', ', $existingColumns));
            return;
        }
        
        if ($this->option('backup')) {
            $this->createBackup($modelClass);
        }
        
        // Check confirmation if not forced
        if (!$this->option('force')) {
            $this->warn('⚠️  This will encrypt data IN-PLACE in your database!');
            $this->warn('   Make sure you have a backup!');
            $this->info("Fields to encrypt: " . implode(', ', $fields));
            if (!$this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Encryption cancelled.');
                return;
            }
        }
        
        $this->info("Encrypting fields for {$modelClass}: " . implode(', ', $fields));
        
        $this->encryptModelData($modelClass, $fields, $this->option('chunk'));
        
        $this->info('✅ Data encryption completed!');
        
        // Reindex to Meilisearch after encryption
        if (config('data-encryption.meilisearch.enabled', true)) {
            $this->info("\n🔍 Indexing to Meilisearch for search...");
            
            // Initialize index first
            $meilisearch = app(MeilisearchService::class);
            $indexName = $model->getMeilisearchIndexName();
            
            if ($meilisearch->initializeIndex($indexName)) {
                $this->info("✅ Meilisearch index '{$indexName}' configured!");
                
                // Reindex all records
                $this->call('data-encryption:reindex', [
                    '--model' => $modelClass,
                    '--force' => true,
                ]);
            } else {
                $this->error("❌ Failed to configure Meilisearch index");
            }
        }
        
        $this->info("\n✅ Model {$modelClass} has been successfully configured with:");
        $this->line("   - HasEncryptedFields trait");
        $this->line("   - encryptedFields: " . implode(', ', $fields));
        $this->line("   - searchableHashFields: " . implode(', ', $searchableFields));
    }
    
    /**
     * Add HasEncryptedFields trait to model
     */
    protected function addHasEncryptedFieldsTrait($modelClass): bool
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!$modelPath || !File::exists($modelPath)) {
            $this->error("Could not find model file for: {$modelClass}");
            return false;
        }
        
        $content = File::get($modelPath);
        $modelName = class_basename($modelClass);
        
        $changesMade = false;
        
        // Add trait import if missing
        $traitImport = 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;';
        if (!str_contains($content, $traitImport)) {
            // Add after namespace
            $content = preg_replace(
                '/^(namespace .+;)/m',
                "$1\n\n" . $traitImport,
                $content
            );
            $changesMade = true;
            $this->info("✅ Added trait import to {$modelName}");
        }
        
        // Add trait inside class if missing
        if (!preg_match('/use\s+HasEncryptedFields\s*;/', $content)) {
            // Find the class definition and add trait after opening brace
            $pattern = '/(class\s+' . preg_quote($modelName) . '\s+.*\{)/';
            if (preg_match($pattern, $content)) {
                $content = preg_replace(
                    $pattern,
                    "$1\n    use HasEncryptedFields;",
                    $content
                );
                $changesMade = true;
                $this->info("✅ Added HasEncryptedFields trait to {$modelName}");
            } else {
                // Alternative pattern
                $pattern = '/(class ' . preg_quote($modelName) . '.*\{)/';
                $content = preg_replace(
                    $pattern,
                    "$1\n    use HasEncryptedFields;",
                    $content
                );
                $changesMade = true;
                $this->info("✅ Added HasEncryptedFields trait to {$modelName}");
            }
        }
        
        if ($changesMade) {
            File::put($modelPath, $content);
            $this->info("✅ Updated {$modelName} model file");
        }
        
        return true;
    }
    
    /**
     * Update model with fields configuration
     */
    protected function updateModelWithFields($modelClass, $fields, $searchableFields): bool
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!$modelPath || !File::exists($modelPath)) {
            $this->error("Could not find model file for: {$modelClass}");
            return false;
        }
        
        $content = File::get($modelPath);
        $modelName = class_basename($modelClass);
        
        $fieldsStr = var_export($fields, true);
        $searchableStr = var_export($searchableFields, true);
        
        $changesMade = false;
        
        // Add encryptedFields property
        if (!str_contains($content, 'protected static $encryptedFields')) {
            // Find where to add the property (after the trait)
            $pattern = '/(use HasEncryptedFields;\s*)/';
            if (preg_match($pattern, $content, $matches)) {
                $replacement = $matches[1] . "\n    protected static \$encryptedFields = {$fieldsStr};";
                $content = preg_replace($pattern, $replacement, $content, 1);
                $changesMade = true;
                $this->info("✅ Added encryptedFields to {$modelName}");
            } else {
                // If trait not found, add properties after class opening
                $pattern = '/(class ' . preg_quote($modelName) . '.*\{)/';
                if (preg_match($pattern, $content)) {
                    $replacement = "$1\n    use HasEncryptedFields;\n    protected static \$encryptedFields = {$fieldsStr};";
                    $content = preg_replace($pattern, $replacement, $content, 1);
                    $changesMade = true;
                    $this->info("✅ Added trait and encryptedFields to {$modelName}");
                }
            }
        } else {
            // Update existing property
            $pattern = '/(protected static \$encryptedFields\s*=\s*)(\[.*?\])(\s*;)/s';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '$1' . $fieldsStr . '$3', $content);
                $changesMade = true;
                $this->info("✅ Updated encryptedFields for {$modelName}");
            }
        }
        
        // Add searchableHashFields property
        if (!str_contains($content, 'protected static $searchableHashFields')) {
            // Find encryptedFields property and add searchableHashFields after it
            $pattern = '/(protected static \$encryptedFields\s*=\s*' . preg_quote($fieldsStr, '/') . '\s*;\s*)/';
            if (preg_match($pattern, $content)) {
                $replacement = "$1    protected static \$searchableHashFields = {$searchableStr};\n";
                $content = preg_replace($pattern, $replacement, $content, 1);
                $changesMade = true;
                $this->info("✅ Added searchableHashFields to {$modelName}");
            } else {
                // If pattern not found, add it after encryptedFields with any value
                $pattern = '/(protected static \$encryptedFields\s*=\s*)(\[.*?\])(\s*;)/s';
                if (preg_match($pattern, $content, $matches)) {
                    $replacement = $matches[0] . "\n    protected static \$searchableHashFields = {$searchableStr};";
                    $content = preg_replace($pattern, $replacement, $content, 1);
                    $changesMade = true;
                    $this->info("✅ Added searchableHashFields to {$modelName}");
                }
            }
        } else {
            // Update existing property
            $pattern = '/(protected static \$searchableHashFields\s*=\s*)(\[.*?\])(\s*;)/s';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '$1' . $searchableStr . '$3', $content);
                $changesMade = true;
                $this->info("✅ Updated searchableHashFields for {$modelName}");
            }
        }
        
        if ($changesMade) {
            File::put($modelPath, $content);
            $this->info("✅ Updated fields configuration in {$modelName}");
        }
        
        return true;
    }
    
    /**
     * Ask user for fields to encrypt
     */
    protected function askForFields($modelClass): array
    {
        $model = new $modelClass;
        $table = $model->getTable();
        
        if (!Schema::hasTable($table)) {
            $this->error("Table {$table} does not exist!");
            return [];
        }
        
        $columns = Schema::getColumnListing($table);
        
        $this->info("Available columns in {$table}: " . implode(', ', $columns));
        
        $defaultFields = ['email', 'phone'];
        $availableDefaults = array_intersect($defaultFields, $columns);
        
        $fields = $this->ask(
            'Enter fields to encrypt (comma-separated, e.g., email,phone):',
            implode(',', $availableDefaults)
        );
        
        return array_map('trim', explode(',', $fields));
    }
    
    /**
     * Get model file path
     */
    protected function getModelPath($modelClass): ?string
    {
        // Convert namespace to file path
        $modelName = class_basename($modelClass);
        
        // Try common locations
        $possiblePaths = [
            app_path('Models/' . $modelName . '.php'),
            app_path($modelName . '.php'),
        ];
        
        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Create model file if it doesn't exist
     */
    protected function createModelFile($modelClass): ?string
    {
        $modelName = class_basename($modelClass);
        $namespace = str_replace('\\' . $modelName, '', $modelClass);
        
        $modelPath = app_path('Models/' . $modelName . '.php');
        
        // Create directory if it doesn't exist
        File::ensureDirectoryExists(dirname($modelPath));
        
        $content = "<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Model;
use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;

class {$modelName} extends Model
{
    use HasEncryptedFields;
    
    protected static \$encryptedFields = ['email', 'phone'];
    protected static \$searchableHashFields = ['email', 'phone'];
    
    // Add your model properties and methods here
}
";
        
        if (File::put($modelPath, $content)) {
            $this->info("✅ Created model file: {$modelPath}");
            return $modelPath;
        }
        
        $this->error("Failed to create model file: {$modelPath}");
        return null;
    }
    
    /**
     * Create migration for hash columns if needed
     */
    protected function createMigrationIfNeeded($modelClass, $fields): bool
    {
        $model = new $modelClass;
        $table = $model->getTable();
        
        if (!Schema::hasTable($table)) {
            $this->error("Table {$table} does not exist!");
            return false;
        }
        
        // Check if hash columns already exist
        $existingColumns = Schema::getColumnListing($table);
        $missingHashColumns = [];
        
        foreach ($fields as $field) {
            if (!in_array($field, $existingColumns)) {
                $this->warn("⚠️  Field '{$field}' does not exist in table '{$table}'");
                continue;
            }
            
            $hashColumn = $field . '_hash';
            if (!in_array($hashColumn, $existingColumns)) {
                $missingHashColumns[] = $field;
            }
        }
        
        if (empty($missingHashColumns)) {
            $this->info("✅ Hash columns already exist in {$table}");
            return false;
        }
        
        $this->info("Creating migration for hash columns in {$table}...");
        
        $timestamp = date('Y_m_d_His');
        $migrationName = "add_hash_columns_to_{$table}_table";
        $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
        
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
                    
                    // Add backup column if enabled
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
        
        if (File::put($migrationFile, $content)) {
            $this->info("✅ Created migration file: {$migrationFile}");
            return true;
        }
        
        $this->error("Failed to create migration file: {$migrationFile}");
        return false;
    }
    
    /**
     * Create backup for specific model
     */
    protected function createBackup($modelClass)
    {
        $this->info('💾 Creating backup...');
        
        $model = new $modelClass;
        $table = $model->getTable();
        $modelName = class_basename($modelClass);
        
        $backupPath = database_path('backups/' . date('Y-m-d_His') . '_' . $modelName);
        File::makeDirectory($backupPath, 0755, true, true);
        
        if (Schema::hasTable($table)) {
            try {
                $data = DB::table($table)->get()->toArray();
                $json = json_encode($data, JSON_PRETTY_PRINT);
                File::put($backupPath . '/' . $table . '.json', $json);
                
                $this->info("   Backed up {$table} table");
            } catch (\Exception $e) {
                $this->error("Failed to backup {$table}: " . $e->getMessage());
            }
        } else {
            $this->warn("Table {$table} does not exist, skipping backup");
        }
        
        $this->info('✅ Backup created at: ' . $backupPath);
    }
    
    /**
     * Encrypt model data
     */
    protected function encryptModelData($modelClass, $fields, $chunkSize = 1000)
    {
        $this->info("Encrypting {$modelClass}...");
        
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);
        
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        
        // Get total count
        $total = DB::table($table)->count();
        
        if ($total === 0) {
            $this->info("No records found in {$table}");
            return;
        }
        
        $this->info("Processing {$total} records...");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $processed = 0;
        $encryptedCount = 0;
        
        // Process in chunks
        DB::table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($records) use ($table, $fields, $encryptionService, $hashService, $bar, $primaryKey, &$processed, &$encryptedCount) {
            foreach ($records as $record) {
                $updateData = [];
                
                foreach ($fields as $field) {
                    if (!isset($record->$field) || $record->$field === null || $record->$field === '') {
                        continue;
                    }
                    
                    $value = $record->$field;
                    
                    // Check if already encrypted
                    if (!$this->isEncrypted($value)) {
                        // Encrypt the value
                        $updateData[$field] = $encryptionService->encrypt($value);
                        
                        // Create hash for exact search
                        $hashField = $field . '_hash';
                        $updateData[$hashField] = $hashService->hash($value);
                        
                        $encryptedCount++;
                    }
                }
                
                // Update record if we have changes
                if (!empty($updateData)) {
                    DB::table($table)
                        ->where($primaryKey, $record->$primaryKey)
                        ->update($updateData);
                }
                
                $processed++;
                $bar->advance();
            }
        });
        
        $bar->finish();
        $this->newLine();
        $this->info("✅ {$modelClass} encryption completed");
        $this->info("📊 Statistics:");
        $this->line("   - Total records processed: {$processed}");
        $this->line("   - Fields encrypted: {$encryptedCount}");
    }

    protected function isEncrypted($value): bool
    {
        if (!is_string($value)) return false;
        try {
            $decoded = base64_decode($value, true);
            if ($decoded === false) return false;
            $data = json_decode($decoded, true);
            return isset($data['iv'], $data['value'], $data['mac']);
        } catch (\Exception $e) {
            return false;
        }
    }
}