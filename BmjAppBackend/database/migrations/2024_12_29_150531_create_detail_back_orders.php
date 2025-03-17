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
        Schema::create('detail_back_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_bo')->constrained('back_orders');
            $table->foreignId('id_spareparts')->constrained('spareparts');
            $table->string('number_delivery_order');
            $table->string('number_back_order');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detail_back_orders');
    }
};
