<?php
// database/seeders/ProformaInvoiceSeeder.php
namespace Database\Seeders;

use App\Models\ProformaInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema; // Import Schema facade

class ProformaInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        // FIX: Disable foreign key checks before truncating to avoid constraint violations.
        Schema::disableForeignKeyConstraints();
        ProformaInvoice::truncate();
        Schema::enableForeignKeyConstraints();

        $purchaseOrders = PurchaseOrder::whereDoesntHave('proformaInvoice')->get();

        foreach ($purchaseOrders as $po) {
            // Not every PO immediately becomes a PI
            if (rand(1, 10) > 9) continue;

            $piDate = Carbon::parse($po->created_at)->addDays(rand(1, 5));
            $grandTotal = $po->quotation->grand_total;
            $downPayment = $grandTotal * 0.3; // 30% DP

            $pi = ProformaInvoice::create([
                'purchase_order_id' => $po->id,
                'proforma_invoice_number' => 'PI-' . $po->purchase_order_number,
                'proforma_invoice_date' => $piDate,
                'down_payment' => $downPayment,
                'grand_total' => $grandTotal,
                'total_amount' => $grandTotal, // Assuming total amount is grand total for now
                'is_dp_paid' => false,
                'is_full_paid' => false,
                // FIX: Replaced NumberFormatter with a static placeholder to avoid dependency on the 'intl' PHP extension.
                'total_amount_text' => 'Jumlah Terbilang Sesuai Grand Total',
                'employee_id' => $po->employee_id,
                'notes' => 'Proforma Invoice for ' . $po->purchase_order_number,
                'created_at' => $piDate,
                'updated_at' => $piDate,
            ]);

            // Simulate DP payment for some older PIs
            if (Carbon::parse($pi->created_at)->diffInDays(now()) > 7) {
                if (rand(1, 10) <= 8) { // 80% chance DP is paid
                    $paymentDate = $piDate->copy()->addDays(rand(2, 7));
                    $pi->update([
                        'is_dp_paid' => true,
                        'updated_at' => $paymentDate
                    ]);
                }
            }

            // Simulate Full payment for very old PIs that are fulfilled
            if (Carbon::parse($pi->created_at)->diffInDays(now()) > 30 && $pi->is_dp_paid && in_array($po->current_status, ['Done', 'Release'])) {
                if (rand(1, 10) <= 7) { // 70% chance of full payment
                    $paymentDate = Carbon::parse($pi->updated_at)->addDays(rand(15, 30));
                    $pi->update([
                        'is_full_paid' => true,
                        'updated_at' => $paymentDate
                    ]);
                }
            }
        }
    }
}
