<?php
// database/seeders/InvoiceSeeder.php
namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\ProformaInvoice;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        Invoice::factory(15)->create([
            'id_pi' => ProformaInvoice::inRandomOrder()->first()->id,
            'invoice_number' => fn() => 'INV-' . fake()->unique()->bothify('####-##-####'),
            'term_of_pay' => fake()->randomElement(['CASH', '30 DAYS', '60 DAYS', 'DP 50%'])
        ]);
    }
}
