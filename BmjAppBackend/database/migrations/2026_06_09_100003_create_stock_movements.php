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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sparepart_id')->constrained('spareparts');
            $table->foreignId('branch_id')->constrained('branches');
            // Signed: + for an increase, − for a decrease.
            $table->integer('delta');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->timestamps();

            // Per-part history is read newest-first.
            $table->index(['sparepart_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
