<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained('fee_types')->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('paid_kobo')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('fee_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
