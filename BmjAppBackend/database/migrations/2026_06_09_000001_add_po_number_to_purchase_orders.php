<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user-entered real Purchase Order number, captured at moveToPo. Distinct from
     * purchase_order_number, which is the auto-generated "Internal Request" number. Nullable
     * so the column can be added to existing rows; new POs always set it (required + unique
     * at the moveToPo boundary). Unique so two POs can't share the same real PO number.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('po_number')->nullable()->unique()->after('purchase_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['po_number']);
            $table->dropColumn('po_number');
        });
    }
};
