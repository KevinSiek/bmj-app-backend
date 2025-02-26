<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{BackOrder,Buy};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Buy>
 */
class BuyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Buy::class;

    public function definition()
    {
        return [
            'id_bo' => BackOrder::inRandomOrder()->first()->id,
            'no_buy' => Str::random(10),
            'total_amount' => $this->faker->randomFloat(2, 1000, 10000),
            'review' => $this->faker->boolean,
            'note' => $this->faker->sentence,
        ];
    }
}
