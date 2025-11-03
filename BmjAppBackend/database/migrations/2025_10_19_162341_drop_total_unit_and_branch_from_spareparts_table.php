<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $branchCodeMap = DB::table('branches')
            ->select('id', 'name', 'code')
            ->get()
            ->flatMap(function ($branch) {
                return [
                    strtolower($branch->name) => $branch->id,
                    strtolower($branch->code) => $branch->id,
                ];
            });

        $spareparts = DB::table('spareparts')->select('id', 'total_unit', 'branch')->get();
        foreach ($spareparts as $sparepart) {
            $branchKey = strtolower($sparepart->branch ?? '');
            $branchId = $branchCodeMap[$branchKey] ?? $branchCodeMap['semarang'] ?? null;

            if ($branchId) {
                DB::table('branch_spareparts')->updateOrInsert(
                    [
                        'sparepart_id' => $sparepart->id,
                        'branch_id' => $branchId,
                    ],
                    [
                        'quantity' => $sparepart->total_unit ?? 0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        Schema::table('spareparts', function (Blueprint $table) {
            if (Schema::hasColumn('spareparts', 'total_unit')) {
                $table->dropColumn('total_unit');
            }

            if (Schema::hasColumn('spareparts', 'branch')) {
                $table->dropColumn('branch');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spareparts', function (Blueprint $table) {
            if (!Schema::hasColumn('spareparts', 'total_unit')) {
                $table->integer('total_unit')->nullable()->after('unit_price_sell');
            }

            if (!Schema::hasColumn('spareparts', 'branch')) {
                $table->string('branch')->nullable()->after('total_unit');
            }
        });
    }
};
