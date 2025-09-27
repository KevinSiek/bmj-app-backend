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
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->string('proforma_invoice_number');
            $table->date('proforma_invoice_date');
            $table->decimal('down_payment', 15, 2)->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->boolean('is_dp_paid')->nullable();
            $table->boolean('is_full_paid')->nullable();
            $table->string('total_amount_text')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proforma_invoices');
    }
};
