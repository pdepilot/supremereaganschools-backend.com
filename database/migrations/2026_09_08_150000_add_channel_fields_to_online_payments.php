<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('online_payments', 'channel')) {
                $table->string('channel', 40)->nullable()->after('status');
            }
            if (! Schema::hasColumn('online_payments', 'gateway_status')) {
                $table->string('gateway_status', 40)->nullable()->after('channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            if (Schema::hasColumn('online_payments', 'gateway_status')) {
                $table->dropColumn('gateway_status');
            }
            if (Schema::hasColumn('online_payments', 'channel')) {
                $table->dropColumn('channel');
            }
        });
    }
};
