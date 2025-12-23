<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\File;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                        {model? : Model class to encrypt (e.g., App\Models\User)}
                        {--backup : Create backup before encryption}
                        {--field= : Specific field to encrypt}
                        {--chunk=1000 : Number of records to process at once}
                        {--force : Skip confirmation prompts}';
    
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
        
        // Check if model uses HasEncryptedFields trait
        $model = new $modelClass;
        $traits = class_uses($model);
        $traitName = 'PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields';
        
        if (!in_array($traitName, $traits)) {
            $this->warn("⚠️ Model {$modelClass} does not use HasEncryptedFields trait");
            $this->info("📝 Automatically adding HasEncryptedFields trait to {$modelClass}...");
            
            // Automatically add the trait without asking
            if ($this->addTraitToModel($modelClass)) {
                $this->info("✅ Successfully added HasEncryptedFields trait to {$modelClass}");
                
                // Need to clear the class cache to reload traits
                $this->clearClassCache($modelClass);
                
                // Reload the model to get updated traits
                $model = new $modelClass;
                $traits = class_uses($model);
                
                if (!in_array($traitName, $traits)) {
                    $this->error("Failed to add HasEncryptedFields trait to {$modelClass}");
                    $this->line("Please add this to your model manually:");
                    $this->line("use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;");
                    $this->line("use HasEncryptedFields;");
                    $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                    $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                    return;
                }
            } else {
                $this->error("Failed to add HasEncryptedFields trait to {$modelClass}");
                $this->line("Please add this to your model manually:");
                $this->line("use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;");
                $this->line("use HasEncryptedFields;");
                $this->line("protected static \$encryptedFields = ['email', 'phone'];");
                $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
                return;
            }
        }
        
        // Get encrypted fields from model's static property
        $reflection = new \ReflectionClass($modelClass);
        $encryptedFields = [];
        
        try {
            $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');
        } catch (\ReflectionException $e) {
            $this->error("Model {$modelClass} doesn't have encryptedFields property configured");
            $this->line("Please add this to your model manually:");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
            $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
            return;
        }

        if (empty($encryptedFields)) {
            $this->error("Model {$modelClass} doesn't have encryptedFields property configured or it's empty");
            $this->line("Please add this to your model manually:");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
            $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
            return;
        }

        $fields = $encryptedFields;
        
        // Check if we have the fields in database
        $table = $model->getTable();
        $existingColumns = Schema::getColumnListing($table);
        
        // Filter only fields that exist in the table
        $fields = array_filter($fields, function($field) use ($existingColumns) {
            return in_array($field, $existingColumns);
        });
        
        if (empty($fields)) {
            $this->warn("⚠️  No fields to encrypt in {$modelClass}");
            return;
        }
        
        $this->info("Encrypting fields for {$modelClass}: " . implode(', ', $fields));
        
        if ($this->option('backup')) {
            $this->createBackup($modelClass);
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
    
    /**
     * Automatically add HasEncryptedFields trait to model
     */
    protected function addTraitToModel(string $modelClass): bool
    {
        try {
            // Use reflection to get model file path
            $reflection = new \ReflectionClass($modelClass);
            $modelPath = $reflection->getFileName();
            
            if (!File::exists($modelPath)) {
                $this->error("Model file not found: {$modelPath}");
                return false;
            }
            
            $content = File::get($modelPath);
            
            // Add the trait import at the top
            $traitImport = 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;';
            
            // Check if already has trait import
            if (!str_contains($content, $traitImport)) {
                // Add after namespace
                if (str_contains($content, 'namespace ')) {
                    $content = preg_replace(
                        '/(namespace [^;]+;)/',
                        "$1\n\n{$traitImport}",
                        $content
                    );
                } else {
                    // Add at the beginning after opening PHP tag
                    $content = preg_replace(
                        '/^<\?php\s*/',
                        "<?php\n\n{$traitImport}\n",
                        $content
                    );
                }
            }
            
            // Add the trait usage inside the class
            $traitUsage = 'use HasEncryptedFields;';
            
            if (!str_contains($content, $traitUsage)) {
                // Find the class definition
                if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                    $classStart = $matches[1];
                    // Add trait usage after class opening brace
                    $content = str_replace(
                        $classStart,
                        $classStart . "\n    {$traitUsage}",
                        $content
                    );
                } else {
                    // Try a simpler pattern
                    if (preg_match('/(class\s+\w+\s*{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . "\n    {$traitUsage}",
                            $content
                        );
                    }
                }
            }
            
            // Add encrypted fields properties
            $properties = "\n    protected static \$encryptedFields = ['email', 'phone'];\n    protected static \$searchableHashFields = ['email', 'phone'];";
            
            if (!str_contains($content, 'protected static $encryptedFields')) {
                // Find where to insert - after trait usage if it exists
                if (str_contains($content, $traitUsage)) {
                    $content = str_replace(
                        $traitUsage,
                        $traitUsage . $properties,
                        $content
                    );
                } else {
                    // Add after class opening
                    if (preg_match('/(class\s+\w+\s+extends\s+[^{]+{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    } else if (preg_match('/(class\s+\w+\s*{)/', $content, $matches)) {
                        $classStart = $matches[1];
                        $content = str_replace(
                            $classStart,
                            $classStart . $properties,
                            $content
                        );
                    }
                }
            }
            
            // Backup original file
            File::copy($modelPath, $modelPath . '.backup-' . date('YmdHis'));
            
            // Write updated content
            File::put($modelPath, $content);
            
            // Clear opcache if enabled
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($modelPath, true);
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->error("Failed to modify model: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear class cache to reload traits
     */
    protected function clearClassCache(string $modelClass)
    {
        // Clear the class from memory
        if (function_exists('opcache_invalidate')) {
            $reflection = new \ReflectionClass($modelClass);
            opcache_invalidate($reflection->getFileName(), true);
        }
        
        // Clear Laravel's class loader cache
        if (app()->bound('cache')) {
            app('cache')->forget('class-trait-map-' . $modelClass);
        }
    }
    
    protected function createBackup(string $modelClass)
    {
        $this->info('💾 Creating backup...');
        
        $backupPath = database_path('backups/' . date('Y-m-d_His'));
        File::makeDirectory($backupPath, 0755, true, true);
        
        // Get model table
        $model = new $modelClass;
        $table = $model->getTable();
        
        // Backup the specific table
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
        DB::table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($records) use ($table, $fields, $encryptionService, $hashService, $bar, $primaryKey, $modelClass) {
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