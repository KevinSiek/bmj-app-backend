<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{Sparepart, Seller, DetailSparepart};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DetailSparepart>
 */
class DetailSparepartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = DetailSparepart::class;

    public function definition()
    {
        return [
            'sparepart_id' => Sparepart::factory(), // Will be overridden by seeder if provided
            'seller_id' => Seller::factory(),
            'unit_price' => $this->faker->randomFloat(2, 100, 1000),
            'quantity' => $this->faker->numberBetween(1, 100),
        ];
    }
}
