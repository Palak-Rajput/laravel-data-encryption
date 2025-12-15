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

    protected $description = 'Encrypt existing data IN-PLACE in original columns';

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (!class_exists($modelClass)) {
            $this->error("❌ Model {$modelClass} does not exist.");
            return Command::FAILURE;
        }

        $fields = $this->option('fields')
            ? array_map('trim', explode(',', $this->option('fields')))
            : ['email', 'phone'];

        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');
        $backup = $this->option('backup');
        $force = $this->option('force');

        $model = new $modelClass;
        $table = $model->getTable();

        $this->info("🔐 Encrypting data for model: {$modelClass}");
        $this->warn("⚠️  This ENCRYPTS ORIGINAL columns: " . implode(', ', $fields));

        // Validate required columns
        foreach ($fields as $field) {
            if (!Schema::hasColumn($table, $field . '_hash')) {
                $this->error("❌ Missing column {$field}_hash. Run migrations first.");
                return Command::FAILURE;
            }

            if ($backup && !Schema::hasColumn($table, "{$field}_backup")) {
                $this->warn("⚠️  Backup column {$field}_backup missing. Backup disabled.");
                $backup = false;
            }
        }

        $total = DB::table($table)->count();

        if ($total === 0) {
            $this->warn('⚠️  No records found. Nothing to encrypt.');
            return Command::SUCCESS;
        }

        if (!$dryRun && !$force) {
            $this->newLine();
            $this->error('🚨 DANGER ZONE');
            $this->line('• This overwrites original data');
            $this->line('• Without ENCRYPTION_KEY data is unrecoverable');

            if (!$this->confirm('Have you backed up your database?', false)) {
                $this->error('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        $encryptionService = app(EncryptionService::class);
        $hashService = app(HashService::class);

        $encrypted = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($total);

        DB::table($table)
            ->orderBy('id')
            ->chunk($chunkSize, function ($records) use (
                $table,
                $fields,
                $encryptionService,
                $hashService,
                $dryRun,
                $backup,
                &$encrypted,
                &$skipped,
                &$errors,
                $bar
            ) {
                foreach ($records as $record) {
                    $updates = [];
                    $skipRecord = false;

                    foreach ($fields as $field) {
                        if (empty($record->$field)) {
                            continue;
                        }

                        if ($this->isAlreadyEncrypted($record->$field)) {
                            $skipped++;
                            $skipRecord = true;
                            break;
                        }

                        try {
                            if ($backup) {
                                $updates["{$field}_backup"] = $record->$field;
                            }

                            $updates[$field] = $encryptionService->encrypt($record->$field);
                            $updates["{$field}_hash"] = $hashService->hash($record->$field);

                        } catch (\Throwable $e) {
                            $errors++;
                            $skipRecord = true;
                            break;
                        }
                    }

                    if (!$skipRecord && !$dryRun && !empty($updates)) {
                        DB::table($table)->where('id', $record->id)->update($updates);
                        $encrypted++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info('📊 Encryption summary');
        $this->line("   ✅ Encrypted: {$encrypted}");
        $this->line("   ⏭️  Skipped: {$skipped}");
        $this->line("   ❌ Errors: {$errors}");
        $this->line("   📦 Total: {$total}");

        if ($backup && $encrypted > 0) {
            $this->info('💾 Backup columns populated (*_backup)');
        }

        $this->warn('⚠️ Original columns now contain encrypted data.');
        $this->line('Keep your ENCRYPTION_KEY safe.');

        return Command::SUCCESS;
    }

    protected function isAlreadyEncrypted($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);
        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }
}
