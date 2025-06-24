<?php

namespace Database\Seeders;

use App\Models\DetailSparepart;
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
        ])->each(function ($sparepart) {
            // Create DetailSparepart records for each sparepart with random sellers
            $sparepart->detailSpareparts()->saveMany(DetailSparepart::factory()->count(3)->make([
                'sparepart_id' => $sparepart->id,
            ]));
        });
    }
}
