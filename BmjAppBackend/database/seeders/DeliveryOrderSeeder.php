<?php

namespace Database\Seeders;

use App\Http\Controllers\QuotationController;
use App\Models\DeliveryOrder;
use App\Models\Quotation;
use Illuminate\Database\Seeder;

class DeliveryOrderSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $quotations = Quotation::where('type', QuotationController::SPAREPARTS)->take(5)->get();

        foreach ($quotations as $quotation) {
            DeliveryOrder::create([
                'quotation_id' => $quotation->id,
                'type' => QuotationController::SPAREPARTS,
                'current_status' => 'Process',
                'notes' => 'Sample delivery order for ' . $quotation->quotation_number,
            ]);
        }
    }
}
