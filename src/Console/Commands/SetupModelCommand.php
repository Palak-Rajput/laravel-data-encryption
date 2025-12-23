<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SetupModelCommand extends Command
{
    protected $signature = 'data-encryption:setup-model 
                            {model : Model class to setup (e.g., App\Models\Customer)}
                            {--fields= : Comma-separated fields to encrypt}
                            {--force : Skip confirmation prompts}';
    
    protected $description = 'Setup encryption for a specific model';
    
    public function handle()
    {
        $modelClass = $this->argument('model');
        
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} not found");
            return;
        }
        
        $this->info("🔧 Setting up encryption for {$modelClass}");
        
        // Get fields from option or detect
        $fieldsInput = $this->option('fields');
        
        if ($fieldsInput) {
            $fields = array_map('trim', explode(',', $fieldsInput));
        } else {
            $model = new $modelClass;
            $table = $model->getTable();
            
            if (!Schema::hasTable($table)) {
                $this->error("Table {$table} does not exist");
                return;
            }
            
            // Detect sensitive fields
            $fields = $this->detectSensitiveFields($table);
            
            if (empty($fields)) {
                $this->error("No sensitive fields detected in {$table}");
                $this->line("Specify fields with --fields option");
                return;
            }
            
            $this->info("Detected fields: " . implode(', ', $fields));
        }
        
        // Create migration for hash columns
        $this->createMigrationForModel($modelClass, $fields);
        
        // Add trait to model
        $this->addEncryptionTraitToModel($modelClass, $fields);
        
        $this->info("\n✅ Model setup complete!");
        $this->line("Next steps:");
        $this->line("1. Run migration: php artisan migrate");
        $this->line("2. Encrypt data: php artisan data-encryption:encrypt \"{$modelClass}\" --force");
    }
    
    protected function detectSensitiveFields($table)
    {
        $columns = Schema::getColumnListing($table);
        
        $sensitivePatterns = [
            'email', 'phone', 'mobile', 'telephone',
            'ssn', 'social_security',
            'credit_card', 'card_number',
            'address', 'street', 'city', 'zip',
            'dob', 'birth_date',
            'passport', 'license'
        ];
        
        $detectedFields = [];
        
        foreach ($columns as $column) {
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($column, $pattern) !== false) {
                    $detectedFields[] = $column;
                    break;
                }
            }
        }
        
        return array_unique($detectedFields);
    }
    
    protected function createMigrationForModel($modelClass, $fields)
    {
        $model = new $modelClass;
        $table = $model->getTable();
        
        $timestamp = date('Y_m_d_His');
        $migrationName = "add_hash_columns_to_{$table}_table";
        $migrationFile = database_path("migrations/{$timestamp}_{$migrationName}.php");
        
        $fieldsCode = '';
        $dropCode = '';
        
        foreach ($fields as $field) {
            $hashColumn = $field . '_hash';
            $fieldsCode .= "
            if (Schema::hasColumn('{$table}', '{$field}') && !Schema::hasColumn('{$table}', '{$hashColumn}')) {
                \$table->string('{$hashColumn}', 64)->nullable()->index()->after('{$field}');
            }";
            
            $dropCode .= "
            if (Schema::hasColumn('{$table}', '{$hashColumn}')) {
                \$table->dropColumn('{$hashColumn}');
            }";
        }
        
        $content = '<?php

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
        Schema::table(\'' . $table . '\', function (Blueprint $table) {' . $dropCode . '
        });
    }
};';

        File::put($migrationFile, $content);
        $this->info("✅ Migration created: {$migrationFile}");
    }
    
    protected function addEncryptionTraitToModel($modelClass, $fields)
    {
        $modelPath = $this->getModelPath($modelClass);
        
        if (!File::exists($modelPath)) {
            $this->error("Model file not found: {$modelPath}");
            return;
        }
        
        $content = File::get($modelPath);
        
        // Add trait import
        if (!str_contains($content, 'use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;')) {
            $content = preg_replace(
                '/^(namespace [^;]+;)/m',
                "$1\n\nuse PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;",
                $content
            );
        }
        
        // Add trait usage
        if (!preg_match('/use\s+HasEncryptedFields\s*;/', $content)) {
            if (preg_match('/(class\s+\w+\s+extends[^{]+{)/', $content, $matches)) {
                $classStart = $matches[1];
                $content = str_replace(
                    $classStart,
                    $classStart . "\n    use HasEncryptedFields;",
                    $content
                );
            }
        }
        
        // Add properties
        $fieldsArray = $this->formatArray($fields);
        
        if (!str_contains($content, 'protected static $encryptedFields')) {
            if (preg_match('/(class\s+\w+\s+extends[^{]+{)/', $content, $matches)) {
                $classStart = $matches[1];
                $propertyCode = "\n    protected static \$encryptedFields = {$fieldsArray};\n" .
                               "    protected static \$searchableHashFields = {$fieldsArray};";
                
                $content = str_replace(
                    $classStart,
                    $classStart . $propertyCode,
                    $content
                );
            }
        }
        
        File::put($modelPath, $content);
        $this->info("✅ Added encryption trait and properties to {$modelClass}");
    }
    
    protected function getModelPath($modelClass)
    {
        $relativePath = str_replace('App\\', '', $modelClass);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        return app_path($relativePath . '.php');
    }
    
    protected function formatArray($fields)
    {
        $quoted = array_map(function($field) {
            return "'" . addslashes($field) . "'";
        }, $fields);
        
        return "[" . implode(', ', $quoted) . "]";
    }
}