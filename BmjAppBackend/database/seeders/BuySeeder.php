<?php
// database/seeders/BuySeeder.php
namespace Database\Seeders;

use App\Models\Buy;
use App\Models\BackOrder;
use App\Models\DetailBuy;
use Illuminate\Database\Seeder;

class BuySeeder extends Seeder
{
    public function run(): void
    {
        Buy::factory(15)
            ->has(DetailBuy::factory()->count(5), 'detailBuys')
            ->state([
                'no_buy' => fn() => 'BUY-' . fake()->unique()->bothify('####-##'),
                'review' => fake()->boolean(70)
            ])
            ->create([
                'id_bo' => BackOrder::inRandomOrder()->first()->id,
                'note' => fake()->randomElement([
                    'Order for customer x1',
                    'Order for customer x2',
                    'Order for customer x3',
                    'Order for customer x4',
                ]),
            ]);
    }
}
