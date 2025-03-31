<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{WorkOrder,Employee, Quotation};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sparepart>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = WorkOrder::class;

    public function definition()
    {
        return [
            'id_quotation' => Quotation::inRandomOrder()->first()->id,
            'no_wo' => Str::random(10),
            'received_by' => Employee::inRandomOrder()->first()->id,
            'expected_day' => $this->faker->date(),
            'expected_start_date' => $this->faker->date(),
            'expected_end_date' => $this->faker->date(),
            'compiled_by' => Employee::inRandomOrder()->first()->id,
            'start_date' => $this->faker->date(),
            'end_date' => $this->faker->date(),
            'job_descriptions' => $this->faker->sentence,
            'work_peformed_by' => Employee::inRandomOrder()->first()->id,
            'approved_by' => Employee::inRandomOrder()->first()->id,
            'is_done' => $this->faker->boolean,
            'additional_components' => $this->faker->sentence,
        ];
    }
}
