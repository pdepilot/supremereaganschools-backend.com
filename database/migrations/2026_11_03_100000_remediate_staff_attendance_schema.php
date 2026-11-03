<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table) {
            // Add clock_out_campus_id for authorized cross-campus clock-out
            $table->foreignId('clock_out_campus_id')
                ->nullable()
                ->after('campus_id')
                ->constrained('campuses')
                ->restrictOnDelete();

            // Replace non-unique index with unique index to prevent duplicate records per staff per day
            $table->unique(['staff_profile_id', 'date'], 'sar_staff_date_unique');
            $table->dropIndex(['staff_profile_id', 'date']);

            // Harden foreign key against accidental historical data loss
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropForeign(['campus_id']);
                $table->foreign('campus_id')->references('id')->on('campuses')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table) {
            $table->index(['staff_profile_id', 'date']);
            $table->dropUnique('sar_staff_date_unique');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropForeign(['campus_id']);
                $table->foreign('campus_id')->references('id')->on('campuses')->cascadeOnDelete();
            }

            $table->dropConstrainedForeignId('clock_out_campus_id');
        });
    }
};

