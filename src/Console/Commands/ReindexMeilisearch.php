<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PalakRajput\DataEncryption\Services\MeilisearchService;

class ReindexMeilisearch extends Command
{
    protected $signature = 'data-encryption:reindex 
                        {--model= : Model to reindex (default: App\Models\User)}
                        {--chunk=1000 : Chunk size}
                        {--force : Skip confirmation}';
    
    protected $description = 'Reindex existing encrypted data to Meilisearch for partial search';
    
    public function handle()
    {
        $modelClass = $this->option('model') ?? 'App\Models\User';
        
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} not found");
            return;
        }
        
        // Check if model uses the trait
        $traits = class_uses(new $modelClass);
        $traitName = 'PalakRajput\DataEncryption\Models\Trait\HasEncryptedFields';
        
        if (!in_array($traitName, $traits)) {
            $this->error("Model {$modelClass} does not use HasEncryptedFields trait");
            return;
        }
        
        if (!$this->option('force')) {
            $this->warn('⚠️  This will reindex all records to Meilisearch.');
            if (!$this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Reindexing cancelled.');
                return;
            }
        }
        
        $this->info("🔍 Reindexing {$modelClass} to Meilisearch...");
        
        $model = new $modelClass;
        $total = $model->count();
        
        if ($total === 0) {
            $this->info("No records found.");
            return;
        }
        
        $this->info("Processing {$total} records...");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $model->chunk($this->option('chunk'), function ($records) use ($bar, $modelClass) {
            foreach ($records as $record) {
                try {
                    $record->indexToMeilisearch();
                } catch (\Exception $e) {
                    Log::error('Failed to index record to Meilisearch', [
                        'id' => $record->id,
                        'error' => $e->getMessage()
                    ]);
                }
                $bar->advance();
            }
        });
        
        $bar->finish();
        $this->newLine();
        
        // Initialize Meilisearch index with proper settings
        if (config('data-encryption.meilisearch.enabled', true)) {
            $this->info("🔧 Configuring Meilisearch for partial search...");
            $meilisearch = app(MeilisearchService::class);
            $indexName = $model->getMeilisearchIndexName();
            
            if ($meilisearch->initializeIndex($indexName)) {
                $this->info("✅ Meilisearch index '{$indexName}' configured!");
                sleep(2); // Wait for settings to apply
            } else {
                $this->error("❌ Failed to configure Meilisearch index");
            }
        }
        
        $this->info("✅ {$total} records reindexed to Meilisearch!");
        $this->info("📖 Partial search is now enabled for: gmail, user, @domain, etc.");
        $this->info("💡 Try searching for: 'gmail', 'user', '@example.com', 'test'");
    }
}