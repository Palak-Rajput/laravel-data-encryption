<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddHashColumnsToAllTables extends Migration
{
    /**
     * List of tables to check for sensitive columns
     */
    private function getTablesToProcess(): array
    {
        return Schema::getAllTables();
    }
    
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
        // Get database connection
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
            // Skip migrations table
            if ($tableName === 'migrations' || $tableName === 'password_reset_tokens' || 
                $tableName === 'failed_jobs' || $tableName === 'personal_access_tokens') {
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
        // Get all tables
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