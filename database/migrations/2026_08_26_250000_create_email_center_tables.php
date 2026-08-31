<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('audience', 30)->nullable();
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->text('body');
            $table->boolean('is_system')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outbound_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->string('subject');
            $table->string('audience', 30);
            $table->text('body');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->json('recipients')->nullable();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mails');
        Schema::dropIfExists('email_templates');
    }
};
