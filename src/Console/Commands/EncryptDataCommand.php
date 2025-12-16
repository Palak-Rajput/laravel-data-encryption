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
            $this->error("Model {$modelClass} does not use HasEncryptedFields trait");
            $this->line("Add this to your model:");
            $this->line("use PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields;");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
            $this->line("protected static \$searchableHashFields = ['email', 'phone'];");
            return;
        }
        
        if ($this->option('backup')) {
            $this->createBackup();
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
        
        // Get encrypted fields from model's static property
        $reflection = new \ReflectionClass($modelClass);
        $encryptedFields = $reflection->getStaticPropertyValue('encryptedFields');

        if (empty($encryptedFields)) {
            $this->error("Model {$modelClass} doesn't have encryptedFields property configured");
            $this->line("Add this to your model:");
            $this->line("protected static \$encryptedFields = ['email', 'phone'];");
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
    
    protected function createBackup()
    {
        $this->info('💾 Creating backup...');
        
        $backupPath = database_path('backups/' . date('Y-m-d_His'));
        File::makeDirectory($backupPath, 0755, true, true);
        
        // Backup relevant tables
        $tables = ['users']; // Add other tables as needed
        
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $data = DB::table($table)->get()->toArray();
                $json = json_encode($data, JSON_PRETTY_PRINT);
                File::put($backupPath . '/' . $table . '.json', $json);
                
                $this->info("   Backed up {$table} table");
            }
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
                        
                        // For phone, create phone token
                        if ($field === 'phone') {
                            $digits = preg_replace('/\D+/', '', $value);
                            $updateData['phone_token'] = !empty($digits) ? $digits : null;
                        }
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