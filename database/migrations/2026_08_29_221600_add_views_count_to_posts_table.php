<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts') || Schema::hasColumn('posts', 'views_count')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedInteger('views_count')->default(0)->after('reading_time');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts') || ! Schema::hasColumn('posts', 'views_count')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('views_count');
        });
    }
};
