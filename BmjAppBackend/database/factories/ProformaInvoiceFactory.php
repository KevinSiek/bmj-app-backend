<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{ProformaInvoice, PurchaseOrder, Employee};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProformaInvoice>
 */
class ProformaInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = ProformaInvoice::class;

    public function definition()
    {
        return [
            'purchase_order_id' => PurchaseOrder::inRandomOrder()->first()->id,
            'proforma_invoice_number' => Str::random(10),
            'proforma_invoice_date' => $this->faker->date(),
            'advance_payment' => $this->faker->randomFloat(2, 1000, 5000),
            'grand_total' => $this->faker->randomFloat(2, 1000, 10000),
            'total_amount' => $this->faker->randomFloat(2, 5000, 15000),
            'total_amount_text' => $this->faker->words(3, true),
            'employee_id' => Employee::inRandomOrder()->first()->id,
        ];
    }
}
