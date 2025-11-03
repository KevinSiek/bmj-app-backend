<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{BackOrder, PurchaseOrder, Sparepart};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BackOrder>
 */
class BackOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = BackOrder::class;

    public function definition()
    {
        return [
            'purchase_order_id' => PurchaseOrder::inRandomOrder()->first()->id,
            'back_order_number' => Str::random(10),
            'current_status' => fake()->randomElement([
                'Ready',
                'Not ready',
            ]),
        ];
    }
}
