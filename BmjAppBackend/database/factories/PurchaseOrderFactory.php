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
            'id_quotation' => Quotation::inRandomOrder()->first()->id,
            'po_number' => Str::random(10),
            'po_date' => $this->faker->date(),
            'employee_id' => Employee::inRandomOrder()->first()->id,
        ];
    }
}
