<?php

namespace Database\Factories;

use App\Http\Controllers\PurchaseOrderController;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{Quotation, PurchaseOrder, Employee};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = PurchaseOrder::class;

    public function definition()
    {
        $purchaseOrderDate = $this->faker->date();
        return [
            'quotation_id' => Quotation::inRandomOrder()->first()->id,
            'purchase_order_number' => Str::random(10),
            'purchase_order_date' => $this->faker->dateTimeBetween('2025-03-01', '2025-05-31'),
            'payment_due' => $this->faker->dateTimeBetween($purchaseOrderDate, '+30 days')->format('Y-m-d'),
            'employee_id' => Employee::inRandomOrder()->first()->id,
            'current_status' => fake()->randomElement([
                PurchaseOrderController::PREPARE,
                PurchaseOrderController::READY,
                PurchaseOrderController::RELEASE,
            ]),
            'notes' => $this->faker->sentence()
        ];
    }
}
