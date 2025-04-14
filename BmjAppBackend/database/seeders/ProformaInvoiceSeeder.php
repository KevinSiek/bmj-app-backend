<?php
// database/seeders/ProformaInvoiceSeeder.php
namespace Database\Seeders;

use App\Models\ProformaInvoice;
use Illuminate\Database\Seeder;
use NumberFormatter;

class ProformaInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        ProformaInvoice::factory(20)->create([
            'pi_number' => fn() => sprintf(
                '%03d/BMJ-PI/%s/%d',
                fake()->numberBetween(1, 999),
                strtoupper(fake()->monthName()),
                now()->year
            ),
            'advance_payment' => fn(array $attr) => $attr['grand_total'] * 0.3,
            'total_amount_text' => 'Empat Belas Juta Delapan Ratus Delapan Puluh Tujuh Ribu Seratus Sembilan Puluh Delapan Rupiah' // Hardcoded value
        ]);
    }
}
