<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\HashService;

class EncryptDataCommand extends Command
{
    protected $signature = 'data-encryption:encrypt 
                            {model : Model class name}
                            {--fields= : Fields to encrypt (comma-separated)}
                            {--chunk=1000 : Number of records per chunk}
                            {--dry-run : Show what would be encrypted without doing it}';
    
    protected $description = 'Encrypt existing data in database';
    
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
        
        $this->info("🔐 Encrypting data for model: {$modelClass}");
        $this->info("Fields: " . implode(', ', $fields));
        $this->info("Chunk size: {$chunkSize}");
        
        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }
        
        $model = new $modelClass;
        $table = $model->getTable();
        
        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);
        
        $total = DB::table($table)->count();
        $this->info("Total records: {$total}");
        
        $bar = $this->output->createProgressBar($total);
        
        DB::table($table)->orderBy('id')->chunk($chunkSize, function ($records) use (
            $table, $fields, $encryptionService, $hashService, $bar, $dryRun
        ) {
            foreach ($records as $record) {
                $updates = [];
                
                foreach ($fields as $field) {
                    if (isset($record->$field) && !empty($record->$field)) {
                        // Encrypt the value
                        $encrypted = $encryptionService->encrypt($record->$field);
                        $updates["{$field}_encrypted"] = $encrypted;
                        
                        // Create hash
                        $updates["{$field}_hash"] = $hashService->hash($record->$field);
                        
                        // Backup original (optional)
                        $updates["{$field}_original"] = $record->$field;
                    }
                }
                
                if (!empty($updates) && !$dryRun) {
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
            $this->info('✅ Dry run completed. No changes made.');
        } else {
            $this->info('✅ Data encryption completed successfully!');
            $this->info('📊 You can now safely remove original columns after verification.');
        }
    }
}