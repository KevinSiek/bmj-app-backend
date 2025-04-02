<?php
// database/seeders/BackOrderSeeder.php
namespace Database\Seeders;

use App\Models\BackOrder;
use App\Models\DetailBackOrder;
use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class BackOrderSeeder extends Seeder
{
    public function run(): void
    {
        BackOrder::factory(15)
        ->has(DetailBackOrder::factory()->count(5), 'detailBackOrders')
        ->state([
            'back_order_number' => fn() => 'PT-' . fake()->unique()->bothify('####-##'),
        ])
        ->create([
            'purchase_order_id' => PurchaseOrder::inRandomOrder()->first()->id,
        ]);


    }

}
