<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{Good,Buy,DetailBuy};

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
            'id_buy' => Buy::factory(),
            'id_goods' => Good::factory(),
            'quantity' => $this->faker->numberBetween(1, 100),
        ];
    }
}
