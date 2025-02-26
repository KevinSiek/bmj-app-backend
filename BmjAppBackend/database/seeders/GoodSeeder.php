<?php
namespace Database\Seeders;

use App\Models\Good;
use Illuminate\Database\Seeder;

class GoodSeeder extends Seeder
{
    public function run(): void
    {
        Good::factory(50)->create([
            'no_sparepart' => fn() => 'SPR-' . fake()->unique()->numberBetween(1000, 9999),
            'unit_price_buy' => fake()->numberBetween(1000000, 50000000),
            'unit_price_sell' => fn(array $attr) => $attr['unit_price_buy'] * 1.2,
            'total_unit' => fake()->numberBetween(0, 100),
        ]);
    }
}
