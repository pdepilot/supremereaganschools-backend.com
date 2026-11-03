<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('id');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('geofence_type', 20)->default('circle')->after('longitude');
            $table->unsignedInteger('radius_meters')->default(100)->after('geofence_type');
            $table->json('boundary_coordinates')->nullable()->after('radius_meters');
            $table->unsignedInteger('gps_accuracy_threshold')->default(50)->after('boundary_coordinates');
            $table->time('operating_hours_start')->default('06:30:00')->after('gps_accuracy_threshold');
            $table->time('operating_hours_end')->default('18:30:00')->after('operating_hours_start');
            $table->time('expected_clock_in_time')->default('07:45:00')->after('operating_hours_end');
            $table->time('expected_clock_out_time')->default('16:00:00')->after('expected_clock_in_time');
            $table->unsignedInteger('grace_period_minutes')->default(15)->after('expected_clock_out_time');
            $table->json('working_days')->nullable()->after('grace_period_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'latitude',
                'longitude',
                'geofence_type',
                'radius_meters',
                'boundary_coordinates',
                'gps_accuracy_threshold',
                'operating_hours_start',
                'operating_hours_end',
                'expected_clock_in_time',
                'expected_clock_out_time',
                'grace_period_minutes',
                'working_days',
            ]);
        });
    }
};

