<?php
// database/seeders/BackOrderSeeder.php
namespace Database\Seeders;

use App\Models\BackOrder;
use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class BackOrderSeeder extends Seeder
{
    public function run(): void
    {
        BackOrder::factory(15)->create([
            'id_po' => PurchaseOrder::inRandomOrder()->first()->id,
        ]);
    }
}
