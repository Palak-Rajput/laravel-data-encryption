<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\HashService;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                            {model : Model class name}
                            {--fields= : Fields to encrypt (comma-separated)}
                            {--chunk=1000 : Number of records per chunk}
                            {--dry-run : Show what would be encrypted without doing it}
                            {--backup : Backup original data to *_backup columns}';
    
    protected $description = 'Encrypt existing data in database - ENCRYPTS ORIGINAL COLUMNS';
    
    public function handle()
    {
        $modelClass = $this->argument('model');
        
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist!");
            return 1;
        }
        
        $fields = $this->option('fields') 
            ? explode(',', $this->option('fields'))
            : ['email', 'phone'];
        
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');
        
        $this->info("🔐 ENCRYPTING DATA IN ORIGINAL COLUMNS for model: {$modelClass}");
        $this->info("⚠️  WARNING: This will overwrite {$modelClass}::" . implode(', ', $fields) . " columns!");
        $this->info("Fields: " . implode(', ', $fields));
        $this->info("Chunk size: {$chunkSize}");
        
        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }
        
        $model = new $modelClass;
        $table = $model->getTable();
        
        // Verify hash columns exist
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            if (!Schema::hasColumn($table, $hashColumn)) {
                $this->error("Column {$hashColumn} does not exist! Run migrations first.");
                return 1;
            }
            
            if ($backup && !Schema::hasColumn($table, $field . '_backup')) {
                $this->error("Column {$field}_backup does not exist for backup! Use --backup flag during migration.");
                return 1;
            }
        }
        
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);
        
        $total = DB::table($table)->count();
        $this->info("Total records to process: {$total}");
        
        if (!$dryRun && $this->confirm("⚠️  DANGER: This will encrypt {$total} records. BACKUP YOUR DATABASE FIRST! Continue?", false)) {
            $this->error("Operation cancelled. Please backup your database first.");
            return 1;
        }
        
        $bar = $this->output->createProgressBar($total);
        $encryptedCount = 0;
        $skippedCount = 0;
        
        DB::table($table)->orderBy('id')->chunk($chunkSize, function ($records) use (
            $table, $fields, $encryptionService, $hashService, $bar, $dryRun, $backup, &$encryptedCount, &$skippedCount
        ) {
            foreach ($records as $record) {
                $updates = [];
                $shouldUpdate = false;
                
                foreach ($fields as $field) {
                    if (isset($record->$field) && !empty($record->$field)) {
                        // Check if already encrypted
                        if ($this->isAlreadyEncrypted($record->$field)) {
                            $skippedCount++;
                            continue;
                        }
                        
                        // Backup original if requested
                        if ($backup) {
                            $backupField = $field . '_backup';
                            $updates[$backupField] = $record->$field;
                        }
                        
                        // Encrypt the value INTO THE ORIGINAL COLUMN
                        $updates[$field] = $encryptionService->encrypt($record->$field);
                        
                        // Create hash for searching
                        $updates[$field . '_hash'] = $hashService->hash($record->$field);
                        
                        $shouldUpdate = true;
                        $encryptedCount++;
                    }
                }
                
                if ($shouldUpdate && !$dryRun) {
                    DB::table($table)
                        ->where('id', $record->id)
                        ->update($updates);
                }
                
                $bar->advance();
            }
        });
        
        $bar->finish();
        $this->newLine();
        
        if ($dryRun) {
            $this->info("📊 Dry run results:");
            $this->line("   Would encrypt: {$encryptedCount} records");
            $this->line("   Already encrypted: {$skippedCount} records");
            $this->line("   Total records: {$total}");
            $this->info('✅ Dry run completed. No changes made.');
        } else {
            $this->info("📊 Encryption completed:");
            $this->line("   Encrypted: {$encryptedCount} records");
            $this->line("   Already encrypted: {$skippedCount} records");
            $this->line("   Total processed: {$total}");
            
            if ($backup) {
                $this->info('💾 Original data backed up in *_backup columns');
                $this->warn('⚠️  Remove backup columns after verification:');
                foreach ($fields as $field) {
                    $this->line("   ALTER TABLE {$table} DROP COLUMN {$field}_backup;");
                }
            }
            
            $this->info('✅ Data encryption completed successfully!');
            $this->warn('⚠️  IMPORTANT: Your original email/phone columns now contain ENCRYPTED data!');
        }
    }
    
    protected function isAlreadyEncrypted($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        try {
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                return false;
            }
            
            $data = json_decode($decoded, true);
            return isset($data['iv'], $data['value'], $data['mac']);
        } catch (\Exception $e) {
            return false;
        }
    }
}