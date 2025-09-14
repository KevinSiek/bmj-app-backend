<?php

namespace Database\Seeders;

use App\Models\WorkOrder;
use App\Models\WoUnit;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WorkOrderSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        // Find Service POs that have a PI with DP paid and no Work Order yet
        $purchaseOrders = PurchaseOrder::where('current_status', '!=', 'BO')
            ->whereHas('quotation', fn ($q) => $q->where('type', 'Service'))
            ->whereHas('proformaInvoice', fn ($q) => $q->where('is_dp_paid', true))
            ->whereDoesntHave('workOrder')
            ->get();

        $serviceEmployees = \App\Models\Employee::where('role', 'Service')->get();
        $headOfService = $serviceEmployees->first();
        $director = \App\Models\Employee::where('role', 'Director')->first();

        foreach ($purchaseOrders as $po) {
            $releaseDate = Carbon::parse($po->proformaInvoice->updated_at)->addDays(rand(1, 4));

            $wo = WorkOrder::create([
                'purchase_order_id' => $po->id,
                'work_order_number' => 'WO/' . str_replace('PO-IN/', '', $po->purchase_order_number),
                'received_by' => $po->quotation->customer->company_name,
                'start_date' => $releaseDate,
                'end_date' => $releaseDate->copy()->addDays(rand(3, 10)),
                'current_status' => 'On Progress',
                'worker' => $serviceEmployees->random()->fullname,
                'compiled' => $po->employee->fullname,
                'head_of_service' => $headOfService->fullname,
                'approver' => $director->fullname,
                'is_done' => false,
                'created_at' => $releaseDate,
                'updated_at' => $releaseDate,
            ]);

            // Create WO Units based on Quotation details
            foreach ($po->quotation->detailQuotations as $detail) {
                WoUnit::create([
                    'id_wo' => $wo->id,
                    'job_descriptions' => $detail->service,
                    'unit_type' => 'Unit',
                    'quantity' => $detail->quantity,
                ]);
            }

            // Mark older Work Orders as Done
            if (Carbon::parse($wo->end_date)->isPast()) {
                 $wo->update(['is_done' => true, 'current_status' => 'Done']);
                 $po->update(['current_status' => 'Done']);
                 // Also update quotation status
                 $quotation = $po->quotation;
                 $status = $quotation->status;
                 $status[] = ['state' => 'Done', 'employee' => 'System', 'timestamp' => $wo->end_date];
                 $quotation->update(['current_status' => 'Done', 'status' => $status]);
            }
        }
    }
}
