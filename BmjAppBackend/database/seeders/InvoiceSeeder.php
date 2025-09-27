<?php
// database/seeders/InvoiceSeeder.php
namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\ProformaInvoice;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $proformaInvoices = ProformaInvoice::where('is_dp_paid', true)
            ->whereDoesntHave('invoice')
            ->get();

        foreach ($proformaInvoices as $pi) {
            $invoiceDate = Carbon::parse($pi->updated_at)->addDay();
            Invoice::create([
                'proforma_invoice_id' => $pi->id,
                'invoice_number' => 'IP' . str_replace('PI', '', $pi->proforma_invoice_number),
                'invoice_date' => $invoiceDate,
                'employee_id' => $pi->employee_id,
                'term_of_payment' => fake()->randomElement(['CASH', '30 DAYS']),
                'created_at' => $invoiceDate,
                'updated_at' => $invoiceDate,
            ]);
        }
    }
}
