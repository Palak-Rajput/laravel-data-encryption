<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {

            if (Schema::hasColumn('users', 'email')) {
                $table->text('email_encrypted')->nullable();
                $table->string('email_hash', 64)->nullable()->index();
                $table->string('email_original')->nullable();
            }

            if (Schema::hasColumn('users', 'phone')) {
                $table->text('phone_encrypted')->nullable();
                $table->string('phone_hash', 64)->nullable()->index();
                $table->string('phone_original')->nullable();
            }

        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_encrypted',
                'email_hash',
                'email_original',
                'phone_encrypted',
                'phone_hash',
                'phone_original',
            ]);
        });
    }
};
