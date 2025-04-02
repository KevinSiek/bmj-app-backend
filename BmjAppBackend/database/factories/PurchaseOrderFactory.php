<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{Quotation,PurchaseOrder,Employee};

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
        return [
            'quotation_id' => Quotation::inRandomOrder()->first()->id,
            'purchase_order_number' => Str::random(10),
            'purchase_order_date' => $this->faker->date(),
            'employee_id' => Employee::inRandomOrder()->first()->id,
        ];
    }
}
