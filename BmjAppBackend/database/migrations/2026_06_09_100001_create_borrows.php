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
        Schema::create('borrows', function (Blueprint $table) {
            $table->id();
            $table->string('borrow_number')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('sparepart_po_id')->nullable()->constrained('purchase_orders');
            $table->string('current_status');
            $table->json('status')->nullable();
            $table->string('notes')->nullable();
            $table->string('return_notes')->nullable();
            $table->string('reject_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrows');
    }
};
