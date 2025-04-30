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
            $table->foreignId('compiled')->constrained('employees')->onDelete('cascade');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->nullable();
            $table->string('job_descriptions')->nullable();
            $table->string('worker')->nullable();
            $table->string('head_of_service')->nullable();
            $table->foreignId('approver')->constrained('employees')->onDelete('cascade');
            $table->boolean('is_done')->nullable();
            $table->string('spareparts')->nullable();
            $table->string('backup_sparepart')->nullable();
            $table->string('scope')->nullable();
            $table->string('vaccine')->nullable();
            $table->string('apd')->nullable();
            $table->string('peduli_lindungi')->nullable();
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
