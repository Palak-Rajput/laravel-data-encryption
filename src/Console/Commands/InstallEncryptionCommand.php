<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class InstallEncryptionCommand extends Command
{
    protected $signature = 'data-encryption:install 
                            {--models= : Comma-separated list of models to encrypt}
                            {--fields= : Comma-separated list of fields to encrypt}';
    
    protected $description = 'Install the Data Encryption package';
    
    public function handle()
    {
        $this->info('🔐 Installing Laravel Data Encryption Package...');
        
        // Publish config
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'config'
        ]);
        
        // Publish migrations
        $this->call('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'migrations'
        ]);
        
        // Add environment variables
        $this->addEnvironmentVariables();
        
        // Generate encryption key if not exists
        $this->generateEncryptionKey();
        
        // Setup Meilisearch indices
        $this->setupMeilisearch();
        
        $this->info('✅ Data Encryption package installed successfully!');
        $this->showNextSteps();
    }
    
    protected function addEnvironmentVariables()
    {
        $envPath = base_path('.env');
        
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            
            $variables = [
                '# Data Encryption Package',
                'ENCRYPTION_CIPHER=AES-256-CBC',
                'ENCRYPTION_KEY=' . (env('APP_KEY') ?: 'base64:' . base64_encode(random_bytes(32))),
                'HASH_ALGORITHM=sha256',
                'HASH_SALT=laravel-data-encryption-' . uniqid(),
                '# Meilisearch Configuration',
                'MEILISEARCH_HOST=http://localhost:7700',
                'MEILISEARCH_KEY=',
                'MEILISEARCH_INDEX_PREFIX=encrypted_',
            ];
            
            $added = [];
            foreach ($variables as $variable) {
                if (str_starts_with($variable, '#')) {
                    if (!str_contains($envContent, $variable)) {
                        File::append($envPath, PHP_EOL . $variable);
                    }
                } else {
                    $key = explode('=', $variable)[0];
                    if (!str_contains($envContent, $key)) {
                        File::append($envPath, PHP_EOL . $variable);
                        $added[] = $key;
                    }
                }
            }
            
            if (!empty($added)) {
                $this->info('Added environment variables: ' . implode(', ', $added));
            }
        }
    }
    
    protected function generateEncryptionKey()
    {
        if (empty(env('ENCRYPTION_KEY')) && !empty(env('APP_KEY'))) {
            $this->info('Using APP_KEY as encryption key');
        } elseif (empty(env('ENCRYPTION_KEY'))) {
            $this->warn('ENCRYPTION_KEY is not set. Please generate one:');
            $this->line('php artisan key:generate --show');
            $this->line('Then set ENCRYPTION_KEY in your .env file');
        }
    }
    
    protected function setupMeilisearch()
    {
        if ($this->confirm('Do you want to setup Meilisearch for encrypted data search?', true)) {
            $this->info('📊 Setting up Meilisearch...');
            
            // Check if Meilisearch is running
            $host = env('MEILISEARCH_HOST', 'http://localhost:7700');
            
            $this->line("Meilisearch host: {$host}");
            $this->line('Please ensure Meilisearch is running on this host');
            $this->line('Documentation: https://www.meilisearch.com/docs');
        }
    }
    
    protected function showNextSteps()
    {
        $this->newLine();
        $this->info('📝 Next Steps:');
        $this->line('1. Run migrations: php artisan migrate');
        $this->line('2. Add the HasEncryptedFields trait to your models');
        $this->line('3. Configure fields to encrypt in config/data-encryption.php');
        $this->line('4. Encrypt existing data: php artisan data-encryption:encrypt');
        $this->line('5. Start Meilisearch service for searching encrypted data');
        $this->newLine();
        $this->line('Example model setup:');
        $this->line('use PalakRajput\\DataEncryption\\Models\\Trait\\HasEncryptedFields;');
        $this->line('');
        $this->line('class User extends Model {');
        $this->line('    use HasEncryptedFields;');
        $this->line('    ');
        $this->line('    protected static $encryptedFields = [\'email\', \'phone\'];');
        $this->line('    protected static $searchableHashFields = [\'email\', \'phone\'];');
        $this->line('}');
    }
}