<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Sparepart;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sparepart>
 */
class SparepartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Sparepart::class;

    public function definition()
    {
        return [
            'no_sparepart'  => $this->faker->word,
            'name' => $this->faker->word,
            'unit_price_buy' => $this->faker->randomFloat(2, 100, 1000),
            'unit_price_sell' => $this->faker->randomFloat(2, 200, 2000),
            'total_unit' => $this->faker->numberBetween(10, 1000),
        ];
    }
}
