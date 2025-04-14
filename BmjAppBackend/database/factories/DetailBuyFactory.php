<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{Sparepart, Buy, DetailBuy};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DetailBuy>
 */
class DetailBuyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = DetailBuy::class;

    public function definition()
    {
        return [
            'buy_id' => Buy::factory(),
            'sparepart_id' => Sparepart::factory(),
            'quantity' => $this->faker->numberBetween(1, 100),
            'seller' => $this->faker->company,
            'unit_price' => $this->faker->randomFloat(2, 100, 1000),
        ];
    }
}
