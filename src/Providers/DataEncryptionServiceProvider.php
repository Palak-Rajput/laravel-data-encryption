<?php

namespace PalakRajput\DataEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use PalakRajput\DataEncryption\Services\EncryptionService;
use PalakRajput\DataEncryption\Services\MeilisearchService;
use PalakRajput\DataEncryption\Services\HashService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Contracts\Http\Kernel;
use PalakRajput\DataEncryption\Http\Middleware\InjectDisableConsole;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;

class DataEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Laravel version detection
     */
    private function getLaravelMajorVersion()
    {
        $version = app()->version();
        // Extract major version from string like "Laravel Framework 8.83.27"
        if (preg_match('/(\d+)\./', $version, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Check if running Laravel 8 or higher
     */
    private function isLaravel8OrHigher()
    {
        return $this->getLaravelMajorVersion() >= 8;
    }

    /**
     * Check if running Laravel 9 or higher
     */
    private function isLaravel9OrHigher()
    {
        return $this->getLaravelMajorVersion() >= 9;
    }

    /**
     * Get appropriate Meilisearch client based on Laravel version
     */
    private function getMeilisearchClient()
    {
        $host = config('data-encryption.meilisearch.host', 'http://localhost:7700');
        $key = config('data-encryption.meilisearch.key', '');
        
        // Laravel 6-8: Use older client method
        if ($this->getLaravelMajorVersion() < 9) {
            // For older Laravel versions, we need to be careful with client instantiation
            return new \Meilisearch\Client($host, $key);
        }
        
        // Laravel 9+: Can use newer client
        return new \Meilisearch\Client($host, $key);
    }

    /**
     * Auto-discover all models with email/phone columns
     */
    private function discoverModelsWithSensitiveData(): array
    {
        $models = [];
        
        // Get all Eloquent models from the app
        $modelFiles = $this->getModelFiles();
        
        foreach ($modelFiles as $modelFile) {
            $modelClass = $this->getModelClassFromFile($modelFile);
            
            if ($modelClass && class_exists($modelClass)) {
                try {
                    $model = new $modelClass();
                    
                    if ($model instanceof Model) {
                        $table = $model->getTable();
                        
                        // Check if table has sensitive columns
                        if (Schema::hasTable($table)) {
                            $columns = Schema::getColumnListing($table);
                            
                            $hasSensitiveData = false;
                            $sensitiveFields = [];
                            
                            foreach ($columns as $column) {
                                $columnLower = strtolower($column);
                                
                                // Check for email fields
                                if (str_contains($columnLower, 'email') || 
                                    preg_match('/^(email|e_mail|mail)$/i', $column)) {
                                    $hasSensitiveData = true;
                                    $sensitiveFields[] = $column;
                                }
                                
                                // Check for phone fields
                                if (str_contains($columnLower, 'phone') || 
                                    str_contains($columnLower, 'mobile') ||
                                    str_contains($columnLower, 'tel') ||
                                    preg_match('/^(phone|mobile|tel|telephone|contact_number)$/i', $column)) {
                                    $hasSensitiveData = true;
                                    $sensitiveFields[] = $column;
                                }
                            }
                            
                            if ($hasSensitiveData) {
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
        
        return $models;
    }
    
    /**
     * Get all model files from app/Models directory
     */
    private function getModelFiles(): array
    {
        $modelsPath = app_path('Models');
        $modelFiles = [];
        
        if (file_exists($modelsPath)) {
            $modelFiles = glob($modelsPath . '/*.php');
        }
        
        // Also check for older Laravel structure (app/User.php)
        if ($this->getLaravelMajorVersion() < 8) {
            $userModelPath = app_path('User.php');
            if (file_exists($userModelPath)) {
                $modelFiles[] = $userModelPath;
            }
        }
        
        return $modelFiles;
    }
    
    /**
     * Get model class name from file path
     */
    private function getModelClassFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace
        preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch);
        $namespace = $namespaceMatch[1] ?? 'App\\Models';
        
        // Extract class name
        preg_match('/class\s+(\w+)/', $content, $classMatch);
        $className = $classMatch[1] ?? null;
        
        if ($className) {
            return $namespace . '\\' . $className;
        }
        
        return null;
    }

    public function register()
    {
        // Ensure config exists before merging
        $configPath = __DIR__.'/../../config/data-encryption.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'data-encryption');
        } else {
            // Fallback config for older Laravel versions
            $this->app['config']->set('data-encryption', $this->getDefaultConfig());
        }

        // Auto-discover and configure models
        $this->autoConfigureAllModels();

        // Bind services with version compatibility
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService($app['config']->get('data-encryption', []));
        });

        $this->app->singleton(MeilisearchService::class, function ($app) {
            $config = $app['config']->get('data-encryption', []);
            
            // Inject compatible Meilisearch client
            $service = new MeilisearchService($config);
            
            // Use reflection to set the client if needed
            if ($this->getLaravelMajorVersion() < 9) {
                try {
                    $reflection = new \ReflectionClass($service);
                    $clientProperty = $reflection->getProperty('client');
                    $clientProperty->setAccessible(true);
                    $clientProperty->setValue($service, $this->getMeilisearchClient());
                } catch (\Exception $e) {
                    // Fallback - service will create its own client
                }
            }
            
            return $service;
        });

        $this->app->singleton(HashService::class, function ($app) {
            return new HashService($app['config']->get('data-encryption', []));
        });

        // Alias for easier access
        $this->app->alias(EncryptionService::class, 'encryption.service');
        $this->app->alias(MeilisearchService::class, 'meilisearch.service');
        $this->app->alias(HashService::class, 'hash.service');
    }

    public function boot()
    {
        // Middleware registration with version check
        if (!$this->app->runningInConsole()) {
            try {
                $kernel = $this->app->make(Kernel::class);
                
                // Check if middleware can be pushed (Laravel 5.1+)
                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(InjectDisableConsole::class);
                }
            } catch (\Exception $e) {
                // Silently fail for older Laravel versions
            }
        }
        
        // Publish config and migrations
        $this->publishes([
            __DIR__.'/../../config/data-encryption.php' => config_path('data-encryption.php'),
        ], 'config');

        // Publish migrations with version-aware naming
        $this->publishMigrations();

        // Load views if they exist
        if (file_exists(__DIR__.'/../../resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../../resources/views', 'data-encryption');
        }

        // Blade directive only if Blade facade exists (Laravel 5.0+)
        if (class_exists('Illuminate\Support\Facades\Blade') && 
            method_exists('Illuminate\Support\Facades\Blade', 'directive')) {
            Blade::directive('disableConsole', function () {
                return "<?php echo view('data-encryption::disable-console')->render(); ?>";
            });
        }

        // Register package Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \PalakRajput\DataEncryption\Console\Commands\InstallEncryptionCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\EncryptDataCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\ReindexMeilisearch::class,
                \PalakRajput\DataEncryption\Console\Commands\DebugSearchCommand::class,
                \PalakRajput\DataEncryption\Console\Commands\DiscoverModelsCommand::class, // New command
            ]);
        }

        // Auto-configure models on boot as well (in case config was cleared)
        $this->autoConfigureAllModels();
    }

    /**
     * Auto-configure all models with sensitive data
     */
    private function autoConfigureAllModels()
    {
        // Only auto-configure if enabled in config
        if (!config('data-encryption.auto_discover', true)) {
            return;
        }

        $discoveredModels = $this->discoverModelsWithSensitiveData();
        
        if (!empty($discoveredModels)) {
            $currentConfig = config('data-encryption.encrypted_fields', []);
            $currentSearchableConfig = config('data-encryption.searchable_fields', []);
            
            // Merge discovered models with existing config
            foreach ($discoveredModels as $modelClass => $fields) {
                if (!isset($currentConfig[$modelClass])) {
                    $currentConfig[$modelClass] = $fields;
                    
                    // Make email and phone fields searchable by default
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
            
            // Update config
            config([
                'data-encryption.encrypted_fields' => $currentConfig,
                'data-encryption.searchable_fields' => $currentSearchableConfig,
            ]);
        }
    }

    /**
     * Publish migrations with compatibility
     */
    /**
 * Publish migrations with compatibility
 */
/**
 * Publish migrations with compatibility
 */
private function publishMigrations()
{
    // Get the vendor path where your package is installed
    $vendorPath = base_path('vendor/palak-rajput/laravel-data-encryption');
    
    // Check if the package exists in vendor
    if (!file_exists($vendorPath)) {
        // Alternative: Use relative path from the provider
        $vendorPath = dirname(__DIR__, 2); // Go up from src/Providers to package root
    }
    
    $migrationsPath = $vendorPath . '/database/migrations';
    
    // If migrations directory doesn't exist in the package, create it
    if (!file_exists($migrationsPath)) {
        if (!mkdir($migrationsPath, 0755, true) && !is_dir($migrationsPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $migrationsPath));
        }
        
        // Create the migration file
        $this->createMigrationFile($migrationsPath);
    }
    
    // Check if the specific migration file exists
    $migrationFile = $migrationsPath . '/add_hash_columns_to_all_tables.php';
    if (!file_exists($migrationFile)) {
        $this->createMigrationFile($migrationsPath);
    }
    
    $timestamp = date('Y_m_d_His');
    
    $this->publishes([
        $migrationFile => database_path("migrations/{$timestamp}_add_hash_columns_to_all_tables.php"),
    ], 'migrations');
}

/**
 * Create migration file in the specified directory
 */
private function createMigrationFile(string $migrationsPath): void
{
    $migrationContent = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddHashColumnsToAllTables extends Migration
{
    /**
     * Check if a column name indicates sensitive data
     */
    private function isSensitiveColumn(string $columnName): bool
    {
        $columnLower = strtolower($columnName);
        
        $sensitivePatterns = [
            'email', 'phone', 'mobile', 'telephone',
            'ssn', 'social_security', 'tax_id',
            'credit_card', 'card_number',
            'passport', 'driver_license',
        ];
        
        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($columnLower, $pattern)) {
                return true;
            }
        }
        
        // Special patterns
        if (preg_match('/^(email|e_mail|mail)$/i', $columnName)) {
            return true;
        }
        
        if (preg_match('/^(phone|mobile|tel|telephone|contact_number)$/i', $columnName)) {
            return true;
        }
        
        return false;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $connection = DB::getDefaultConnection();
        
        // Get all tables (method varies by database driver)
        if ($connection === 'mysql') {
            $tables = DB::select('SHOW TABLES');
            $tables = array_map(function($table) {
                return array_values((array)$table)[0];
            }, $tables);
        } elseif ($connection === 'pgsql') {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tables = array_map(function($table) {
                return $table->tablename;
            }, $tables);
        } elseif ($connection === 'sqlite') {
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = array_map(function($table) {
                return $table->name;
            }, $tables);
        } else {
            $tables = [];
        }
        
        foreach ($tables as $tableName) {
            // Skip migrations table and other system tables
            if (in_array($tableName, ['migrations', 'password_reset_tokens', 'failed_jobs', 'personal_access_tokens', 'password_resets'])) {
                continue;
            }
            
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Get column list for this table
                $columns = Schema::getColumnListing($tableName);
                
                foreach ($columns as $column) {
                    if ($this->isSensitiveColumn($column)) {
                        $hashColumn = $column . '_hash';
                        
                        // Add hash column if it doesn't exist
                        if (!Schema::hasColumn($tableName, $hashColumn)) {
                            $table->string($hashColumn, 64)->nullable()->index();
                        }
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $connection = DB::getDefaultConnection();
        
        if ($connection === 'mysql') {
            $tables = DB::select('SHOW TABLES');
            $tables = array_map(function($table) {
                return array_values((array)$table)[0];
            }, $tables);
        } elseif ($connection === 'pgsql') {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tables = array_map(function($table) {
                return $table->tablename;
            }, $tables);
        } elseif ($connection === 'sqlite') {
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = array_map(function($table) {
                return $table->name;
            }, $tables);
        } else {
            $tables = [];
        }
        
        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Get column list for this table
                $columns = Schema::getColumnListing($tableName);
                
                foreach ($columns as $column) {
                    $hashColumn = $column . '_hash';
                    
                    // Remove hash column if it exists
                    if (Schema::hasColumn($tableName, $hashColumn)) {
                        $table->dropColumn($hashColumn);
                    }
                }
            });
        }
    }
}
PHP;

    $migrationFile = $migrationsPath . '/add_hash_columns_to_all_tables.php';
    file_put_contents($migrationFile, $migrationContent);
}

    /**
     * Default configuration for older Laravel versions
     */
    private function getDefaultConfig()
    {
        $laravelVersion = $this->getLaravelMajorVersion();
        $userModel = $laravelVersion >= 8 ? 'App\Models\User' : 'App\User';
        
        // Auto-discover models for default config
        $discoveredModels = $this->discoverModelsWithSensitiveData();
        
        // If no models discovered, use User as default
        if (empty($discoveredModels)) {
            $discoveredModels = [
                $userModel => ['email', 'phone']
            ];
        }
        
        // Build searchable fields config
        $searchableFields = [];
        foreach ($discoveredModels as $modelClass => $fields) {
            $searchable = [];
            foreach ($fields as $field) {
                $fieldLower = strtolower($field);
                if (str_contains($fieldLower, 'email') || 
                    str_contains($fieldLower, 'phone') ||
                    str_contains($fieldLower, 'mobile')) {
                    $searchable[] = $field;
                }
            }
            if (!empty($searchable)) {
                $searchableFields[$modelClass] = $searchable;
            }
        }
        
        return [
            'encryption' => [
                'cipher' => 'AES-256-CBC',
                'key' => env('ENCRYPTION_KEY', env('APP_KEY')),
            ],
            'encrypted_fields' => $discoveredModels,
            'searchable_fields' => $searchableFields,
            'hashing' => [
                'algorithm' => 'sha256',
                'salt' => 'laravel-data-encryption',
            ],
            'meilisearch' => [
                'enabled' => true,
                'host' => 'http://localhost:7700',
                'key' => '',
                'index_prefix' => 'encrypted_',
                'index_settings' => [
                    'searchableAttributes' => ['email_parts', 'name', 'model_type'],
                    'filterableAttributes' => ['email_hash', 'phone_hash', 'model_type'],
                    'sortableAttributes' => ['created_at', 'name'],
                    'typoTolerance' => ['enabled' => true],
                ],
            ],
            'partial_search' => [
                'enabled' => true,
                'min_part_length' => 3,
                'email_separators' => ['@', '.', '-', '_', '+'],
            ],
            'auto_discover' => true, // Enable auto-discovery of models
            'sensitive_patterns' => [
                'email', 'phone', 'mobile', 'telephone',
                'ssn', 'social_security', 'tax_id',
                'credit_card', 'card_number',
                'passport', 'driver_license',
                'address', 'street', 'city', 'zip', 'postal_code',
                'dob', 'date_of_birth', 'birth_date',
                'national_id', 'identity_number'
            ],
        ];
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides()
    {
        return [
            EncryptionService::class,
            MeilisearchService::class,
            HashService::class,
            'encryption.service',
            'meilisearch.service',
            'hash.service',
        ];
    }
}