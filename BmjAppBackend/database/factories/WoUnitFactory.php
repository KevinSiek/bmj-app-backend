<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{WoUnit, WorkOrder};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WoUnit>
 */
class WoUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = WoUnit::class;

    public function definition()
    {
        return [
            'id_wo' => WorkOrder::inRandomOrder()->first()->id,
            'job_description' => $this->faker->sentence,
            'unit_type' => $this->faker->randomElement(['Type A', 'Type B', 'Type C', '']),
            'quantity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
