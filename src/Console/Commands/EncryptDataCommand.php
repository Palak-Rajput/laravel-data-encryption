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
                            {--backup : Backup original data to *_backup columns}
                            {--force : Skip confirmation prompts}';
    
    protected $description = 'Encrypt existing data in database - ENCRYPTS ORIGINAL COLUMNS';
    
    public function handle()
    {
        $modelClass = $this->argument('model');
        
        if (!class_exists($modelClass)) {
            $this->error("❌ Model {$modelClass} does not exist!");
            return 1;
        }
        
        $fields = $this->option('fields') 
            ? explode(',', $this->option('fields'))
            : ['email', 'phone'];
        
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');
        $force = $this->option('force');
        
        $this->info("🔐 ENCRYPTING DATA IN ORIGINAL COLUMNS for model: {$modelClass}");
        $this->warn("⚠️  WARNING: This will overwrite {$modelClass}::" . implode(', ', $fields) . " columns!");
        $this->info("Fields: " . implode(', ', $fields));
        $this->info("Chunk size: {$chunkSize}");
        
        if ($dryRun) {
            $this->warn('📊 DRY RUN - No changes will be made');
        }
        
        $model = new $modelClass;
        $table = $model->getTable();
        
        // Verify hash columns exist
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            if (!Schema::hasColumn($table, $hashColumn)) {
                $this->error("❌ Column {$hashColumn} does not exist! Run migrations first.");
                $this->line("Run: php artisan migrate");
                return 1;
            }
            
            if ($backup && !Schema::hasColumn($table, $field . '_backup')) {
                $this->warn("⚠️  Column {$field}_backup does not exist for backup!");
                $this->line("Re-run migration with --backup option or skip --backup flag");
                $backup = false;
            }
        }
        
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);
        
        $total = DB::table($table)->count();
        if ($total === 0) {
    $this->warn('⚠️  No records found. Nothing to encrypt.');
    return Command::SUCCESS;
}

        $this->info("Total records to process: {$total}");
        
        // Safety confirmation (unless --force or --dry-run)
        if (!$dryRun && !$force) {
            $this->newLine();
            $this->error("🚨 🚨 🚨  DANGER ZONE  🚨 🚨 🚨");
            $this->line("This operation:");
            $this->line("• Overwrites original {$modelClass}::" . implode(', ', $fields) . " columns");
            $this->line("• Converts plain text to ENCRYPTED data");
            $this->line("• Without encryption key, data is UNRECOVERABLE");
            $this->newLine();
            
            if (!$this->confirm("✅ Have you backed up your database?", false)) {
                $this->error("❌ Operation cancelled. Backup your database first.");
                $this->line("Run: mysqldump -u root -p database_name > backup.sql");
                return 1;
            }
            
            if (!$this->confirm("⚠️  Are you sure you want to encrypt {$total} records?", false)) {
                $this->error("❌ Operation cancelled.");
                return 1;
            }
            
            // Final warning for large datasets
            if ($total > 1000) {
                $this->warn("⚠️  Large dataset detected: {$total} records");
                if (!$this->confirm("🔴 FINAL WARNING: Proceed with encryption?", false)) {
                    $this->error("❌ Operation cancelled.");
                    return 1;
                }
            }
        }
        
        $bar = $this->output->createProgressBar($total);
        $encryptedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        
        DB::table($table)->orderBy('id')->chunk($chunkSize, function ($records) use (
            $table, $fields, $encryptionService, $hashService, $bar, $dryRun, $backup, &$encryptedCount, &$skippedCount, &$errorCount
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
                        try {
                            $updates[$field] = $encryptionService->encrypt($record->$field);
                            
                            // Create hash for searching
                            $updates[$field . '_hash'] = $hashService->hash($record->$field);
                            
                            $shouldUpdate = true;
                            $encryptedCount++;
                        } catch (\Exception $e) {
                            $this->warn("Failed to encrypt {$field} for record {$record->id}: " . $e->getMessage());
                            $errorCount++;
                        }
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
            $this->line("   Would error: {$errorCount} records");
            $this->line("   Total records: {$total}");
            $this->info('✅ Dry run completed. No changes made.');
            
            if ($encryptedCount > 0) {
                $this->line('');
                $this->info('To actually encrypt, run without --dry-run flag:');
                $this->line("php artisan data-encryption:encrypt \"{$modelClass}\" --backup");
            }
        } else {
            $this->info("📊 Encryption completed:");
            $this->line("   ✅ Encrypted: {$encryptedCount} records");
            $this->line("   ⏭️  Already encrypted: {$skippedCount} records");
            $this->line("   ❌ Errors: {$errorCount} records");
            $this->line("   📊 Total processed: {$total}");
            
            if ($encryptedCount > 0) {
                $this->info('🎉 Data encryption successful!');
                
                if ($backup) {
                    $this->info('💾 Original data backed up in *_backup columns');
                    $this->line('To remove backup columns after verification:');
                    foreach ($fields as $field) {
                        $this->line("   php artisan make:migration drop_{$field}_backup_from_{$table}_table");
                    }
                }
                
                // Test that encryption works
                $this->line('');
                $this->info('🧪 Test encryption:');
                $this->line("   php artisan tinker");
                $this->line("   >>> \$user = {$modelClass}::first();");
                $this->line("   >>> echo \$user->email; // Should show decrypted email");
                $this->line("   >>> echo \$user->email_hash; // Should show hash");
            } else {
                $this->warn('⚠️  No records were encrypted (may already be encrypted)');
            }
            
            $this->warn('⚠️  REMEMBER: Your original columns now contain ENCRYPTED data!');
            $this->line('   Keep your ENCRYPTION_KEY safe in .env file');
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