<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->index(['exam_id', 'status'], 'cbt_attempts_exam_status_index');
            $table->index(['status', 'ends_at'], 'cbt_attempts_status_ends_at_index');
        });

        Schema::table('cbt_results', function (Blueprint $table) {
            $table->index(['passed', 'marked_at'], 'cbt_results_passed_marked_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_attempts', function (Blueprint $table) {
            $table->dropIndex('cbt_attempts_exam_status_index');
            $table->dropIndex('cbt_attempts_status_ends_at_index');
        });

        Schema::table('cbt_results', function (Blueprint $table) {
            $table->dropIndex('cbt_results_passed_marked_at_index');
        });
    }
};
