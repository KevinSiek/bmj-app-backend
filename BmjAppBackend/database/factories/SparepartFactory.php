<?php

namespace Database\Factories;

use App\Http\Controllers\EmployeeController;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Sparepart;
use Illuminate\Support\Str;

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
        $name = $this->faker->word;
        return [
            'sparepart_number' => $this->faker->unique()->word,
            'branch' => $this->faker->randomElement([EmployeeController::SEMARANG, EmployeeController::JAKARTA]),
            'sparepart_name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'unit_price_buy' => $this->faker->randomFloat(2, 200, 2000),
            'unit_price_sell' => $this->faker->randomFloat(2, 200, 2000),
            'total_unit' => $this->faker->numberBetween(10, 1000),
        ];
    }
}
