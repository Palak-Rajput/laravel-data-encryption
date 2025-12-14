<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // This is a template migration - users will modify it for their tables
        Schema::table('users', function (Blueprint $table) {
            // Encrypted columns
            $table->text('email_encrypted')->nullable()->after('email');
            // $table->text('phone_encrypted')->nullable()->after('phone');
            
            // Hash columns for searching
            $table->string('email_hash', 64)->nullable()->index()->after('email_encrypted');
            // $table->string('phone_hash', 64)->nullable()->index()->after('phone_encrypted');
            
            // Optional: Original columns backup (can be removed after verification)
            $table->string('email_original')->nullable()->after('email_hash');
            // $table->string('phone_original')->nullable()->after('phone_hash');
        });
    }
    
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_encrypted',
                // 'phone_encrypted',
                'email_hash',
                // 'phone_hash',
                'email_original',
                // 'phone_original',
            ]);
        });
    }
};