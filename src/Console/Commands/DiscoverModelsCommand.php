<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;

class DiscoverModelsCommand extends Command
{
    protected $signature = 'data-encryption:discover-models 
                           {--show : Show discovered models without saving}
                           {--force : Force rediscovery even if models already configured}';
    
    protected $description = 'Discover all models with email or phone columns';
    
    public function handle()
    {
        $this->info('🔍 Discovering models with sensitive data...');
        
        $models = $this->discoverModels();
        
        if (empty($models)) {
            $this->warn('No models with email or phone columns found.');
            return;
        }
        
        $this->info('📋 Found ' . count($models) . ' models with sensitive data:');
        
        $tableData = [];
        foreach ($models as $modelClass => $fields) {
            $tableData[] = [
                'Model' => $modelClass,
                'Fields' => implode(', ', $fields),
                'Table' => $this->getTableForModel($modelClass),
            ];
        }
        
        $this->table(['Model', 'Fields', 'Table'], $tableData);
        
        if (!$this->option('show')) {
            if ($this->confirm('Update configuration with these models?', true)) {
                $this->updateConfiguration($models);
                $this->info('✅ Configuration updated!');
            }
        }
        
        $this->info("\n💡 Next steps:");
        $this->line('1. Add HasEncryptedFields trait to discovered models');
        $this->line('2. Run migrations: php artisan migrate');
        $this->line('3. Encrypt existing data: php artisan data-encryption:encrypt');
    }
    
    private function discoverModels(): array
    {
        $models = [];
        
        // Look for models in app/Models directory
        $modelsPath = app_path('Models');
        
        if (file_exists($modelsPath)) {
            $modelFiles = glob($modelsPath . '/*.php');
            
            foreach ($modelFiles as $modelFile) {
                $modelClass = $this->getModelClassFromFile($modelFile);
                
                if ($modelClass && class_exists($modelClass)) {
                    try {
                        $model = new $modelClass();
                        
                        if ($model instanceof Model) {
                            $table = $model->getTable();
                            
                            if (Schema::hasTable($table)) {
                                $columns = Schema::getColumnListing($table);
                                $sensitiveFields = [];
                                
                                foreach ($columns as $column) {
                                    $columnLower = strtolower($column);
                                    
                                    // Check for email fields
                                    if (str_contains($columnLower, 'email') || 
                                        preg_match('/^(email|e_mail|mail)$/i', $column)) {
                                        $sensitiveFields[] = $column;
                                    }
                                    
                                    // Check for phone fields
                                    if (str_contains($columnLower, 'phone') || 
                                        str_contains($columnLower, 'mobile') ||
                                        str_contains($columnLower, 'tel') ||
                                        preg_match('/^(phone|mobile|tel|telephone|contact_number)$/i', $column)) {
                                        $sensitiveFields[] = $column;
                                    }
                                }
                                
                                if (!empty($sensitiveFields)) {
                                    $models[$modelClass] = array_unique($sensitiveFields);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip models that can't be instantiated
                        continue;
                    }
                }
            }
        }
        
        return $models;
    }
    
    private function getModelClassFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch);
        $namespace = $namespaceMatch[1] ?? 'App\\Models';
        
        preg_match('/class\s+(\w+)/', $content, $classMatch);
        $className = $classMatch[1] ?? null;
        
        if ($className) {
            return $namespace . '\\' . $className;
        }
        
        return null;
    }
    
    private function getTableForModel(string $modelClass): string
    {
        try {
            $model = new $modelClass();
            return $model->getTable();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
    
    private function updateConfiguration(array $models)
    {
        $currentConfig = config('data-encryption.encrypted_fields', []);
        $currentSearchableConfig = config('data-encryption.searchable_fields', []);
        
        // Only update if force option is used or model not already configured
        foreach ($models as $modelClass => $fields) {
            if ($this->option('force') || !isset($currentConfig[$modelClass])) {
                $currentConfig[$modelClass] = $fields;
                
                // Make email and phone fields searchable
                $searchableFields = [];
                foreach ($fields as $field) {
                    $fieldLower = strtolower($field);
                    if (str_contains($fieldLower, 'email') || 
                        str_contains($fieldLower, 'phone') ||
                        str_contains($fieldLower, 'mobile')) {
                        $searchableFields[] = $field;
                    }
                }
                
                if (!empty($searchableFields)) {
                    $currentSearchableConfig[$modelClass] = $searchableFields;
                }
            }
        }
        
        // Update config file
        $configPath = config_path('data-encryption.php');
        
        if (file_exists($configPath)) {
            $config = require $configPath;
            $config['encrypted_fields'] = $currentConfig;
            $config['searchable_fields'] = $currentSearchableConfig;
            
            file_put_contents(
                $configPath,
                '<?php return ' . var_export($config, true) . ';'
            );
        }
    }
}