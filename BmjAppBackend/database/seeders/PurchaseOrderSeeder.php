<?php
// database/seeders/PurchaseOrderSeeder.php
namespace Database\Seeders;

use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\BackOrder;
use App\Models\DetailBackOrder;
use App\Models\Sparepart;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        // Get approved quotations that don't have a PO yet
        $quotations = Quotation::where('current_status', 'Approved')
            ->whereDoesntHave('purchaseOrder')
            ->get();

        $director = \App\Models\Employee::where('role', 'Director')->first();

        foreach ($quotations as $quotation) {
            // Simulate that not all approved quotations become a PO
            if (rand(1, 10) > 8) continue; // 80% chance to create a PO

            $poDate = Carbon::parse($quotation->updated_at)->addDays(rand(1, 7));
            $hasIndent = false;

            $po = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'purchase_order_number' => 'PO-' . str_replace('QUOT/', '', $quotation->quotation_number),
                'purchase_order_date' => $poDate,
                'payment_due' => $poDate->copy()->addDays(30),
                'employee_id' => $quotation->employee_id,
                'current_status' => 'Prepare', // Default status
                // FIX: Removed 'version' as it's not in the provided migration for the purchase_orders table.
                'notes' => 'Generated from quotation ' . $quotation->quotation_number,
                'created_at' => $poDate,
                'updated_at' => $poDate,
            ]);

            // Update Quotation Status
            $status = $quotation->status;
            $status[] = ['state' => 'Po', 'employee' => $quotation->employee->username, 'timestamp' => $poDate->toIso8601String()];
            $quotation->current_status = 'Po';
            $quotation->status = $status;
            $quotation->save();

            // Handle stock and back orders for 'Spareparts' type
            if ($quotation->type === 'Spareparts') {
                $backOrder = null;
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->is_indent) {
                        $hasIndent = true;
                        if (!$backOrder) {
                            $backOrder = BackOrder::create([
                                'purchase_order_id' => $po->id,
                                'back_order_number' => 'BO-' . $po->id,
                                'current_status' => 'Process',
                                // FIX: Removed 'employee_id' as the back_orders table does not have this column.
                                'created_at' => $poDate,
                                'updated_at' => $poDate,
                            ]);
                        }
                        DetailBackOrder::create([
                            'back_order_id' => $backOrder->id,
                            'sparepart_id' => $detail->sparepart_id,
                            'number_delivery_order' => 0,
                            'number_back_order' => $detail->quantity,
                        ]);
                    } else {
                        // Decrement stock for available items
                        $sparepart = Sparepart::find($detail->sparepart_id);
                        if ($sparepart) {
                            $sparepart->decrement('total_unit', $detail->quantity);
                        }
                    }
                }
                if ($hasIndent) {
                    $po->update(['current_status' => 'BO']);
                }
            }
        }
    }
}
