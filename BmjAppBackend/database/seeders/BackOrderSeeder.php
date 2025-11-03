<?php
// database/seeders/BackOrderSeeder.php
namespace Database\Seeders;

use App\Models\BackOrder;
use App\Models\Buy;
use App\Models\DetailBuy;
use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Branch;
use App\Services\SparepartStockService;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class BackOrderSeeder extends Seeder
{
    public function run(): void
    {
        // This seeder now processes existing back orders instead of creating new ones
        $backOrdersToProcess = BackOrder::where('current_status', 'Process')->get();
        $stockService = app('App\Services\SparepartStockService');

        foreach ($backOrdersToProcess as $backOrder) {
            // Simulate a delay for processing
            if (Carbon::parse($backOrder->created_at)->diffInDays(now()) < 10) {
                continue; // Only process older back orders
            }

            $branchModel = Branch::find(optional($backOrder->purchaseOrder?->quotation)->branch_id) ?? Branch::query()
                ->where('name', optional($backOrder->purchaseOrder?->quotation?->employee)->branch)
                ->orWhere('code', optional($backOrder->purchaseOrder?->quotation?->employee)->branch)
                ->first();
            $branchId = $branchModel?->id;

            $processDate = Carbon::parse($backOrder->created_at)->addDays(rand(7, 14));
            $totalBuyAmount = 0;

            // Create a Buy record for this BackOrder
            $buy = Buy::create([
                'back_order_id' => $backOrder->id,
                'buy_number' => 'BUY-' . $backOrder->id,
                'current_status' => 'Wait for Review',
                'review' => false,
                // FIX: Add 'total_amount' with a default value to satisfy the NOT NULL constraint.
                'total_amount' => 0,
                'notes' => 'Auto-purchase for ' . $backOrder->back_order_number,
                'created_at' => $processDate,
                'updated_at' => $processDate,
                'branch_id' => $branchId,
            ]);

            foreach ($backOrder->detailBackOrders as $detail) {
                // Find the cheapest seller for this sparepart
                $cheapest = DetailSparepart::where('sparepart_id', $detail->sparepart_id)
                    ->orderBy('unit_price', 'asc')
                    ->first();

                $buyPrice = $cheapest ? $cheapest->unit_price : 100000; // Fallback price

                DetailBuy::create([
                    'buy_id' => $buy->id,
                    'sparepart_id' => $detail->sparepart_id,
                    'quantity' => $detail->number_back_order,
                    'unit_price' => $buyPrice,
                ]);

                $totalBuyAmount += $detail->number_back_order * $buyPrice;

                // Replenish stock
                $sparepart = Sparepart::find($detail->sparepart_id);
                if ($sparepart && $branchId) {
                    $stockService->increase($sparepart, $branchId, (int) $detail->number_back_order);
                }
            }

            // Update totals and statuses
            $buy->update(['total_amount' => $totalBuyAmount]);
            $backOrder->update(['current_status' => 'Ready', 'updated_at' => $processDate]);
            $backOrder->purchaseOrder()->update(['current_status' => 'Prepare', 'updated_at' => $processDate]);
        }
    }
}
