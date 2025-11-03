<?php

namespace Database\Seeders;

use App\Models\DeliveryOrder;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DeliveryOrderSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        // Find Sparepart POs that are ready and don't have a Delivery Order
        $purchaseOrders = PurchaseOrder::whereIn('current_status', ['Prepare', 'Ready'])
            ->whereHas('quotation', fn ($q) => $q->where('type', 'Spareparts'))
            ->whereDoesntHave('deliveryOrder')
            ->get();

        $inventoryEmployees = \App\Models\Employee::where('role', 'Inventory')->get();

        foreach ($purchaseOrders as $po) {
             $releaseDate = Carbon::parse($po->updated_at)->addDays(rand(1, 3));

            $do = DeliveryOrder::create([
                'purchase_order_id' => $po->id,
                'type' => 'Spareparts',
                'current_status' => 'On Progress',
                'notes' => 'Delivery for PO ' . $po->purchase_order_number,
                'delivery_order_number' => 'DO-' . $po->id,
                'delivery_order_date' => $releaseDate,
                'received_by' => 'Customer Reception',
                'prepared_by' => $inventoryEmployees->random()->fullname,
                'picked_by' => $inventoryEmployees->random()->fullname,
                'ship_mode' => fake()->randomElement(['Truck', 'Courier', 'Air Freight']),
                'order_type' => 'Standard',
                'created_at' => $releaseDate,
                'updated_at' => $releaseDate,
            ]);

            // Mark older Delivery Orders as Done
            if (Carbon::parse($do->delivery_order_date)->diffInDays(now()) > 14) {
                 $doneDate = $do->delivery_order_date->copy()->addDays(rand(3, 7));
                 $do->update(['current_status' => 'Done', 'updated_at' => $doneDate]);
                 $po->update(['current_status' => 'Done', 'updated_at' => $doneDate]);

                 // Also update quotation status
                 $quotation = $po->quotation;
                 $status = $quotation->status;
                 $status[] = ['state' => 'Done', 'employee' => 'System', 'timestamp' => $doneDate->toIso8601String()];
                 $quotation->update(['current_status' => 'Done', 'status' => $status]);
            }
        }
    }
}
