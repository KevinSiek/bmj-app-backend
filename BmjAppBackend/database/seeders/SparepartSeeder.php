<?php

namespace Database\Seeders;

use App\Models\Sparepart;
use Illuminate\Database\Seeder;

class SparepartSeeder extends Seeder
{
    public function run(): void
    {
        Sparepart::factory(50)->create([
            'sparepart_number' => fn() => 'SPR-' . fake()->unique()->numberBetween(1000, 9999),
            'unit_price_sell' => fake()->numberBetween(1200000, 60000000),
            'total_unit' => fake()->numberBetween(0, 100),
        ]);
    }
}
