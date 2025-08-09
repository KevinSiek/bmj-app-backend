<?php

namespace Database\Seeders;

use App\Http\Controllers\QuotationController;
use App\Models\DeliveryOrder;
use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class DeliveryOrderSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $purchaseOrders = PurchaseOrder::whereHas('quotation', function ($query) {
            $query->where('type', QuotationController::SPAREPARTS);
        })->take(5)->get();

        foreach ($purchaseOrders as $index => $purchaseOrder) {
            DeliveryOrder::create([
                'purchase_order_id' => $purchaseOrder->id,
                'type' => QuotationController::SPAREPARTS,
                'current_status' => 'Process',
                'notes' => 'Sample delivery order for ' . $purchaseOrder->quotation->quotation_number,
                'delivery_order_number' => 'DO-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'delivery_order_date' => now()->subDays($index)->toDateString(),
                'received_by' => 'Receiver ' . ($index + 1),
                'prepared_by' => 'Preparer ' . ($index + 1),
                'picked_by' => 'Picker ' . ($index + 1),
                'ship_mode' => 'Ground',
                'order_type' => 'Standard',
                'delivery' => 'Express',
                'npwp' => 'NPWP' . str_pad($index + 1, 6, '0', STR_PAD_LEFT),
            ]);
        }
    }
}
