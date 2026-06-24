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
        Schema::table('detail_quotations', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->nullable()->change();
        });

        Schema::table('detail_spareparts', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detail_quotations', function (Blueprint $table) {
            $table->integer('unit_price')->nullable()->change();
        });

        Schema::table('detail_spareparts', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
        });
    }
};
