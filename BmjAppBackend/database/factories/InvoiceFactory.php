<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{ProformaInvoice,Invoice,Employee};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Invoice::class;

    public function definition()
    {
        return [
            'id_pi' => ProformaInvoice::inRandomOrder()->first()->id,
            'invoice_number' => Str::random(10),
            'invoice_date' => $this->faker->date(),
            'term_of_pay' => $this->faker->sentence,
            'employee_id' => Employee::inRandomOrder()->first()->id,
        ];
    }
}
