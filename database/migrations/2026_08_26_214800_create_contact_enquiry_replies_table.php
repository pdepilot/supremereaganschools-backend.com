<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_enquiry_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_enquiry_id')->constrained('contact_enquiries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index('contact_enquiry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiry_replies');
    }
};
