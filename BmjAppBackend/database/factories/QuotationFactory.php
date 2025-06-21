<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{Quotation, Customer, Employee};
use Illuminate\Support\Arr;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Quotation::class;

    public function definition()
    {
        $project = $this->faker->sentence;

        return [
            'quotation_number' => Str::random(10),
            'version' => 1, // Set default version to 1
            'slug' => Str::slug($project) . '-' . Str::random(6), // Generate a unique slug
            'customer_id' => Customer::factory(),
            'project' => $project,
            'type' => $this->faker->sentence,
            'date' => $this->faker->dateTimeBetween('2025-03-01', '2025-05-31'),
            'amount' => $this->faker->randomFloat(2, 1000, 10000),
            'discount' => $this->faker->randomFloat(2, 100, 500),
            'subtotal' => $this->faker->randomFloat(2, 5000, 9000),
            'ppn' => $this->faker->randomFloat(2, 500, 2000),
            'grand_total' => $this->faker->randomFloat(2, 6000, 12000),
            'notes' => $this->faker->sentence,
            'employee_id' => Employee::factory(),
            'current_status' => Arr::random(['Process', 'On Review', 'PO', 'Cancelled', 'Revised']),
            'status' => [
                [
                    'state' => 'Po',
                    'timestamp' => now()->format('d/m/Y'),
                    'employee' => "Director Name"
                ],
                [
                    'state' => 'Pi',
                    'timestamp' => now()->format('d/m/Y'),
                    'employee' => "PI Name"
                ],
                [
                    'state' => 'Inventory',
                    'timestamp' => now()->format('d/m/Y'),
                    'employee' => "Inventory Name"
                ],
                [
                    'state' => 'Paid',
                    'timestamp' => now()->format('d/m/Y'),
                    'employee' => "PI Name"
                ],
                [
                    'state' => 'Done',
                    'timestamp' => now()->format('d/m/Y'),
                    'employee' => "Done Name"
                ]
            ],
            'review' => $this->faker->boolean(),
            'is_return' => $this->faker->boolean(),
        ];
    }
}
