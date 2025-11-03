<?php
// database/seeders/PurchaseOrderSeeder.php
namespace Database\Seeders;

use App\Models\General;
use Illuminate\Database\Seeder;

class GeneralSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure table is empty before seeding
        General::truncate();

        // Create a single, realistic general settings record
        General::create([
            'discount' => 0.05, // 5% default discount
            'ppn' => 0.11,      // 11% PPN
            'currency_converter' => 15000.00 // Example USD to IDR rate
        ]);
    }
}
