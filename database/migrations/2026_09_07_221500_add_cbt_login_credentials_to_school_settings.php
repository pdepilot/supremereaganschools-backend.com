<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->string('cbt_login_email')->nullable()->after('updated_by');
            $table->string('cbt_login_password')->nullable()->after('cbt_login_email');
            $table->foreignId('cbt_operator_user_id')
                ->nullable()
                ->after('cbt_login_password')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cbt_operator_user_id');
            $table->dropColumn(['cbt_login_email', 'cbt_login_password']);
        });
    }
};
