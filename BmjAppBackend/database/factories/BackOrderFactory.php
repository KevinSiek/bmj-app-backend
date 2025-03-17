<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\{BackOrder,PurchaseOrder, Sparepart};

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
            'id_po' => PurchaseOrder::inRandomOrder()->first()->id,
            'no_bo' => Str::random(10),
            'status' => fake()->randomElement([
                'Ready',
                'Not ready',
            ]),
        ];
    }
}
