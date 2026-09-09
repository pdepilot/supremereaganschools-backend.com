<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_sync_logs', function (Blueprint $table) {
            $table->string('event_id', 64)->nullable()->after('id');
            $table->string('protocol', 40)->nullable()->after('direction');
            $table->string('batch_id', 64)->nullable()->after('protocol');

            $table->unique('event_id', 'cbt_sync_logs_event_id_unique');
            $table->index(['batch_id', 'created_at'], 'cbt_sync_logs_batch_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_sync_logs', function (Blueprint $table) {
            $table->dropUnique('cbt_sync_logs_event_id_unique');
            $table->dropIndex('cbt_sync_logs_batch_created_index');
            $table->dropColumn(['event_id', 'protocol', 'batch_id']);
        });
    }
};
