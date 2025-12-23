<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                        {model? : Model class to encrypt (e.g., App\Models\User)}
                        {--backup : Create backup before encryption}
                        {--field= : Specific field to encrypt}
                        {--chunk=1000 : Number of records to process at once}
                        {--force : Skip confirmation prompts}
                        {--skip-migration : Skip creating migration for hash columns}';
    
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
        
        // STEP 1: Add HasEncryptedFields trait if missing
        $traits = class_uses($model);
        $traitName = 'PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields';
        
        if (!in_array($traitName, $traits)) {
            $this->warn("⚠️ Model {$modelClass} does not use HasEncryptedFields trait");
            $this->info("📝 Automatically adding HasEncryptedFields trait to {$modelClass}...");
            
            if ($this->addTraitToModel($modelClass)) {
                $this->info("✅ Successfully added HasEncryptedFields trait to {$modelClass}");
                
                // Clear cache and reload
                $this->clearClassCache($modelClass);
                $model = new $modelClass;
                
                // Check again
                $traits = class_uses($model);
                if (!in_array($traitName, $traits)) {
                    $this->error("Trait still not detected. Please restart console and try again.");
                    return;
                }
            } else {
                $this->error("Failed to add HasEncryptedFields trait");
                return;
            }
        }
        
        // STEP 2: Get encrypted fields from model
        $reflection = new \ReflectionClass($modelClass);
        $encryptedFields = [];
        
        try {
            $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');
        } catch (\ReflectionException $e) {
            // Try to add default encrypted fields
            $this->info("📝 Adding default encrypted fields to {$modelClass}...");
            if ($this->addEncryptedFieldsToModel($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);
                $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');
            } else {
                $this->error("Model doesn't have encryptedFields configured");
                $this->line("Please add to {$modelClass}:");
                $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                return;
            }
        }

        if (empty($encryptedFields)) {
            $this->error("encryptedFields property is empty");
            $this->line("Please add fields to encrypt in {$modelClass}:");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
            return;
        }

        // STEP 3: Check if hash columns exist, create migration if needed
        $existingColumns = Schema::getColumnListing($table);
        $needsMigration = false;
        $hashColumnsToAdd = [];
        
        foreach ($encryptedFields as $field) {
            $hashColumn = $field . '_hash';
            $backupColumn = $field . '_backup';
            
            if (!in_array($hashColumn, $existingColumns)) {
                $needsMigration = true;
                $hashColumnsToAdd[$field] = [
                    'hash' => $hashColumn,
                    'backup' => $backupColumn
                ];
            }
        }
        
        // STEP 4: Create and run migration if needed
        if ($needsMigration && !$this->option('skip-migration')) {
            $this->info("📊 Creating migration for hash columns...");
            
            if ($this->createHashColumnsMigration($modelClass, $hashColumnsToAdd)) {
                $this->info("✅ Migration created successfully!");
                
                // Ask to run migration
                if ($this->confirm('Run the migration now?', true)) {
                    $this->call('migrate');
                    
                    // Refresh schema cache
                    Schema::getConnection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
                    
                    $this->info("✅ Migration executed!");
                } else {
                    $this->warn("⚠️  Migration created but not executed.");
                    $this->line("Run: php artisan migrate");
                    $this->line("Then run this command again.");
                    return;
                }
            } else {
                $this->error("Failed to create migration");
                return;
            }
        }
        
        // STEP 5: Backup if requested
        if ($this->option('backup')) {
            $this->createBackup($modelClass);
        }
        
        // STEP 6: Confirm encryption
        if (!$this->option('force')) {
            $this->warn('⚠️  This will encrypt data IN-PLACE in your database!');
            $this->warn('   Make sure you have a backup!');
            if (!$this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Encryption cancelled.');
                return;
            }
        }
        
        // STEP 7: Encrypt data
        $fields = array_filter($encryptedFields, function($field) use ($existingColumns) {
            return in_array($field, $existingColumns);
        });
        
        if (empty($fields)) {
            $this->warn("⚠️  No fields to encrypt in {$modelClass}");
            return;
        }
        
        $this->info("Encrypting fields for {$modelClass}: " . implode(', ', $fields));
        $this->encryptModelData($modelClass, $fields, $this->option('chunk'));
        
        $this->info('✅ Data encryption completed!');
        
        // STEP 8: Reindex to Meilisearch
        if (config('data-encryption.meilisearch.enabled', true)) {
            $this->info("\n🔍 Indexing to Meilisearch for search...");
            
            $meilisearch = app(MeilisearchService::class);
            $indexName = $model->getMeilisearchIndexName();
            
            if ($meilisearch->initializeIndex($indexName)) {
                $this->info("✅ Meilisearch index '{$indexName}' configured!");
                
                $this->call('data-encryption:reindex', [
                    '--model' => $modelClass,
                    '--force' => true,
                ]);
            } else {
                $this->error("❌ Failed to configure Meilisearch index");
            }
        }
    }
    
    /**
     * Add HasEncryptedFields trait to model
     */
    protected function addTraitToModel(string $modelClass): bool
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            $modelPath = $reflection->getFileName();
            
            if (!File::exists($modelPath)) {
                return false;
            }
            
            $content = File::get($modelPath);
            
            // Add trait import
            $traitImport = 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;';
            if (!str_contains($content, $traitImport)) {
                if (str_contains($content, 'namespace ')) {
                    $content = preg_replace(
                        '/(namespace [^;]+;)/',
                        "$1\n\n{$traitImport}",
                        $content
                    );
                }
            }
            
            // Add trait usage
            if (!str_contains($content, 'use HasEncryptedFields;')) {
                if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                    $classStart = $matches[1];
                    $content = str_replace(
                        $classStart,
                        $classStart . "\n    use HasEncryptedFields;",
                        $content
                    );
                }
            }
            
            // Add encrypted fields properties
            if (!str_contains($content, 'protected static $encryptedFields')) {
                $properties = "\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];";
                
                if (str_contains($content, 'use HasEncryptedFields;')) {
                    $content = str_replace(
                        'use HasEncryptedFields;',
                        'use HasEncryptedFields;' . $properties,
                        $content
                    );
                } else {
                    if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    }
                }
            }
            
            // Backup and save
            File::copy($modelPath, $modelPath . '.backup-' . date('YmdHis'));
            File::put($modelPath, $content);
            
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($modelPath, true);
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add encrypted fields to model
     */
    protected function addEncryptedFieldsToModel(string $modelClass): bool
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            $modelPath = $reflection->getFileName();
            
            $content = File::get($modelPath);
            
            if (!str_contains($content, 'protected static $encryptedFields')) {
                $properties = "\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];";
                
                if (str_contains($content, 'use HasEncryptedFields;')) {
                    $content = str_replace(
                        'use HasEncryptedFields;',
                        'use HasEncryptedFields;' . $properties,
                        $content
                    );
                } else {
                    if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    }
                }
                
                File::put($modelPath, $content);
                return true;
            }
            
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Create migration for hash columns
     */
    protected function createHashColumnsMigration(string $modelClass, array $hashColumns): bool
    {
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $modelName = class_basename($modelClass);
            
            $timestamp = date('Y_m_d_His');
            $migrationName = "add_hash_columns_to_{$table}_table";
            $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
            
            // Create migration content
            $fieldsCode = '';
            foreach ($hashColumns as $field => $columns) {
                $fieldsCode .= "
            if (Schema::hasColumn('{$table}', '{$field}')) {
                \$table->string('{$columns['hash']}', 64)->nullable()->index()->after('{$field}');
                \$table->string('{$columns['backup']}', 255)->nullable()->after('{$columns['hash']}');
            }";
            }
            
            $dropColumns = array_map(function($cols) {
                return "'" . $cols['hash'] . "', '" . $cols['backup'] . "'";
            }, array_values($hashColumns));
            
            $migrationContent = '<?php

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
        Schema::table(\'' . $table . '\', function (Blueprint $table) {
            $table->dropColumn([' . implode(', ', $dropColumns) . ']);
        });
    }
};';
            
            File::put($migrationFile, $migrationContent);
            return true;
            
        } catch (\Exception $e) {
            $this->error("Migration creation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear class cache
     */
    protected function clearClassCache(string $modelClass)
    {
        if (function_exists('opcache_invalidate')) {
            $reflection = new \ReflectionClass($modelClass);
            opcache_invalidate($reflection->getFileName(), true);
        }
    }
    
    protected function createBackup(string $modelClass)
    {
        $this->info('💾 Creating backup...');
        
        $backupPath = database_path('backups/' . date('Y-m-d_His'));
        File::makeDirectory($backupPath, 0755, true, true);
        
        $model = new $modelClass;
        $table = $model->getTable();
        
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
        
        $total = DB::table($table)->count();
        
        if ($total === 0) {
            $this->info("No records found in {$table}");
            return;
        }
        
        $this->info("Processing {$total} records...");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        DB::table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($records) use ($table, $fields, $encryptionService, $hashService, $bar, $primaryKey) {
            foreach ($records as $record) {
                $updateData = [];
                
                foreach ($fields as $field) {
                    if (!isset($record->$field) || empty($record->$field)) {
                        continue;
                    }
                    
                    $value = $record->$field;
                    
                    if (!$this->isEncrypted($value)) {
                        // Backup original value
                        $backupField = $field . '_backup';
                        if (Schema::hasColumn($table, $backupField)) {
                            $updateData[$backupField] = $value;
                        }
                        
                        // Encrypt
                        $updateData[$field] = $encryptionService->encrypt($value);
                        
                        // Create hash
                        $hashField = $field . '_hash';
                        $updateData[$hashField] = $hashService->hash($value);
                    }
                }
                
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