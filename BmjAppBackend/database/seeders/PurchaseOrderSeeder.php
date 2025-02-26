<?php
// database/seeders/PurchaseOrderSeeder.php
namespace Database\Seeders;

use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        PurchaseOrder::factory(25)->create([
            'po_number' => fn() => 'PO-' . fake()->unique()->bothify('??/##/####'),
        ]);
    }
}
