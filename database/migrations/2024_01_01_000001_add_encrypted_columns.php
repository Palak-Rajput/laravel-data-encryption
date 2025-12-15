<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Only add hash columns for searching - email/phone columns stay as is
            if (Schema::hasColumn('users', 'email')) {
                $table->string('email_hash', 64)->nullable()->index()->after('email');
                $table->string('email_backup', 255)->nullable()->after('email_hash'); // Temporary backup
            }

            if (Schema::hasColumn('users', 'phone')) {
                $table->string('phone_hash', 64)->nullable()->index()->after('phone');
                $table->string('phone_backup', 255)->nullable()->after('phone_hash'); // Temporary backup
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_hash',
                'email_backup',
                'phone_hash',
                'phone_backup',
            ]);
            
            // Note: Cannot automatically decrypt data when rolling back!
            // Users must run decrypt command first
        });
    }
};