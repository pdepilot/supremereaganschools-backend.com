<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('term_summaries', function (Blueprint $table) {
            $table->text('class_teacher_comment')->nullable()->after('class_size');
            $table->text('principal_comment')->nullable()->after('class_teacher_comment');
            $table->foreignId('class_teacher_commented_by')->nullable()->after('principal_comment')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('principal_commented_by')->nullable()->after('class_teacher_commented_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('term_summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_teacher_commented_by');
            $table->dropConstrainedForeignId('principal_commented_by');
            $table->dropColumn(['class_teacher_comment', 'principal_comment']);
        });
    }
};
