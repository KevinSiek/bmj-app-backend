<?php

namespace Database\Factories;

use App\Http\Controllers\BuyController;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{Buy};

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
            'buy_number' => Str::random(10),
            'total_amount' => $this->faker->randomFloat(2, 1000, 10000),
            'review' => $this->faker->boolean,
            'current_status' => $this->faker->randomElement([
                BuyController::APPROVE,
                BuyController::WAIT_REVIEW,
                BuyController::DECLINE,
                BuyController::DONE,
            ]),
            'notes' => $this->faker->sentence,
        ];
    }
}
