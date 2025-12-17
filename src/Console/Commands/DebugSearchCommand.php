<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PalakRajput\DataEncryption\Services\MeilisearchService;

class DebugSearchCommand extends Command
{
    protected $signature = 'data-encryption:debug-search 
                            {email? : Email to search for}
                            {--test : Test search with sample data}
                            {--model=App\Models\User : Model to debug}';
    
    protected $description = 'Debug search functionality';
    
    public function handle()
    {
        if (!config('data-encryption.meilisearch.enabled', true)) {
            $this->error('Meilisearch is not enabled! Check your config.');
            $this->line('Add to .env: MEILISEARCH_ENABLED=true');
            return;
        }
        
        $modelClass = $this->option('model');
        
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} not found");
            return;
        }
        
        $email = $this->argument('email');
        
        if ($this->option('test')) {
            $this->testSearch($modelClass);
            return;
        }
        
        if ($email) {
            $this->searchEmail($modelClass, $email);
            return;
        }
        
        $this->checkMeilisearchStatus($modelClass);
    }
    
    protected function checkMeilisearchStatus($modelClass)
    {
        $this->info('🔍 Checking Meilisearch status...');
        
        $meilisearch = app(MeilisearchService::class);
        $model = new $modelClass;
        $indexName = $model->getMeilisearchIndexName();
        
        try {
            // Get index stats
            $index = $meilisearch->client->index($indexName);
            $stats = $index->stats();
            
            $this->info("📊 Index: {$indexName}");
            $this->info("📄 Documents: " . ($stats['numberOfDocuments'] ?? 0));
            $this->info("🕒 Last update: " . ($stats['lastUpdate'] ?? 'Never'));
            
            // Check settings
            $settings = $index->getSettings();
            $this->info("\n🔎 Searchable attributes: " . implode(', ', $settings['searchableAttributes'] ?? []));
            $this->info("🎯 Filterable attributes: " . implode(', ', $settings['filterableAttributes'] ?? []));
            $this->info("📈 Sortable attributes: " . implode(', ', $settings['sortableAttributes'] ?? []));
            
            // Test search
            $this->info("\n🧪 Testing search with 'test'...");
            $results = $meilisearch->search($indexName, 'test', ['email_parts', 'name']);
            $this->info("📋 Search results count: " . count($results));
            
            if (!empty($results)) {
                $this->info("\n📝 Sample results:");
                foreach (array_slice($results, 0, 3) as $result) {
                    $this->line("  - ID: {$result['id']}, Name: {$result['name'] ?? 'N/A'}");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            $this->line("💡 Make sure Meilisearch is running at: " . config('data-encryption.meilisearch.host'));
            $this->line("   Run: meilisearch --master-key=your_master_key");
        }
    }
    
    protected function searchEmail($modelClass, $email)
    {
        $this->info("🔍 Searching for: {$email}");
        
        try {
            $users = $modelClass::searchEncrypted($email)->get();
            
            $this->info("✅ Found: " . $users->count() . " users");
            
            if ($users->count() > 0) {
                $this->table(['ID', 'Name', 'Email'], $users->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }));
            }
        } catch (\Exception $e) {
            $this->error("❌ Search error: " . $e->getMessage());
        }
    }
    
    protected function testSearch($modelClass)
    {
        $this->info('🧪 Running search tests...');
        
        $testQueries = [
            'gmail',
            '@gmail',
            'user',
            '.com',
            'example',
            'test',
            'admin',
        ];
        
        foreach ($testQueries as $query) {
            $this->info("\n🔍 Searching: '{$query}'");
            
            try {
                $results = $modelClass::searchEncrypted($query)->get();
                
                $this->info("📋 Results: " . $results->count());
                
                if ($results->count() > 0) {
                    foreach ($results as $user) {
                        $this->line("  ✅ {$user->name} <{$user->email}>");
                    }
                } else {
                    $this->line("  ❌ No results");
                }
            } catch (\Exception $e) {
                $this->error("  ❌ Error: " . $e->getMessage());
            }
        }
    }
}