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
        Schema::create('detail_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations');
            $table->foreignId('sparepart_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('is_indent')->nullable();
            $table->boolean('is_return')->nullable();
            $table->string('service')->nullable();
            $table->decimal('service_price', 15, 2)->nullable();
            $table->integer('unit_price')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detail_quotations');
    }
};
