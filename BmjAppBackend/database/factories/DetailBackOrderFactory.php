<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{BackOrder, Sparepart, DetailBackOrder};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DetailBuy>
 */
class DetailBackOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = DetailBackOrder::class;

    public function definition()
    {
        return [
            'back_order_id' => BackOrder::factory(),
            'sparepart_id' => Sparepart::factory(),
            'number_delivery_order' => $this->faker->numberBetween(1, 10),
            'number_back_order'=>$this->faker->numberBetween(1, 10),
        ];
    }
}
