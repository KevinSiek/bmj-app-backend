<?php

namespace Database\Factories;

use App\Http\Controllers\WorkOrderController;
use App\Models\WorkOrder;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        // Generate expected start date
        $expectedStartDate = $this->faker->dateTimeBetween('-2 weeks', '+2 weeks')->format('Y-m-d');
        // Ensure expected end date is after expected start date (1 to 21 days later)
        $expectedEndDate = $this->faker->dateTimeBetween(
            $expectedStartDate . ' +1 day',
            $expectedStartDate . ' +3 weeks'
        )->format('Y-m-d');

        // Generate start date
        $startDate = $this->faker->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');
        // Ensure end date is after start date (1 to 14 days later)
        $endDate = $this->faker->dateTimeBetween(
            $startDate . ' +1 day',
            $startDate . ' +2 weeks'
        )->format('Y-m-d');

        $status = $this->faker->randomElement([
            WorkOrderController::ON_PROGRESS,
            WorkOrderController::SPAREPART_READY,
            WorkOrderController::DONE,
        ]);
        $isDone = $status === WorkOrderController::DONE ? true : $this->faker->boolean;

        return [
            'quotation_id' => Quotation::inRandomOrder()->first()->id ?? Quotation::factory()->create()->id,
            'work_order_number' => 'WO-' . strtoupper($this->faker->unique()->lexify('????')) . '-' . $this->faker->numberBetween(1000, 9999),
            'received_by' => $this->faker->name,
            'expected_days' => $this->faker->numberBetween(1, 30),
            'expected_start_date' => $expectedStartDate,
            'expected_end_date' => $expectedEndDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'current_status' => $status,
            'worker' => implode(', ', $this->faker->randomElements([
                'John Doe',
                'Jane Smith',
                'Michael Brown',
                'Sarah Johnson'
            ], $this->faker->numberBetween(1, 3))),
            'compiled' => $this->faker->name,
            'head_of_service' => $this->faker->name,
            'approver' => $this->faker->name,
            'is_done' => $isDone,
            'spareparts' => implode(', ', $this->faker->randomElements([
                'Oil Filter',
                'Air Filter',
                'Spark Plug',
                'Brake Pad'
            ], $this->faker->numberBetween(1, 3))),
            'backup_sparepart' => implode(', ', $this->faker->randomElements([
                'Fuel Filter',
                'Gasket',
                'Belt'
            ], $this->faker->numberBetween(0, 2))),
            'scope' => $this->faker->randomElement(['Maintenance', 'Repair', 'Overhaul']),
            'vaccine' => $this->faker->randomElement(['Fully Vaccinated', 'Booster', 'None']),
            'apd' => $this->faker->randomElement(['Helmet, Gloves', 'Safety Boots', 'Mask, Goggles']),
            'peduli_lindungi' => $this->faker->randomElement(['Certified', 'Pending', 'None']),
            'executionTime' => '1',
            'notes' => 'notes'
        ];
    }
}
