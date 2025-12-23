<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                        {model? : Model class to encrypt (e.g., App\Models\User)}
                        {--backup : Create backup before encryption}
                        {--field= : Specific field to encrypt}
                        {--chunk=1000 : Number of records to process at once}
                        {--force : Skip confirmation prompts}
                        {--add-columns : Automatically add hash columns to table}
                        {--add-trait : Automatically add HasEncryptedFields trait to model}';
    
    protected $description = 'Encrypt existing data in the database';
    
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
            return;
        }
        
        $model = new $modelClass;
        $table = $model->getTable();
        
        // Check if model uses HasEncryptedFields trait
        $traits = class_uses($model);
        $traitName = 'PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields';
        
        $shouldAddTrait = !in_array($traitName, $traits);
        
        if ($shouldAddTrait && $this->option('add-trait')) {
            $this->addEncryptionTraitToModel($modelClass);
            $shouldAddTrait = false; // Trait added
        }
        
        if ($shouldAddTrait) {
            $this->warn("⚠️  Model {$modelClass} does not use HasEncryptedFields trait");
            
            if ($this->confirm('Add HasEncryptedFields trait to this model automatically?', true)) {
                $this->addEncryptionTraitToModel($modelClass);
            } else {
                $this->line("Add this to your model manually:");
                $this->line("use PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields;");
                $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                
                if (!$this->confirm('Continue without trait?', false)) {
                    $this->info('Encryption cancelled.');
                    return;
                }
            }
        }
        
        // Get encrypted fields from model's static property or config
        $reflection = new \ReflectionClass($modelClass);
        
        try {
            $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');
        } catch (\ReflectionException $e) {
            // Property doesn't exist, use config or ask user
            $encryptedFields = config("data-encryption.encrypted_fields.{$modelClass}", []);
            
            if (empty($encryptedFields)) {
                $this->warn("⚠️  Model doesn't have encryptedFields property configured");
                $fieldInput = $this->ask('Enter comma-separated fields to encrypt (e.g., email,phone):');
                $encryptedFields = array_map('trim', explode(',', $fieldInput));
                
                // Save to config
                config(["data-encryption.encrypted_fields.{$modelClass}" => $encryptedFields]);
                
                // Add property to model
                $this->addPropertiesToModel($modelClass, $encryptedFields);
            }
        }

        if (empty($encryptedFields)) {
            $this->error("No encrypted fields configured for {$modelClass}");
            return;
        }

        $fields = $encryptedFields;
        
        // Check if we have the fields in database
        $existingColumns = Schema::getColumnListing($table);
        
        // Filter only fields that exist in the table
        $fields = array_filter($fields, function($field) use ($existingColumns) {
            return in_array($field, $existingColumns);
        });
        
        if (empty($fields)) {
            $this->warn("⚠️  No fields to encrypt in {$modelClass}");
            return;
        }
        
        // Check and add hash columns if needed
        $this->checkAndAddHashColumns($table, $fields, $modelClass);
        
        if ($this->option('backup')) {
            $this->createBackup($table);
        }
        
        // Check confirmation if not forced
        if (!$this->option('force')) {
            $this->warn('⚠️  This will encrypt data IN-PLACE in your database!');
            $this->warn('   Make sure you have a backup!');
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
    }
    
    protected function addEncryptionTraitToModel($modelClass)
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!File::exists($modelPath)) {
            $this->error("Model file not found: {$modelPath}");
            return false;
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
            // Find class definition
            if (preg_match('/(class\s+\w+\s+extends[^{]+{)/', $content, $matches)) {
                $classStart = $matches[1];
                $content = str_replace(
                    $classStart,
                    $classStart . "\n    use HasEncryptedFields;",
                    $content
                );
            }
        }
        
        File::put($modelPath, $content);
        $this->info("✅ Added HasEncryptedFields trait to {$modelClass}");
        
        return true;
    }
    
    protected function addPropertiesToModel($modelClass, $fields)
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!File::exists($modelPath)) {
            return;
        }
        
        $content = File::get($modelPath);
        
        // Check if properties already exist
        if (!str_contains($content, 'protected static $encryptedFields')) {
            // Add properties after class opening brace
            if (preg_match('/(class\s+\w+\s+extends[^{]+{)/', $content, $matches)) {
                $classStart = $matches[1];
                $propertyCode = "\n    protected static \$encryptedFields = " . 
                    $this->formatArray($fields) . ";\n" .
                    "    protected static \$searchableHashFields = " . 
                    $this->formatArray($fields) . ";\n";
                
                $content = str_replace(
                    $classStart,
                    $classStart . $propertyCode,
                    $content
                );
            }
        }
        
        File::put($modelPath, $content);
        $this->info("✅ Added encryption properties to {$modelClass}");
    }
    
    protected function formatArray($fields)
    {
        $quoted = array_map(function($field) {
            return "'" . addslashes($field) . "'";
        }, $fields);
        
        return "[" . implode(', ', $quoted) . "]";
    }
    
    protected function getModelPath($modelClass)
    {
        // Convert namespace to file path
        $relativePath = str_replace('App\\', '', $modelClass);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        return app_path($relativePath . '.php');
    }
    
    protected function checkAndAddHashColumns($table, $fields, $modelClass)
    {
        $this->info("🔍 Checking hash columns for {$table} table...");
        
        $missingColumns = [];
        
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            
            if (!Schema::hasColumn($table, $hashColumn)) {
                $missingColumns[] = $hashColumn;
            }
        }
        
        if (!empty($missingColumns)) {
            $this->warn("⚠️  Missing hash columns: " . implode(', ', $missingColumns));
            
            if ($this->option('add-columns') || $this->confirm('Add missing hash columns automatically?', true)) {
                $this->addHashColumnsToTable($table, $fields);
            } else {
                $this->error("❌ Cannot proceed without hash columns.");
                $this->line("Run this migration manually:");
                $this->showMigrationExample($table, $fields);
                return false;
            }
        }
        
        return true;
    }
    
    protected function addHashColumnsToTable($table, $fields)
    {
        $this->info("📝 Creating migration for {$table} table...");
        
        $timestamp = date('Y_m_d_His');
        $migrationName = "add_hash_columns_to_{$table}_table";
        $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
        
        $fieldsCode = '';
        $dropCode = '';
        
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            $fieldsCode .= "
            if (!Schema::hasColumn('{$table}', '{$hashColumn}')) {
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
        
        $this->info("✅ Migration created: {$migrationFile}");
        
        // Run migration
        if ($this->confirm('Run migration now?', true)) {
            $this->call('migrate');
        }
        
        return true;
    }
    
    protected function showMigrationExample($table, $fields)
    {
        $this->line("\nCreate migration with:");
        $this->line('php artisan make:migration add_hash_columns_to_' . $table . '_table');
        
        $this->line("\nAdd this code to the migration:");
        
        foreach ($fields as $field) {
            $this->line("\$table->string('{$field}_hash', 64)->nullable()->index()->after('{$field}');");
        }
    }
    
    protected function createBackup($table)
    {
        $this->info('💾 Creating backup...');
        
        $backupPath = database_path('backups/' . date('Y-m-d_His'));
        File::makeDirectory($backupPath, 0755, true, true);
        
        if (Schema::hasTable($table)) {
            $data = DB::table($table)->get()->toArray();
            $json = json_encode($data, JSON_PRETTY_PRINT);
            File::put($backupPath . '/' . $table . '.json', $json);
            
            $this->info("   Backed up {$table} table");
        }
        
        $this->info('✅ Backup created at: ' . $backupPath);
    }
    
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
        
        // Process in chunks
        DB::table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($records) use ($table, $fields, $encryptionService, $hashService, $bar, $primaryKey) {
            foreach ($records as $record) {
                $updateData = [];
                
                foreach ($fields as $field) {
                    if (!isset($record->$field) || empty($record->$field)) {
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
                    }
                }
                
                // Update record if we have changes
                if (!empty($updateData)) {
                    DB::table($table)
                        ->where($primaryKey, $record->$primaryKey)
                        ->update($updateData);
                }
                
                $bar->advance();
            }
        });
        
        $bar->finish();
        $this->newLine();
        $this->info("✅ {$modelClass} encryption completed");
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