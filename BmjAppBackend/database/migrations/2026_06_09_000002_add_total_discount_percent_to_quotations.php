<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual whole-quotation discount, entered by the user as a percentage of the subtotal.
     * Distinct from the per-item discount captured implicitly in unit prices (and the existing
     * `discount` currency column). Any value > 0 forces the quotation into Director review.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('total_discount_percent', 5, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('total_discount_percent');
        });
    }
};
