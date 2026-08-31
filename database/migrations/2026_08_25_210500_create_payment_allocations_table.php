<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_item_id'], 'payment_allocations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
