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
            'no_bo' => fn() => 'PT-' . fake()->unique()->bothify('####-##'),
        ])
        ->create([
            'id_po' => PurchaseOrder::inRandomOrder()->first()->id,
        ]);


    }

}
