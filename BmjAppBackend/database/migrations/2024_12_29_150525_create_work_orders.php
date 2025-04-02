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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations');
            $table->string('work_order_number');
            $table->foreignId('received_by')->constrained('employees')->onDelete('cascade')->nullable();
            $table->date('expected_days')->nullable();
            $table->date('expected_start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->foreignId('compiled_by')->constrained('employees')->onDelete('cascade');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('job_descriptions')->nullable();
            $table->string('work_peformed_by')->nullable();
            $table->foreignId('approved_by')->constrained('employees')->onDelete('cascade');
            $table->boolean('is_done')->nullable();
            $table->string('additional_components')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
