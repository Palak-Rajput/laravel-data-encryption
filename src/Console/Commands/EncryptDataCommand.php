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
        $reflection = new \ReflectionClass($modelClass);
        $modelPath = $reflection->getFileName();
        
        // STEP 1: Check and add HasEncryptedFields configuration if needed
        $content = File::get($modelPath);
        
        $hasTraitImport = str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;');
        $hasTraitUsage = str_contains($content, 'use HasEncryptedFields;');
        $hasEncryptedFields = str_contains($content, 'protected static $encryptedFields');
        
        // If any of these are missing, add them
        if (!$hasTraitImport || !$hasTraitUsage || !$hasEncryptedFields) {
            $this->warn("⚠️ Model {$modelClass} is missing HasEncryptedFields configuration");
            $this->info("📝 Automatically adding HasEncryptedFields configuration to {$modelClass}...");
            
            if ($this->addTraitToModel($modelClass)) {
                $this->info("✅ Successfully added HasEncryptedFields configuration to {$modelClass}");
                
                // Clear cache
                $this->clearClassCache($modelClass);
                
                // Re-read the updated content
                $content = File::get($modelPath);
                $hasTraitImport = str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;');
                $hasTraitUsage = str_contains($content, 'use HasEncryptedFields;');
                $hasEncryptedFields = str_contains($content, 'protected static $encryptedFields');
                
                if (!$hasTraitImport || !$hasTraitUsage || !$hasEncryptedFields) {
                    $this->error("Failed to verify changes to {$modelClass}. Please add manually:");
                    $this->line("use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;");
                    $this->line("use HasEncryptedFields;");
                    $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                    $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                    return;
                }
            } else {
                $this->error("Failed to add HasEncryptedFields configuration");
                return;
            }
        }
        
        // STEP 2: Get encrypted fields from updated content
        $encryptedFields = $this->getEncryptedFieldsFromContent($content);
        
        if (empty($encryptedFields)) {
            $this->error("encryptedFields property is empty or not found");
            $this->line("Please add fields to encrypt in {$modelClass}:");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
            $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
            return;
        }

        $this->info("✅ Model configured with fields: " . implode(', ', $encryptedFields));
        
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
                $this->info("⚠️ Missing hash column: {$hashColumn}");
            } else {
                $this->info("✅ Hash column exists: {$hashColumn}");
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
                    
                    $this->info("✅ Migration executed!");
                    
                    // Refresh schema cache
                    Schema::getConnection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
                    
                    // Update existing columns after migration
                    $existingColumns = Schema::getColumnListing($table);
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
        } elseif (!$needsMigration) {
            $this->info("✅ All hash columns already exist in database");
        }
        
        // STEP 5: Check if we can proceed with encryption
        $fields = array_filter($encryptedFields, function($field) use ($existingColumns) {
            $hashColumn = $field . '_hash';
            return in_array($field, $existingColumns) && in_array($hashColumn, $existingColumns);
        });
        
        if (empty($fields)) {
            $this->warn("⚠️  No fields to encrypt or missing hash columns");
            $this->line("Make sure hash columns exist for: " . implode(', ', $encryptedFields));
            $this->line("Run migrations first or use --skip-migration to skip hash column check");
            return;
        }
        
        // STEP 6: Backup if requested
        if ($this->option('backup')) {
            $this->createBackup($modelClass);
        }
        
        // STEP 7: Confirm encryption
        if (!$this->option('force')) {
            $this->warn('⚠️  This will encrypt data IN-PLACE in your database!');
            $this->warn('   Make sure you have a backup!');
            if (!$this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Encryption cancelled.');
                return;
            }
        }
        
        // STEP 8: Encrypt data
        $this->info("Encrypting fields for {$modelClass}: " . implode(', ', $fields));
        $this->encryptModelData($modelClass, $fields, $this->option('chunk'));
        
        $this->info('✅ Data encryption completed!');
        
        // STEP 9: Reindex to Meilisearch (only if trait method exists)
        try {
            if (config('data-encryption.meilisearch.enabled', true) && method_exists($model, 'getMeilisearchIndexName')) {
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
            } else {
                $this->info("\n⚠️ Meilisearch not enabled or model doesn't support Meilisearch indexing");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Meilisearch indexing failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get encrypted fields from model file content
     */
    protected function getEncryptedFieldsFromContent(string $content): array
    {
        // Try to extract encryptedFields array from content
        if (preg_match('/protected static\s+\$encryptedFields\s*=\s*\[(.*?)\]\s*;/s', $content, $matches)) {
            $fieldsString = $matches[1];
            // Extract quoted strings
            if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $fieldsString, $fieldMatches)) {
                return $fieldMatches[1];
            }
        }
        
        // Try alternative pattern
        if (preg_match('/\$encryptedFields\s*=\s*\[(.*?)\]\s*;/s', $content, $matches)) {
            $fieldsString = $matches[1];
            if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $fieldsString, $fieldMatches)) {
                return $fieldMatches[1];
            }
        }
        
        return [];
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
                $this->error("Model file not found: {$modelPath}");
                return false;
            }
            
            $content = File::get($modelPath);
            $originalContent = $content;
            
            // Debug: Show current content
            $this->line("\n📄 Current model content (first 500 chars):");
            $this->line(substr($content, 0, 500) . "...");
            
            // Add trait import if missing
            $traitImport = 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;';
            if (!str_contains($content, $traitImport)) {
                $this->info("Adding trait import...");
                if (str_contains($content, 'namespace ')) {
                    $content = preg_replace(
                        '/(namespace [^;]+;)/',
                        "$1\n\n{$traitImport}",
                        $content
                    );
                } else {
                    // Add at the beginning after PHP tag
                    $content = preg_replace(
                        '/^<\?php\s*/',
                        "<?php\n\n{$traitImport}\n",
                        $content
                    );
                }
            }
            
            // Add trait usage if missing
            if (!str_contains($content, 'use HasEncryptedFields;')) {
                $this->info("Adding trait usage...");
                if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                    $classStart = $matches[1];
                    $content = str_replace(
                        $classStart,
                        $classStart . "\n    use HasEncryptedFields;",
                        $content
                    );
                } elseif (preg_match('/(class\s+\w+\s*{)/', $content, $matches)) {
                    $classStart = $matches[1];
                    $content = str_replace(
                        $classStart,
                        $classStart . "\n    use HasEncryptedFields;",
                        $content
                    );
                }
            }
            
            // Add encrypted fields properties if missing
            if (!str_contains($content, 'protected static $encryptedFields')) {
                $this->info("Adding encrypted fields properties...");
                $properties = "\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];";
                
                if (str_contains($content, 'use HasEncryptedFields;')) {
                    $content = str_replace(
                        'use HasEncryptedFields;',
                        'use HasEncryptedFields;' . $properties,
                        $content
                    );
                } else {
                    // Find class and add after opening brace
                    if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    } elseif (preg_match('/(class\s+\w+\s*{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    }
                }
            }
            
            // Only save if content changed
            if ($content !== $originalContent) {
                $this->info("Saving changes to model file...");
                
                // Backup and save
                $backupPath = $modelPath . '.backup-' . date('YmdHis');
                File::copy($modelPath, $backupPath);
                $this->info("Backup created: " . basename($backupPath));
                
                File::put($modelPath, $content);
                
                // Debug: Show updated content
                $this->line("\n📄 Updated model content (first 500 chars):");
                $this->line(substr($content, 0, 500) . "...");
                
                // Clear opcache
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($modelPath, true);
                }
                
                return true;
            } else {
                $this->info("No changes needed - model already has correct configuration");
                return true;
            }
            
        } catch (\Exception $e) {
            $this->error("Error modifying model: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
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
            
            $timestamp = date('Y_m_d_His');
            $migrationName = "add_hash_columns_to_{$table}_table";
            $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
            
            // Check if migration already exists
            if (File::exists($migrationFile)) {
                $this->warn("Migration already exists: " . basename($migrationFile));
                return true;
            }
            
            // Create migration content
            $fieldsCode = '';
            foreach ($hashColumns as $field => $columns) {
                $fieldsCode .= "
            if (Schema::hasColumn('{$table}', '{$field}')) {
                \$table->string('{$columns['hash']}', 64)->nullable()->index()->after('{$field}');
                \$table->string('{$columns['backup']}', 255)->nullable()->after('{$columns['hash']}');
            }";
            }
            
            $dropColumns = [];
            foreach ($hashColumns as $columns) {
                $dropColumns[] = "'" . $columns['hash'] . "'";
                $dropColumns[] = "'" . $columns['backup'] . "'";
            }
            
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
            
            // Ensure migrations directory exists
            $migrationsDir = database_path('migrations');
            if (!File::exists($migrationsDir)) {
                File::makeDirectory($migrationsDir, 0755, true);
            }
            
            File::put($migrationFile, $migrationContent);
            $this->info("📄 Migration file created: " . basename($migrationFile));
            
            // Show the migration content
            $this->line("\n📋 Migration content:");
            $this->line($migrationContent);
            
            return true;
            
        } catch (\Exception $e) {
            $this->error("Migration creation failed: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Clear class cache
     */
    protected function clearClassCache(string $modelClass)
    {
        if (function_exists('opcache_invalidate')) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                opcache_invalidate($reflection->getFileName(), true);
            } catch (\Exception $e) {
                // Ignore opcache errors
            }
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