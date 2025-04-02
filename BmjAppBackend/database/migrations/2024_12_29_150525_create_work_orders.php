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
            $table->foreignId('received_by')->constrained('employees')->onDelete('cascade');
            $table->date('expected_day');
            $table->date('expected_start_date');
            $table->date('expected_end_date');
            $table->foreignId('compiled_by')->constrained('employees')->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('job_descriptions');
            $table->foreignId('work_peformed_by')->constrained('employees')->onDelete('cascade');
            $table->foreignId('approved_by')->constrained('employees')->onDelete('cascade');
            $table->boolean('is_done');
            $table->string('additional_components');
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
