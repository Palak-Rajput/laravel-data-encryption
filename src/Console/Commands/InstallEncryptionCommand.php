<?php

namespace PalakRajput\DataEncryption\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class InstallEncryptionCommand extends Command
{
    protected $signature = 'data-encryption:install
                            {--auto : Run everything automatically}
                            {--yes : Skip confirmations}
                            {--backup : Create backup columns}';

    protected $description = 'Install and setup Laravel Data Encryption';

    public function handle(): int
    {
        $this->info('🔐 Installing Laravel Data Encryption');
        $this->warn('⚠️ This encrypts data IN-PLACE');

        if (!$this->option('yes') && !$this->confirm('Have you backed up your database?', false)) {
            $this->error('Installation cancelled.');
            return Command::FAILURE;
        }

        // Publish config
        $this->callSilent('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'config',
            '--force' => true,
        ]);

        // Publish migrations
        $this->callSilent('vendor:publish', [
            '--provider' => 'PalakRajput\\DataEncryption\\Providers\\DataEncryptionServiceProvider',
            '--tag' => 'migrations',
            '--force' => true,
        ]);

        $this->setupEnv();

        if ($this->option('auto')) {
            $this->call('migrate');
            $this->autoEncryptUser();
        }

        $this->newLine();
        $this->info('✅ Installation COMPLETE');
        $this->line('Your encryption system is ready.');
        $this->warn('⚠️ Keep ENCRYPTION_KEY safe.');

        return Command::SUCCESS;
    }

    protected function autoEncryptUser(): void
    {
        if (!class_exists(\App\Models\User::class)) {
            $this->warn('User model not found. Skipping auto encryption.');
            return;
        }

        $table = (new \App\Models\User)->getTable();

        if (Schema::hasColumn($table, 'email_hash')) {
            $alreadyEncrypted = DB::table($table)->whereNotNull('email_hash')->exists();

            if ($alreadyEncrypted) {
                $this->warn('🔁 User data already encrypted. Skipping.');
                return;
            }
        }

        $this->info('🔐 Encrypting User data...');

        $this->call('data-encryption:encrypt', [
            'model' => 'App\Models\User',
            '--backup' => $this->option('backup'),
            '--force' => true,
        ]);
    }

    protected function setupEnv(): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            return;
        }

        $env = File::get($envPath);

        if (!str_contains($env, 'ENCRYPTION_KEY=')) {
            File::append($envPath, PHP_EOL . 'ENCRYPTION_KEY=' . env('APP_KEY'));
        }

        if (!str_contains($env, 'HASH_ALGORITHM=')) {
            File::append($envPath, PHP_EOL . 'HASH_ALGORITHM=sha256');
        }
    }
}
