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
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->string('current_status')->default('Process');
            $table->text('notes')->nullable();
            $table->string('delivery_order_number')->nullable();
            $table->string('delivery_order_date')->nullable();
            $table->string('received_by')->nullable();
            $table->string('picked_by')->nullable();
            $table->string('ship_mode')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery')->nullable();
            $table->string('npwp')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
