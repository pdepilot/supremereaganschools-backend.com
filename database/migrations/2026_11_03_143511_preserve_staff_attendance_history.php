<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite is used by the automated test suite.
        // MySQL/MariaDB receives the actual historical FK protections.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('staff_campus_assignments', function (Blueprint $table) {
            $table->dropForeign('staff_campus_assignments_staff_profile_id_foreign');
            $table->dropForeign('staff_campus_assignments_campus_id_foreign');
            $table->dropForeign('staff_campus_assignments_assigned_by_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->foreign('campus_id')
                ->references('id')->on('campuses')->restrictOnDelete();

            $table->foreign('assigned_by')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('staff_attendance_records', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_records_staff_profile_id_foreign');
            $table->dropForeign('staff_attendance_records_verified_by_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->foreign('verified_by')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('staff_attendance_verification_logs', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_verification_logs_staff_profile_id_foreign');
            $table->dropForeign('staff_attendance_verification_logs_campus_id_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->foreign('campus_id')
                ->references('id')->on('campuses')->restrictOnDelete();
        });

        Schema::table('staff_attendance_adjustments', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_adjustments_staff_attendance_record_id_foreign');
            $table->dropForeign('staff_attendance_adjustments_requested_by_foreign');
            $table->dropForeign('staff_attendance_adjustments_reviewed_by_foreign');

            $table->foreign('staff_attendance_record_id')
                ->references('id')->on('staff_attendance_records')->restrictOnDelete();

            $table->foreign('requested_by')
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('staff_campus_assignments', function (Blueprint $table) {
            $table->dropForeign('staff_campus_assignments_staff_profile_id_foreign');
            $table->dropForeign('staff_campus_assignments_campus_id_foreign');
            $table->dropForeign('staff_campus_assignments_assigned_by_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->cascadeOnDelete();

            $table->foreign('campus_id')
                ->references('id')->on('campuses')->cascadeOnDelete();

            $table->foreign('assigned_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('staff_attendance_records', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_records_staff_profile_id_foreign');
            $table->dropForeign('staff_attendance_records_verified_by_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->cascadeOnDelete();

            $table->foreign('verified_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('staff_attendance_verification_logs', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_verification_logs_staff_profile_id_foreign');
            $table->dropForeign('staff_attendance_verification_logs_campus_id_foreign');

            $table->foreign('staff_profile_id')
                ->references('id')->on('staff_profiles')->cascadeOnDelete();

            $table->foreign('campus_id')
                ->references('id')->on('campuses')->nullOnDelete();
        });

        Schema::table('staff_attendance_adjustments', function (Blueprint $table) {
            $table->dropForeign('staff_attendance_adjustments_staff_attendance_record_id_foreign');
            $table->dropForeign('staff_attendance_adjustments_requested_by_foreign');
            $table->dropForeign('staff_attendance_adjustments_reviewed_by_foreign');

            $table->foreign('staff_attendance_record_id')
                ->references('id')->on('staff_attendance_records')->cascadeOnDelete();

            $table->foreign('requested_by')
                ->references('id')->on('users')->cascadeOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')->on('users')->nullOnDelete();
        });
    }
};
