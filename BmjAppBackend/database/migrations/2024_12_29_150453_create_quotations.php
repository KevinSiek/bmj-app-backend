<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number');
            $table->string('slug');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('project');
            $table->string('type');
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->decimal('discount', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('ppn', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->text('current_status')->nullable();
            $table->json('status')->nullable(); 
            $table->boolean('review');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
