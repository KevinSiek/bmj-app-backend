<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{WorkOrder, Employee, Quotation};

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
            'quotation_id' => Quotation::inRandomOrder()->first()->id,
            'work_order_number' => Str::random(10),
            'received_by' => Employee::inRandomOrder()->first()->id,
            'expected_days' => $this->faker->date(),
            'expected_start_date' => $this->faker->date(),
            'expected_end_date' => $this->faker->date(),
            'compiled' => Employee::inRandomOrder()->first()->id,
            'start_date' => $this->faker->date(),
            'end_date' => $this->faker->date(),
            'job_descriptions' => $this->faker->sentence,
            'worker' => Employee::inRandomOrder()->first()->id,
            'head_of_service' => Employee::inRandomOrder()->first()->id,
            'approver' => Employee::inRandomOrder()->first()->id,
            'is_done' => $this->faker->boolean,
            'spareparts' => $this->faker->sentence,
            'backup_sparepart' => $this->faker->sentence,
            'scope' => $this->faker->sentence,
            'vaccine' => $this->faker->sentence,
            'apd' => $this->faker->sentence,
            'peduli_lindungi' => $this->faker->sentence,
        ];
    }
}
