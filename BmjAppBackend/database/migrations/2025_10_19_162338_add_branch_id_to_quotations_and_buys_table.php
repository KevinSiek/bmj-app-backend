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
        Schema::table('quotations', function (Blueprint $table) {
            if (!Schema::hasColumn('quotations', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('employee_id')
                    ->constrained('branches')
                    ->nullOnDelete();
            }
        });

        Schema::table('buys', function (Blueprint $table) {
            if (!Schema::hasColumn('buys', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('back_order_id')
                    ->constrained('branches')
                    ->nullOnDelete();
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'branch_id')) {
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
        });

        Schema::table('buys', function (Blueprint $table) {
            if (Schema::hasColumn('buys', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'branch_id')) {
                $table->dropForeign(['branch_id']);
            }
        });
    }
};
